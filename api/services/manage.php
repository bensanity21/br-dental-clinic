<?php
// api/services/manage.php - CRUD for services (super-admin only)
// GET: list every service, including inactive ones
// POST: create | PATCH: update | DELETE: archive, or deactivate if in use
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/activity.php';

requireLogin();
requireRole(['super-admin']);

$method = $_SERVER['REQUEST_METHOD'];

// Shared validation for the fields a service carries.
function readServicePayload(array $data, bool $requireAll): array {
    $errors = [];

    $nameRaw = $data['service_name'] ?? '';
    $descRaw = $data['description'] ?? '';
    $price = $data['price'] ?? null;
    $duration = $data['duration_minutes'] ?? null;
    $active = $data['is_active'] ?? null;

    $nameValid = is_string($nameRaw);
    $descriptionValid = is_string($descRaw);
    $name = $nameValid ? trim($nameRaw) : '';
    $desc = $descriptionValid ? trim($descRaw) : '';

    if ($requireAll || array_key_exists('service_name', $data)) {
        if (!$nameValid || $name === '' || mb_strlen($name) > 100) $errors[] = 'Service name must be text and is required (max 100 characters).';
    }
    if ($requireAll || array_key_exists('description', $data)) {
        if (!$descriptionValid || $desc === '') $errors[] = 'Description must be text and is required.';
    }
    if ($requireAll || array_key_exists('price', $data)) {
        if ((!is_int($price) && !is_float($price) && !is_string($price)) || !is_numeric($price) || (float)$price < 0) $errors[] = 'Price must be zero or a positive number.';
    }
    if ($requireAll || array_key_exists('duration_minutes', $data)) {
        $durationValid = is_int($duration) || (is_string($duration) && ctype_digit($duration));
        $d = $durationValid ? (int)$duration : 0;
        if (!$durationValid || $d < 15 || $d > 480) $errors[] = 'Duration must be an integer between 15 and 480 minutes.';
    }
    if (array_key_exists('is_active', $data)
        && !($active === 0 || $active === 1 || $active === '0' || $active === '1')) {
        $errors[] = 'Status must be active or inactive.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'service_name' => $name,
            'description' => $desc,
            'price' => is_numeric($price) ? round((float)$price, 2) : null,
            'duration_minutes' => (is_int($duration) || (is_string($duration) && ctype_digit($duration))) ? (int)$duration : null,
            'is_active' => $active === null ? 1 : (($active === 0 || $active === '0') ? 0 : 1),
        ],
    ];
}

if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT id, service_name, description, price, duration_minutes, is_active
        FROM services
        ORDER BY is_active DESC, service_name ASC
    ");
    respondSuccess('Services retrieved', $stmt->fetchAll());
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    respondError('Invalid request body.', null, 400);
}

if ($method === 'POST') {
    $parsed = readServicePayload($data, true);
    if ($parsed['errors']) {
        respondError(implode(' ', $parsed['errors']), null, 422);
    }
    $v = $parsed['values'];

    // Duplicate names confuse the booking form.
    $stmt = $pdo->prepare('SELECT id FROM services WHERE service_name = ? LIMIT 1');
    $stmt->execute([$v['service_name']]);
    if ($stmt->fetch()) {
        respondError('A service with that name already exists.', null, 409);
    }

    $stmt = $pdo->prepare('
        INSERT INTO services (service_name, description, price, duration_minutes, is_active)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$v['service_name'], $v['description'], $v['price'], $v['duration_minutes'], $v['is_active']]);
    $newServiceId = (int)$pdo->lastInsertId();
    logActivity($pdo, 'service.create', 'services', $newServiceId,
        "Service \"{$v['service_name']}\" created (₱{$v['price']}, {$v['duration_minutes']} min).");
    respondSuccess('Service created', ['id' => $newServiceId], 201);
}

if ($method === 'PATCH') {
    $id = $data['id'] ?? null;
    if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id < 1) respondError('Service ID must be a positive integer.', null, 400);
    $id = (int)$id;

    $parsed = readServicePayload($data, false);
    if ($parsed['errors']) {
        respondError(implode(' ', $parsed['errors']), null, 422);
    }

    $fields = [];
    $params = [];
    foreach (['service_name', 'description', 'price', 'duration_minutes', 'is_active'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "$f = ?";
            $params[] = $parsed['values'][$f];
        }
    }
    if (!$fields) respondError('Nothing to update.', null, 400);

    $params[] = $id;
    $pdo->prepare('UPDATE services SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    $changedFields = array_map(fn($f) => str_replace(' = ?', '', $f), $fields);
    logActivity($pdo, 'service.update', 'services', (int)$id,
        'Service #' . (int)$id . ' updated (' . implode(', ', $changedFields) . ').',
        ['changed' => $changedFields]);
    respondSuccess('Service updated');
}

if ($method === 'DELETE') {
    // Deleting a service is a soft delete: appointments reference it and the
    // foreign key is RESTRICT, so history must be preserved.
    $id = $data['id'] ?? null;
    if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id < 1) respondError('Service ID must be a positive integer.', null, 400);
    $id = (int)$id;

    $stmt = $pdo->prepare('SELECT id, service_name, description, price, duration_minutes, is_active FROM services WHERE id = ?');
    $stmt->execute([$id]);
    $service = $stmt->fetch();
    if (!$service) respondError('Service not found.', null, 404);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE service_id = ?');
    $stmt->execute([$id]);
    $inUse = (int)$stmt->fetchColumn();

    if ($inUse > 0) {
        // Still referenced by appointments: deactivate instead of archiving,
        // so those appointments keep a valid service row.
        $pdo->prepare('UPDATE services SET is_active = 0 WHERE id = ?')->execute([$id]);
        logActivity($pdo, 'service.deactivate', 'services', (int)$id,
            "Service \"{$service['service_name']}\" set inactive because $inUse appointment(s) use it.",
            ['appointments' => $inUse]);
        respondSuccess('This service is used by ' . $inUse . ' appointment(s), so it was set to inactive instead of being archived.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO archives (original_table, record_id, record_data, deleted_by) VALUES (?, ?, ?, ?)')
            ->execute(['services', $id, json_encode($service, JSON_UNESCAPED_UNICODE), $_SESSION['user_id']]);
        $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Service archive failed: ' . $e->getMessage());
        respondError('Could not archive this service.', null, 500);
    }
    logActivity($pdo, 'service.archive', 'services', (int)$id,
        "Service \"{$service['service_name']}\" archived.",
        ['record' => $service]);
    respondSuccess('Service moved to the archive.');
}

respondError('Invalid request.', null, 405);
