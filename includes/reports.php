<?php
// includes/reports.php - Report definitions shared by the viewer and the CSV export
// ============================================================
// Each report returns the same shape so the browser can render any of them
// without a branch per type:
//   ['title' => string, 'headers' => string[], 'rows' => array[], 'totals' => ?array]
// Keeping the column names here means the PDF/CSV/table output can never drift
// away from the query.

require_once __DIR__ . '/db.php';

const REPORT_TYPES = [
    'appointments'  => 'Appointments Summary by Status',
    'services'      => 'Appointments per Service',
    'dentists'      => 'Appointments per Dentist',
    'patients'      => 'New Patients Registered',
    'cancellations' => 'Cancelled / No-show Appointments',
];

/**
 * Build one report for a date range.
 *
 * @return array{title:string,headers:string[],rows:array[],totals:?array}
 */
function buildReport(PDO $pdo, string $type, string $from, string $to): array {
    if (!array_key_exists($type, REPORT_TYPES)) {
        throw new InvalidArgumentException('Invalid report type.');
    }

    switch ($type) {
        case 'appointments':
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) AS total
                FROM appointments
                WHERE appointment_date BETWEEN ? AND ?
                GROUP BY status
                ORDER BY FIELD(status, 'Pending','Approved','Rescheduled','Completed','Cancelled','No-show')
            ");
            $stmt->execute([$from, $to]);
            return [
                'title'   => REPORT_TYPES[$type],
                'headers' => ['Status', 'Appointments'],
                'rows'    => $stmt->fetchAll(),
                'totals'  => ['label' => 'Total appointments', 'column' => 'total'],
            ];

        case 'services':
            $stmt = $pdo->prepare("
                SELECT s.service_name, COUNT(a.id) AS total
                FROM services s
                LEFT JOIN appointments a
                       ON a.service_id = s.id AND a.appointment_date BETWEEN ? AND ?
                WHERE a.id IS NOT NULL
                GROUP BY s.id, s.service_name
                ORDER BY total DESC, s.service_name ASC
            ");
            $stmt->execute([$from, $to]);
            return [
                'title'   => REPORT_TYPES[$type],
                'headers' => ['Service', 'Appointments'],
                'rows'    => $stmt->fetchAll(),
                'totals'  => ['label' => 'Total appointments', 'column' => 'total'],
            ];

        case 'dentists':
            $stmt = $pdo->prepare("
                SELECT d.full_name, COUNT(a.id) AS total
                FROM dentists d
                LEFT JOIN appointments a
                       ON a.dentist_id = d.id AND a.appointment_date BETWEEN ? AND ?
                WHERE a.id IS NOT NULL
                GROUP BY d.id, d.full_name
                ORDER BY total DESC, d.full_name ASC
            ");
            $stmt->execute([$from, $to]);
            return [
                'title'   => REPORT_TYPES[$type],
                'headers' => ['Dentist', 'Appointments'],
                'rows'    => $stmt->fetchAll(),
                'totals'  => ['label' => 'Total appointments', 'column' => 'total'],
            ];

        case 'patients':
            $stmt = $pdo->prepare("
                SELECT id, full_name, email, created_at
                FROM users
                WHERE role = 'patient' AND DATE(created_at) BETWEEN ? AND ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll();
            return [
                'title'   => REPORT_TYPES[$type],
                'headers' => ['Patient ID', 'Full Name', 'Email', 'Registered'],
                'rows'    => $rows,
                'totals'  => ['label' => 'Total new patients', 'count' => count($rows)],
            ];

        case 'cancellations':
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) AS total
                FROM appointments
                WHERE appointment_date BETWEEN ? AND ? AND status IN ('Cancelled', 'No-show')
                GROUP BY status
                ORDER BY FIELD(status, 'Cancelled','No-show')
            ");
            $stmt->execute([$from, $to]);
            return [
                'title'   => REPORT_TYPES[$type],
                'headers' => ['Status', 'Appointments'],
                'rows'    => $stmt->fetchAll(),
                'totals'  => ['label' => 'Total cancelled / no-show', 'column' => 'total'],
            ];
    }

    throw new InvalidArgumentException('Invalid report type.');
}

/**
 * Human-readable cell value, used by the CSV writer.
 */
function reportCell($value): string {
    if ($value === null) return '';
    return (string)$value;
}
