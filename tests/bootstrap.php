<?php
/**
 * tests/bootstrap.php - shared helpers for the test suite.
 *
 * The suite runs the real API over HTTP against an isolated MySQL database
 * (clinic_test by default) started on its own PHP dev server, so a test run
 * never touches the live `clinic` database.
 *
 * No external dependencies: plain PHP + curl extension.
 */

const TEST_DB = 'clinic_test';
const TEST_PORT = 8099;
const TEST_BASE = 'http://127.0.0.1:8099';

// ---------------------------------------------------------------- environment

function testProjectRoot(): string
{
    return dirname(__DIR__);
}

/** Password hash for the seeded accounts used by the suite. */
function testHash(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]); // low cost: tests only
}

/**
 * Rebuild the isolated test database from clinic.sql.
 * Destructive only to TEST_DB - never to the live database.
 */
function testResetDatabase(): void
{
    $root = testProjectRoot();
    $sql  = file_get_contents($root . '/clinic.sql');
    if ($sql === false) {
        throw new RuntimeException('cannot read clinic.sql');
    }

    // Retarget every schema reference at the test database.
    $sql = str_replace('`clinic`', '`' . TEST_DB . '`', $sql);
    $sql = preg_replace('/^USE `clinic`;/m', 'USE `' . TEST_DB . '`;', $sql);

    $tmp = sys_get_temp_dir() . '/br_test_schema.sql';
    file_put_contents($tmp, $sql);

    testMysql('DROP DATABASE IF EXISTS `' . TEST_DB . '`;');
    testMysql('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
    testMysqlFile($tmp);
    @unlink($tmp);

    // Fast hashes so login tests are quick.
    testMysql(sprintf(
        "USE `%s`; UPDATE users SET password_hash = %s;",
        TEST_DB,
        testPdoFor(TEST_DB)->quote(testHash('TestPass123!'))
    ));

    testSeedTestPatients();
}

/**
 * clinic.sql ships only the three staff accounts, so the suite adds its own
 * patients. Extra accounts are created here rather than in the seed file so the
 * shipped database stays exactly as it was.
 */
function testSeedTestPatients(): void
{
    $pdo = testPdoFor(TEST_DB);
    $accounts = [
        ['Test Patient Jane', 'jane@test.com'],
        ['Test Patient Ben',  'cagurol2benedict1@gmail.com'],
    ];
    $insert = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'patient')"
    );
    foreach ($accounts as [$name, $email]) {
        $exists = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $exists->execute([$email]);
        if (!$exists->fetch()) {
            $insert->execute([$name, $email, testHash('TestPass123!')]);
        }
    }
}

/** The rate limiter is per email+IP; tests share one IP, so clear it often. */
function testClearRateLimits(): void
{
    testMysql("USE `" . TEST_DB . "`; DELETE FROM login_attempts;");
}

/** Run a statement as MySQL root through the CLI (avoids PDO buffering quirks). */
function testMysql(string $sql): void
{
    $cmd = sprintf('"%s" -u root -e %s 2>&1', testMysqlBin(), escapeshellarg($sql));
    exec($cmd, $out, $code);
    if ($code !== 0) {
        throw new RuntimeException('mysql failed: ' . implode("\n", $out));
    }
}

function testMysqlFile(string $path): void
{
    $cmd = sprintf('"%s" -u root < %s 2>&1', testMysqlBin(), escapeshellarg($path));
    exec($cmd, $out, $code);
    if ($code !== 0) {
        throw new RuntimeException('mysql import failed: ' . implode("\n", $out));
    }
}

function testMysqlBin(): string
{
    $candidates = [
        'C:/xampp/mysql/bin/mysql.exe',
        '/c/xampp/mysql/bin/mysql.exe',
        'mysql',
    ];
    foreach ($candidates as $c) {
        if ($c === 'mysql' || file_exists($c)) return $c;
    }
    return 'mysql';
}

function testPdoFor(string $database): PDO
{
    return new PDO(
        'mysql:host=127.0.0.1;dbname=' . $database . ';charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

// ------------------------------------------------------------------- server

/**
 * Make sure a test server is answering on TEST_PORT.
 *
 * Reuses one that is already running (so the suite can be re-run without
 * restarting), otherwise launches a detached PHP dev server pointed at the
 * isolated test database. The server is left running at the end of the suite;
 * tests/stop-server.php shuts it down.
 */
function testServerEnsure(): void
{
    if (testServerUp()) return;

    $root = testProjectRoot();
    $launcher = sys_get_temp_dir() . '/br_test_server_launch.bat';
    file_put_contents($launcher, implode("\r\n", [
        '@echo off',
        'set DB_NAME=' . TEST_DB,
        'set APP_ENV=testing',
        '"C:\xampp\php\php.exe" -S 127.0.0.1:' . TEST_PORT . ' -t "' . str_replace('/', '\\', $root) . '"',
    ]));

    // STYLE: fully detached - the suite must not block on the server.
    exec('cmd /C start "" /B "' . $launcher . '" > NUL 2>&1');

    for ($i = 0; $i < 80; $i++) {
        usleep(250000);
        if (testServerUp()) return;
    }

    throw new RuntimeException(
        'Test server is not answering on port ' . TEST_PORT . ".\n" .
        'Start it manually in another terminal:' . "\n" .
        '  cd "' . $root . '"' . "\n" .
        '  DB_NAME=' . TEST_DB . ' APP_ENV=testing php -S 127.0.0.1:' . TEST_PORT . ' -t .'
    );
}

function testServerUp(): bool
{
    $fp = @fsockopen('127.0.0.1', TEST_PORT, $errno, $errstr, 0.4);
    if (!$fp) return false;
    fclose($fp);
    return true;
}

function testStopServer(): void
{
    // Windows: find the PID listening on TEST_PORT and kill it.
    exec('netstat -ano | findstr :' . TEST_PORT, $out);
    foreach ($out as $line) {
        if (preg_match('/LISTENING\s+(\d+)/', $line, $m)) {
            exec('taskkill /F /PID ' . $m[1] . ' 2>&1');
        }
    }
}

// ------------------------------------------------------------------- client

/** In-memory cookie jar per simulated user. */
final class TestClient
{
    private string $jar;
    public ?string $csrf = null;
    public ?array $user = null;

    public function __construct(public string $label = 'anon')
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'brjar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /**
     * Perform a request. Returns [status, decodedBody, rawBody].
     */
    public function request(string $method, string $path, ?array $body = null, bool $withCsrf = true): array
    {
        $ch = curl_init(TEST_BASE . '/' . ltrim($path, '/'));
        $headers = ['Accept: application/json'];

        if ($body !== null) {
            $payload = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-Type: application/json';
        }
        if ($withCsrf && $this->csrf !== null && strtoupper($method) !== 'GET') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
        ]);

        $raw    = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        return [$status, is_array($decoded) ? $decoded : null, $raw];
    }

    public function get(string $path): array   { return $this->request('GET', $path); }
    public function post(string $path, array $body = []): array { return $this->request('POST', $path, $body); }
    public function patch(string $path, array $body = []): array { return $this->request('PATCH', $path, $body); }
    public function delete(string $path, array $body = []): array { return $this->request('DELETE', $path, $body); }

    /** Log in and remember the CSRF token. Returns the decoded response. */
    public function login(string $email, string $password): array
    {
        [$status, $res] = $this->post('api/login.php', ['email' => $email, 'password' => $password]);
        if (($res['success'] ?? false) === true) {
            $this->csrf = $res['data']['csrf_token'] ?? null;
            $this->user = $res['data'] ?? null;
        }
        return [$status, $res];
    }
}

// ------------------------------------------------------------------- runner

final class TestRunner
{
    public array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private string $suite = '';

    public function suite(string $name): void
    {
        $this->suite = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public function ok(bool $condition, string $description, string $detail = ''): bool
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32mPASS\033[0m " . $description . "\n";
        } else {
            $this->failed++;
            echo "  \033[31mFAIL\033[0m " . $description . ($detail !== '' ? "  \033[33m[" . $detail . "]\033[0m" : '') . "\n";
        }
        $this->results[] = [$condition, $this->suite, $description, $detail];
        return $condition;
    }

    public function eq(mixed $expected, mixed $actual, string $description): bool
    {
        return $this->ok(
            $expected === $actual,
            $description,
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }

    public function summary(): int
    {
        $total = $this->passed + $this->failed;
        echo "\n" . str_repeat('-', 66) . "\n";
        if ($this->failed === 0) {
            echo "\033[32m" . sprintf('ALL PASSED: %d/%d assertions', $this->passed, $total) . "\033[0m\n";
        } else {
            echo "\033[31m" . sprintf('FAILURES: %d failed, %d passed, %d total', $this->failed, $this->passed, $total) . "\033[0m\n";
        }
        echo str_repeat('-', 66) . "\n";

        if ($this->failed > 0) {
            echo "\nFailing tests:\n";
            foreach ($this->results as [$ok, $suite, $desc, $detail]) {
                if (!$ok) echo "  - [$suite] $desc" . ($detail !== '' ? " -> $detail" : '') . "\n";
            }
        }
        return $this->failed === 0 ? 0 : 1;
    }
}
