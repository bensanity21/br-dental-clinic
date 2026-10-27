<?php
// api/services/list.php - List all active services (public)
// GET only
require_once '../../includes/db.php';
require_once '../../includes/response.php';

$stmt = $pdo->query("SELECT id, service_name, description, price FROM services ORDER BY service_name ASC");
$services = $stmt->fetchAll();

respondSuccess('Services retrieved', $services);
