<?php
// api/dentists/list.php - List all dentists (public)
// GET only
require_once '../../includes/db.php';
require_once '../../includes/response.php';

$stmt = $pdo->query("
    SELECT d.id, d.full_name, d.specialization, d.phone_number,
           u.email, u.role, u.created_at
    FROM dentists d
    LEFT JOIN users u ON d.user_id = u.id
    ORDER BY d.full_name ASC
");
$dentists = $stmt->fetchAll();

respondSuccess('Dentists retrieved', $dentists);
