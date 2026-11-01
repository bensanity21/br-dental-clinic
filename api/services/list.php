<?php
// api/services/list.php - List active services (public, no login)
// GET only. Inactive services stay in the database but are hidden from
// patients and from the booking form.
require_once '../../includes/db.php';
require_once '../../includes/response.php';

$stmt = $pdo->query("
    SELECT id, service_name, description, price, duration_minutes
    FROM services
    WHERE is_active = 1
    ORDER BY service_name ASC
");
$services = $stmt->fetchAll();

respondSuccess('Services retrieved', $services);
