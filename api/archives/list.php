<?php
// api/archives/list.php - List all archived records (super-admin only)
// GET: ?table=users|services|appointments|... (optional)
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$tableFilter = $_GET['table'] ?? '';
$query = $_GET['q'] ?? '';
$from = $_GET['date_from'] ?? '';
$to = $_GET['date_to'] ?? '';
$pageRaw = $_GET['page'] ?? '1';
$limitRaw = $_GET['limit'] ?? '25';
if (!is_string($tableFilter) || !is_string($query) || !is_string($from) || !is_string($to)
    || !is_string($pageRaw) || !ctype_digit($pageRaw) || !is_string($limitRaw) || !ctype_digit($limitRaw)) {
    respondError('Archive filters must use valid text and positive numeric pagination values.', null, 400);
}
$query = trim($query);
if (strlen($query) > 100) respondError('Archive search must be 100 characters or fewer.', null, 400);
$page = max(1, (int)$pageRaw);
$limit = min(100, max(1, (int)$limitRaw));
$validDate = static function (string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
};
if (($from !== '' && !$validDate($from)) || ($to !== '' && !$validDate($to)) || ($from !== '' && $to !== '' && $from > $to)) {
    respondError('Archive date filters must be valid dates in YYYY-MM-DD order.', null, 400);
}

$sql = "SELECT id, original_table, record_id, record_data, deleted_by, deleted_at FROM archives WHERE 1=1";
$params = [];
if ($tableFilter) {
    $sql .= ' AND original_table = ?';
    $params[] = $tableFilter;
}
$countSql = str_replace('SELECT id, original_table, record_id, record_data, deleted_by, deleted_at', 'SELECT COUNT(*)', $sql);
$countParams = $params;
if ($from !== '') { $sql .= ' AND deleted_at >= ?'; $countSql .= ' AND deleted_at >= ?'; $params[] = $from . ' 00:00:00'; $countParams[] = $from . ' 00:00:00'; }
if ($to !== '') { $sql .= ' AND deleted_at < DATE_ADD(?, INTERVAL 1 DAY)'; $countSql .= ' AND deleted_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $to . ' 00:00:00'; $countParams[] = $to . ' 00:00:00'; }
if ($query !== '') {
    $sql .= ' AND (original_table LIKE ? OR record_data LIKE ?)';
    $countSql .= ' AND (original_table LIKE ? OR record_data LIKE ?)';
    $needle = '%' . $query . '%';
    array_push($params, $needle, $needle);
    array_push($countParams, $needle, $needle);
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$sql .= ' ORDER BY deleted_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$archives = $stmt->fetchAll();

// Decode JSON record_data for display
foreach ($archives as &$a) {
    $a['record_data'] = json_decode($a['record_data'], true);
}
unset($a);

respondSuccess('Archived records retrieved', $archives, 200, [
    'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => $totalPages],
]);
