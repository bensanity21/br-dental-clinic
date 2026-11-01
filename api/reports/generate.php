<?php
// api/reports/generate.php - Admin reports by date range (JSON)
// GET: ?from=YYYY-MM-DD&to=YYYY-MM-DD&type=appointments|services|dentists|patients|cancellations
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/reports.php';

requireLogin();
requireRole(['super-admin']);

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'appointments';

// Validate the dates before they reach a query.
$fromDate = DateTime::createFromFormat('Y-m-d', $from);
$toDate   = DateTime::createFromFormat('Y-m-d', $to);
if (!$fromDate || $fromDate->format('Y-m-d') !== $from || !$toDate || $toDate->format('Y-m-d') !== $to) {
    respondError('Enter the date range as YYYY-MM-DD.', null, 400);
}
if ($from > $to) {
    respondError('The start date must be on or before the end date.', null, 400);
}

if (!array_key_exists($type, REPORT_TYPES)) {
    respondError('Invalid report type.', null, 400);
}

try {
    $report = buildReport($pdo, $type, $from, $to);
} catch (Throwable $e) {
    error_log('Report failed: ' . $e->getMessage());
    respondError('Could not build this report.', null, 500);
}

respondSuccess(
    $report['title'] . " ($from to $to)",
    [
        'type'    => $type,
        'from'    => $from,
        'to'      => $to,
        'title'   => $report['title'],
        'headers' => $report['headers'],
        'data'    => $report['rows'],
        'totals'  => $report['totals'],
    ]
);
