<?php
// api/archives/list.php - List all archived records (super-admin only)
// GET: ?table=users|services|appointments|... (optional)
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$tableFilter = $_GET['table'] ?? '';

$sql = "SELECT id, original_table, record_id, record_data, deleted_by, deleted_at FROM archives WHERE 1=1";
$params = [];
if ($tableFilter) {
    $sql .= ' AND original_table = ?';
    $params[] = $tableFilter;
}
$sql .= ' ORDER BY deleted_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$archives = $stmt->fetchAll();

// Decode JSON record_data for display
foreach ($archives as &$a) {
    $a['record_data'] = json_decode($a['record_data'], true);
}
unset($a);

respondSuccess('Archived records retrieved', $archives);
