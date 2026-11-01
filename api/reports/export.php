<?php
// api/reports/export.php - Download a report as CSV
// GET: ?from=YYYY-MM-DD&to=YYYY-MM-DD&type=...
// Streams a text/csv attachment so it opens directly in Excel or Sheets.
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/reports.php';

// A download has no JSON error channel, so failures still return JSON with the
// right status code; the browser falls back to its normal error handling.
requireLogin();
requireRole(['super-admin']);

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'appointments';

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
    error_log('CSV export failed: ' . $e->getMessage());
    respondError('Could not build this report.', null, 500);
}

$clinicName = CLINIC_NAME;
try {
    $row = $pdo->query('SELECT clinic_name FROM clinic_settings WHERE id = 1')->fetch();
    if ($row && $row['clinic_name']) $clinicName = $row['clinic_name'];
} catch (Throwable $e) {
    // Fall back to the configured name.
}

// file.csv - safe filename from the report title and the date range.
$slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $report['title']));
$filename = trim($slug, '-') . '_' . $from . '_to_' . $to . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel reads accented names (Dasmariñas) correctly.
fwrite($out, "\xEF\xBB\xBF");

// Header block: who generated this and for which period.
fputcsv($out, [$clinicName]);
fputcsv($out, [$report['title']]);
fputcsv($out, ['Period', $from . ' to ' . $to]);
fputcsv($out, ['Generated', date('Y-m-d H:i')]);
fputcsv($out, []);

// Table
fputcsv($out, $report['headers']);
foreach ($report['rows'] as $row) {
    $cells = [];
    foreach ($row as $value) {
        $cells[] = reportCell($value);
    }
    fputcsv($out, $cells);
}

// Total row, when the report defines one.
if (!empty($report['totals'])) {
    $totals = $report['totals'];
    fputcsv($out, []);
    if (isset($totals['column'])) {
        $sum = 0;
        foreach ($report['rows'] as $row) {
            $sum += (int)($row[$totals['column']] ?? 0);
        }
        fputcsv($out, [$totals['label'], $sum]);
    } elseif (isset($totals['count'])) {
        fputcsv($out, [$totals['label'], $totals['count']]);
    }
}

fclose($out);
exit;
