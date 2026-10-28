<?php
// api/services/manage.php - CRUD for services (admin only)
// GET: list all (incl inactive) | POST: create | PATCH: update | DELETE: remove
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id, service_name, description, price FROM services ORDER BY service_name ASC');
    respondSuccess('Services retrieved', $stmt->fetchAll());
}

$data = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST') {
    $name = trim($data['service_name'] ?? '');
    $desc = trim($data['description'] ?? '');
    $price = $data['price'] ?? null;
    if (!$name || !$desc) {
        respondError('Service name and description are required.', null, 400);
    }
    $stmt = $pdo->prepare('INSERT INTO services (service_name, description, price) VALUES (?, ?, ?)');
    $stmt->execute([$name, $desc, $price]);
    respondSuccess('Service created', ['id' => $pdo->lastInsertId()], 201);
}

if ($method === 'PATCH') {
    $id = $data['id'] ?? null;
    $fields = [];
    $params = [];
    foreach (['service_name', 'description', 'price'] as $f) {
        if (isset($data[$f])) { $fields[] = "$f = ?"; $params[] = $data[$f]; }
    }
    if (!$id || !$fields) { respondError('Nothing to update.', null, 400); }
    $params[] = $id;
    $sql = 'UPDATE services SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($params);
    respondSuccess('Service updated');
}

if ($method === 'DELETE') {
    $id = $data['id'] ?? null;
    if (!$id) { respondError('Service ID required.', null, 400); }
    $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
    respondSuccess('Service deleted');
}

respondError('Invalid request.', null, 405);
