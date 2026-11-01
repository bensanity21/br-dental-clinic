<?php
// api/activity/list.php - Read the audit trail (Super Admin only)
// GET: ?page=1&per_page=25&action=&entity=&user_id=&from=&to=&q=
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if ($perPage < 1 || $perPage > 200) $perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

// Filter by exact action name.
$action = trim((string)($_GET['action'] ?? ''));
if ($action !== '') {
    $where[] = 'a.action = ?';
    $params[] = $action;
}

// Filter by entity table.
$entity = trim((string)($_GET['entity'] ?? ''));
if ($entity !== '') {
    $where[] = 'a.entity = ?';
    $params[] = $entity;
}

// Filter by the person who acted.
$userId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId) {
    $where[] = 'a.user_id = ?';
    $params[] = $userId;
}

// Date range on the timestamp (inclusive of the whole end day).
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
if ($from !== '') {
    $fromDate = DateTime::createFromFormat('Y-m-d', $from);
    if (!$fromDate || $fromDate->format('Y-m-d') !== $from) {
        respondError('Enter the start date as YYYY-MM-DD.', null, 400);
    }
    $where[] = 'a.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $toDate = DateTime::createFromFormat('Y-m-d', $to);
    if (!$toDate || $toDate->format('Y-m-d') !== $to) {
        respondError('Enter the end date as YYYY-MM-DD.', null, 400);
    }
    $where[] = 'a.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}

// Free-text search over the description and actor name.
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $where[] = '(a.description LIKE ? OR a.user_name LIKE ? OR a.action LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs a $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT a.id, a.user_id, a.user_name, a.user_role, a.action, a.entity, a.entity_id,
           a.description, a.details, a.ip_address, a.created_at
    FROM activity_logs a
    $whereSql
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Decode the JSON details so the client does not have to.
foreach ($rows as &$row) {
    if (!empty($row['details'])) {
        $decoded = json_decode($row['details'], true);
        $row['details'] = is_array($decoded) ? $decoded : null;
    } else {
        $row['details'] = null;
    }
}
unset($row);

// Distinct actions and entities, for the filter dropdowns.
$actions = $pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$entities = $pdo->query("SELECT DISTINCT entity FROM activity_logs WHERE entity <> '' ORDER BY entity")->fetchAll(PDO::FETCH_COLUMN);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
respondSuccess('Activity retrieved', [
    'rows' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
    'actions' => $actions,
    'entities' => $entities,
]);
