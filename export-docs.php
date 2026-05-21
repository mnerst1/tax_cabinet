<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$search = trim($_GET['q'] ?? '');

try {
    $sql = "
        SELECT ud.id, dt.name AS type_name, dt.code AS type_code,
               COALESCE(ud.submitted_at, ud.signed_at, ud.created_at) AS display_date,
               ud.status
        FROM user_documents ud
        JOIN document_types dt ON ud.doc_type_id = dt.id
        WHERE ud.user_id = ?
    ";
    $params = [$userId];
    if ($search) {
        $sql .= " AND (dt.name LIKE ? OR dt.code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY ud.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $docs = $stmt->fetchAll();
} catch (Exception $e) {
    die("Export failed: " . $e->getMessage());
}

// Headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=documents_export_' . date('Y-m-d') . '.csv');

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";
// Tell Excel to use semicolon as separator
echo "sep=;\n";

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, ['ID', 'Наименование', 'Код', 'Дата', 'Статус'], ';');

// Data rows
foreach ($docs as $d) {
    fputcsv($output, [
        $d['id'],
        $d['type_name'],
        $d['type_code'],
        !empty($d['display_date']) ? date('d.m.Y H:i', strtotime($d['display_date'])) : '—',
        getStatusLabel($d['status'])
    ], ';');
}

fclose($output);
exit;
