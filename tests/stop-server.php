<?php
/**
 * tests/stop-server.php - shut down the test server started by tests/run.php.
 *
 * Only kills the process listening on the test port, so it can never touch
 * Apache on port 80 or any other service.
 */

require_once __DIR__ . '/bootstrap.php';

if (!testServerUp()) {
    echo "No test server on port " . TEST_PORT . " - nothing to do.\n";
    exit(0);
}

exec('netstat -ano | findstr :' . TEST_PORT, $lines);
$pids = [];
foreach ($lines as $line) {
    if (stripos($line, 'LISTENING') !== false && preg_match('/(\d+)\s*$/', $line, $m)) {
        $pids[$m[1]] = true;
    }
}

if (!$pids) {
    echo "Port " . TEST_PORT . " is busy but no PID was identified.\n";
    exit(1);
}

foreach (array_keys($pids) as $pid) {
    exec('taskkill /F /PID ' . (int)$pid . ' 2>&1', $out, $code);
    echo "Stopped test server (pid $pid)\n";
}

echo testServerUp() ? "Still answering - check manually.\n" : "Port " . TEST_PORT . " is free.\n";
