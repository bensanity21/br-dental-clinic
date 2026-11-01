<?php
// api/services/manage.php - CRUD for services (super-admin only)
// GET: list every service, including inactive ones
// POST: create | PATCH: update | DELETE: archive, or deactivate if in use
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$method = $_SERVER['REQUEST_METHOD'];

// Shared validation for the fields a service carries.
function readServicePayload(array $data, bool $requireAll): array {
    $errors = [];

    $name = trim($data['service_name'] ?? '');
    $desc = trim($data['description'] ?? '');
    $price = $data['price'] ?? null;
    $duration = $data['duration_minutes'] ?? null;
    $active = $data['is_active'] ?? null;

    if ($requireAll || array_key_exists('service_name', $data)) {
        if ($name === '' || mb_strlen($name) > 100) $errors[] = 'Service name is required (max 100 characters).';
    }
    if ($requireAll || array_key_exists('description', $data)) {
        if ($desc === '') $errors[] = 'Description is required.';
    }
    if ($requireAll || array_key_exists('price', $data)) {
        if (!is_numeric($price) || (float)$price < 0) $errors[] = 'Price must be zero or a positive number.';
    }
    if ($requireAll || array_key_exists('duration_minutes', $data)) {
        $d = (int)$duration;
        if ($d < 15 || $d > 480) $errors[] = 'Duration must be between 15 and 480 minutes.';
    }
    if ($active !== null && !in_array((int)$active, [0, 1], true)) {
        $errors[] = 'Status must be active or inactive.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'service_name' => $name,
            'description' => $desc,
            'price' => is_numeric($price) ? round((float)$price, 2) : null,
            'duration_minutes' => (int)$duration,
            'is_active' => $active === null ? 1 : (int)$active,
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
    respondSuccess('Service created', ['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PATCH') {
    $id = $data['id'] ?? null;
    if (!$id) respondError('Service ID required.', null, 400);

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
    respondSuccess('Service updated');
}

if ($method === 'DELETE') {
    // Deleting a service is a soft delete: appointments reference it and the
    // foreign key is RESTRICT, so history must be preserved.
    $id = $data['id'] ?? null;
    if (!$id) respondError('Service ID required.', null, 400);

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
    respondSuccess('Service moved to the archive.');
}

respondError('Invalid request.', null, 405);
