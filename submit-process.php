<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$docTypeId = (int)($_POST['doc_type_id'] ?? 0);
$year      = (int)($_POST['year'] ?? date('Y') - 1);
$quarter   = (int)($_POST['quarter'] ?? 0);
$halfYear  = (int)($_POST['half_year'] ?? 0);
$action    = $_POST['action'] ?? 'submit';

if (!$docTypeId) {
    header('Location: ' . SITE_URL . '/submit.php');
    exit;
}

// report_period: 'Q1','Q2','Q3','Q4' | 'H1','H2' | NULL
$reportPeriod = null;
if ($quarter  > 0) $reportPeriod = 'Q' . $quarter;
if ($halfYear > 0) $reportPeriod = 'H' . $halfYear;

// Маппинг doc_type_id → файл формы
$formRoutes = [
    1  => 'forms/form-240.php',
    2  => 'forms/form-250.php',
    3  => 'forms/form-270.php',
    4  => 'forms/form-400.php',
    10 => 'forms/form-200.php',
    7  => 'forms/form-700.php',
    8  => 'forms/form-910.php',
];

try {
    $stmt = $db->prepare("
        INSERT INTO user_documents
            (user_id, doc_type_id, report_year, report_period, status, created_at)
        VALUES (?, ?, ?, ?, 'draft', NOW())
    ");
    $stmt->execute([$userId, $docTypeId, $year, $reportPeriod]);
    $newId = (int)$db->lastInsertId();

    if (isset($formRoutes[$docTypeId])) {
        header('Location: ' . SITE_URL . '/' . $formRoutes[$docTypeId] . '?doc_id=' . $newId);
        exit;
    }

    $db->prepare("UPDATE user_documents SET status='submitted', submitted_at=NOW() WHERE id=?")
       ->execute([$newId]);

    header('Location: ' . SITE_URL . '/documents.php?submitted=1');
    exit;

} catch (Exception $e) {
    header('Location: ' . SITE_URL . '/submit.php?error=1');
    exit;
}
