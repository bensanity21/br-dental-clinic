<?php
// Get or update the signed-in patient's portal language preference.
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireRole(['patient']);
$user = currentUser();
$supportedLanguages = ['en', 'fil'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare('SELECT language_preference FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $language = $stmt->fetchColumn();
        if (!in_array($language, $supportedLanguages, true)) {
            $language = 'en';
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        respondSuccess('Language preference retrieved', ['language_preference' => $language]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
        header('Allow: GET, PATCH');
        respondError('Method not allowed.', null, 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $language = is_array($data) ? ($data['language_preference'] ?? null) : null;
    if (!is_string($language) || !in_array($language, $supportedLanguages, true)) {
        respondError('Choose a valid language.', null, 400);
    }

    $stmt = $pdo->prepare('UPDATE users SET language_preference = ? WHERE id = ?');
    $stmt->execute([$language, $user['id']]);
    respondSuccess('Language preference saved', ['language_preference' => $language]);
} catch (Throwable $e) {
    error_log('Patient language preference request failed: ' . $e->getMessage());
    respondError('We could not save your language preference. Please try again.', null, 500);
}
