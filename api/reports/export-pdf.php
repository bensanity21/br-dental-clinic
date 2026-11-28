<?php
// Server-generated PDF version of the same admin report data as CSV.
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/reports.php';
require_once '../../includes/simple_pdf.php';

requireLogin();
requireRole(['super-admin']);
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'appointments';
if (!is_string($from) || !is_string($to) || !is_string($type)) {
    respondError('Report filters must be text values.', null, 400);
}
$fromDate = DateTime::createFromFormat('!Y-m-d', $from);
$toDate = DateTime::createFromFormat('!Y-m-d', $to);
if (!$fromDate || $fromDate->format('Y-m-d') !== $from || !$toDate || $toDate->format('Y-m-d') !== $to || $from > $to) {
    respondError('Enter a valid date range in YYYY-MM-DD order.', null, 400);
}
if (!array_key_exists($type, REPORT_TYPES)) respondError('Invalid report type.', null, 400);
try {
    $report = buildReport($pdo, $type, $from, $to);
    $clinicName = CLINIC_NAME;
    $clinic = $pdo->query('SELECT clinic_name FROM clinic_settings WHERE id = 1')->fetch();
    if ($clinic && $clinic['clinic_name']) $clinicName = $clinic['clinic_name'];
    $lines = [$clinicName, $report['title'], 'Period: ' . $from . ' to ' . $to, 'Generated: ' . date('Y-m-d H:i'), ''];
    $lines[] = implode(' | ', $report['headers']);
    $lines[] = str_repeat('-', 90);
    foreach ($report['rows'] as $row) {
        $cells = [];
        foreach ($report['headers'] as $header) {
            $key = match ($header) {
                'Appointments' => 'total', 'Service' => 'service_name', 'Dentist' => 'full_name',
                'Patient ID' => 'id', 'Full Name' => 'full_name', 'Registered' => 'created_at',
                default => strtolower(str_replace(' ', '_', $header)),
            };
            $cells[] = trim((string)($row[$key] ?? ''));
        }
        $line = implode(' | ', $cells);
        foreach (explode("\n", wordwrap($line, 95, "\n", true)) as $wrapped) $lines[] = $wrapped;
    }
    if (!empty($report['totals'])) {
        $total = $report['totals'];
        $value = isset($total['column'])
            ? array_sum(array_map(static fn($row) => (int)($row[$total['column']] ?? 0), $report['rows']))
            : ($total['count'] ?? '');
        $lines[] = '';
        $lines[] = ($total['label'] ?? 'Total') . ': ' . $value;
    }
    $pdf = buildSimplePdf($lines);
} catch (Throwable $e) {
    error_log('PDF report export failed: ' . $e->getMessage());
    respondError('Could not build this report.', null, 500);
}
$slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $report['title']), '-'));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $slug . '_' . $from . '_to_' . $to . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $pdf;
exit;
