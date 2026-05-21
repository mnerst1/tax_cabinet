<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

// Уведомления
$unreadCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $s->execute([$userId]);
    $unreadCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

// Все поданные документы пользователя
try {
    $stmt = $db->prepare("
        SELECT ud.id, ud.report_year, ud.report_period, ud.status,
               ud.submitted_at, ud.created_at, ud.form_data,
               dt.code AS type_code, dt.name AS type_name
        FROM user_documents ud
        JOIN document_types dt ON ud.doc_type_id = dt.id
        WHERE ud.user_id = ?
        ORDER BY ud.created_at DESC
    ");
    $stmt->execute([$userId]);
    $documents = $stmt->fetchAll();
} catch (Exception $e) { $documents = []; }

// Начисления / платежи
try {
    $stmt = $db->prepare("
        SELECT tc.*, dt.code AS type_code
        FROM tax_charges tc
        LEFT JOIN document_types dt ON tc.doc_type_id = dt.id
        WHERE tc.user_id = ?
        ORDER BY tc.charge_date DESC
        LIMIT 100
    ");
    $stmt->execute([$userId]);
    $charges = $stmt->fetchAll();
} catch (Exception $e) { $charges = []; }

// История платежей (оплаченные)
$historyCharges = array_filter($charges, fn($c) => (float)($c['paid_amount'] ?? 0) > 0);

// Задолженности (есть остаток)
$debtCharges = array_filter($charges, fn($c) => max(0, (float)$c['amount'] - (float)($c['paid_amount'] ?? 0)) > 0);

// Переплата (paid > amount)
$overpayCharges = array_filter($charges, fn($c) => (float)($c['paid_amount'] ?? 0) > (float)$c['amount']);

// Статистика
$totalDocs      = count($documents);
$submittedDocs  = count(array_filter($documents, fn($d) => in_array($d['status'], ['submitted','accepted','in_review'])));
$draftDocs      = count(array_filter($documents, fn($d) => $d['status'] === 'draft'));
$acceptedDocs   = count(array_filter($documents, fn($d) => $d['status'] === 'accepted'));

$totalCharged    = array_sum(array_column($charges, 'amount'));
$totalPaid       = array_sum(array_map(fn($c) => $c['paid_amount'] ?? 0, $charges));
$totalDebt       = max(0, $totalCharged - $totalPaid);
$totalOverpay    = max(0, $totalPaid - $totalCharged);

// Статусы
$statusLabels = [
    'draft'     => ['label' => __t('status_draft'),    'class' => 'status-draft'],
    'submitted' => ['label' => __t('status_submitted'), 'class' => 'status-submitted'],
    'in_review' => ['label' => __t('status_review'),    'class' => 'status-review'],
    'accepted'  => ['label' => __t('status_accepted'),  'class' => 'status-accepted'],
    'rejected'  => ['label' => __t('status_rejected'),  'class' => 'status-rejected'],
];

$periodNames = [
    'Q1' => __t('form_period_q1'), 'Q2' => __t('form_period_q2'), 'Q3' => __t('form_period_q3'), 'Q4' => __t('form_period_q4'),
    'H1' => __t('form_period_h1'), 'H2' => __t('form_period_h2'),
];

// Активная вкладка — принимаем все возможные значения
$validTabs = ['overview','documents','charges','history','debts','overpayment','profile'];
$tab = in_array($_GET['tab'] ?? '', $validTabs) ? $_GET['tab'] : 'overview';
$flashDeleted = !empty($_GET['deleted']);
$flashError   = $_GET['error'] ?? '';
$pageTitle = __t('nav_account') . ' — ' . SITE_NAME;
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
/* ── Tabs ─────────────────────────────────────────────── */
.account-tabs{display:flex;gap:0;border-bottom:2px solid #e2e6ea;margin-bottom:28px;overflow-x:auto}
.account-tab{display:flex;align-items:center;gap:7px;padding:12px 20px;font-size:13px;font-weight:600;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer;white-space:nowrap;text-decoration:none;transition:color .15s,border-color .15s}
.account-tab:hover{color:#1a3c6e}
.account-tab.active{color:#1a3c6e;border-bottom-color:#1a3c6e}
.account-tab svg{width:16px;height:16px}

/* ── KPI Cards ────────────────────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.kpi-card{background:#fff;border:1px solid #e2e6ea;border-radius:10px;padding:18px 20px;display:flex;flex-direction:column;gap:6px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.kpi-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.kpi-value{font-size:24px;font-weight:700;color:#1a1a2e;line-height:1}
.kpi-sub{font-size:12px;color:#9ca3af}
.kpi-card.accent{background:#1a3c6e;border-color:#1a3c6e}
.kpi-card.accent .kpi-label{color:rgba(255,255,255,.6)}
.kpi-card.accent .kpi-value{color:#fff}
.kpi-card.accent .kpi-sub{color:rgba(255,255,255,.5)}
.kpi-card.danger{background:#fff7f7;border-color:#fca5a5}
.kpi-card.danger .kpi-value{color:#dc2626}
.kpi-card.success{background:#f0fdf4;border-color:#bbf7d0}
.kpi-card.success .kpi-value{color:#059669}

/* ── Profile Card ─────────────────────────────────────── */
.profile-card{background:#fff;border:1px solid #e2e6ea;border-radius:12px;overflow:hidden;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.profile-header{background:linear-gradient(135deg,#1a3c6e 0%,#1a5fa8 100%);padding:28px 28px 20px;color:#fff;display:flex;align-items:center;gap:18px}
.profile-avatar{width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;color:#fff;flex-shrink:0;border:2px solid rgba(255,255,255,.3)}
.profile-name{font-size:20px;font-weight:700}
.profile-role{font-size:13px;opacity:.75;margin-top:3px}
.profile-body{display:grid;grid-template-columns:repeat(3,1fr);gap:0}
.profile-field{padding:16px 24px;border-right:1px solid #f0f2f5;border-bottom:1px solid #f0f2f5}
.profile-field:nth-child(3n){border-right:none}
.profile-field:nth-last-child(-n+3){border-bottom:none}
.profile-field-label{font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.profile-field-value{font-size:14px;font-weight:600;color:#1a1a2e}

/* ── Section Card ─────────────────────────────────────── */
.section-card{background:#fff;border:1px solid #e2e6ea;border-radius:10px;overflow:hidden;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.section-card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #f0f2f5}
.section-card-title{font-size:14px;font-weight:700;color:#1a1a2e;display:flex;align-items:center;gap:8px}
.section-card-title svg{width:16px;height:16px;color:#1a5fa8}

/* ── Table ────────────────────────────────────────────── */
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table th{text-align:left;padding:11px 20px;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;background:#f9fafb;border-bottom:1px solid #f0f2f5}
.data-table td{padding:13px 20px;border-bottom:1px solid #f7f8fa;vertical-align:middle}
.data-table tr:last-child td{border-bottom:none}
.data-table tr:hover td{background:#fafbfc}
.doc-code{font-weight:700;color:#1a3c6e;font-size:12px;background:#e8f0fb;padding:3px 8px;border-radius:5px}
.period-tag{font-size:11px;color:#6b7280;background:#f3f4f6;padding:2px 8px;border-radius:4px;font-weight:500}

/* ── Status badges ────────────────────────────────────── */
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.status-draft    {background:#f3f4f6;color:#6b7280}
.status-submitted{background:#dbeafe;color:#1d4ed8}
.status-review   {background:#fef3c7;color:#92400e}
.status-accepted {background:#d1fae5;color:#065f46}
.status-rejected {background:#fee2e2;color:#991b1b}
.status-dot{width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}

/* ── Amounts ──────────────────────────────────────────── */
.charge-row-paid td{opacity:.65}
.amount-cell{font-weight:700;font-family:monospace;font-size:13px}
.amount-charged{color:#1a1a2e}
.amount-paid{color:#059669}
.amount-debt{color:#dc2626}
.amount-over{color:#7c3aed}
.progress-bar-wrap{width:120px;height:5px;background:#f3f4f6;border-radius:3px;overflow:hidden}
.progress-bar-fill{height:100%;border-radius:3px;background:#059669;transition:width .4s}

/* ── Empty ────────────────────────────────────────────── */
.empty-state{padding:48px 20px;text-align:center;color:#9ca3af}
.empty-icon{font-size:40px;margin-bottom:12px}
.empty-text{font-size:14px}

/* ── Buttons ──────────────────────────────────────────── */
.btn-sm{padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px;border:1px solid transparent;transition:background .15s}
.btn-sm-primary{background:#1a3c6e;color:#fff;border-color:#1a3c6e}
.btn-sm-primary:hover{background:#122d54}
.btn-sm-outline{background:#fff;color:#1a3c6e;border-color:#1a3c6e}
.btn-sm-outline:hover{background:#f0f4ff}
.btn-sm-danger{background:#fff;color:#dc2626;border-color:#fca5a5}
.btn-sm-danger:hover{background:#fff7f7}
.btn-sm-success{background:#059669;color:#fff;border-color:#059669}
.btn-sm-success:hover{background:#047857}

/* ── Info banner ──────────────────────────────────────── */
.info-banner{display:flex;align-items:flex-start;gap:14px;padding:16px 20px;border-radius:10px;margin-bottom:20px;font-size:13px;line-height:1.6}
.info-banner svg{flex-shrink:0;margin-top:1px;width:18px;height:18px}
.info-banner-blue{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.info-banner-red{background:#fff7f7;border:1px solid #fca5a5;color:#991b1b}
.info-banner-green{background:#f0fdf4;border:1px solid #bbf7d0;color:#065f46}
.info-banner-purple{background:#faf5ff;border:1px solid #e9d5ff;color:#6b21a8}

/* ── Timeline ─────────────────────────────────────────── */
.timeline{padding:0 20px 20px}
.timeline-item{display:flex;gap:14px;padding:14px 0;border-bottom:1px solid #f0f2f5}
.timeline-item:last-child{border-bottom:none}
.timeline-dot{width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;margin-top:2px}
.timeline-dot-blue{background:#dbeafe}
.timeline-dot-green{background:#d1fae5}
.timeline-dot-yellow{background:#fef3c7}
.timeline-dot-red{background:#fee2e2}
.timeline-dot-gray{background:#f3f4f6}
.timeline-content{flex:1}
.timeline-title{font-size:13px;font-weight:600;color:#1a1a2e}
.timeline-sub{font-size:12px;color:#9ca3af;margin-top:2px}

/* ── Overpay card ─────────────────────────────────────── */
.overpay-summary{background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);border-radius:12px;padding:24px;color:#fff;margin-bottom:20px}
.overpay-amount{font-size:36px;font-weight:800;margin:8px 0 4px}
.overpay-label{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;opacity:.7}
.overpay-note{font-size:12px;opacity:.6;margin-top:8px}

/* ── Responsive ───────────────────────────────────────── */
@media(max-width:900px){.kpi-grid{grid-template-columns:1fr 1fr}}
@media(max-width:768px){
  .profile-body{grid-template-columns:1fr 1fr}
  .profile-field:nth-child(2n){border-right:none}
  .data-table th:nth-child(n+5),.data-table td:nth-child(n+5){display:none}
}
@media(max-width:480px){
  .kpi-grid{grid-template-columns:1fr}
  .profile-body{grid-template-columns:1fr}
  .profile-field{border-right:none}
}
</style>

<div class="page-wrapper">
  <div class="container">
<?php if ($flashDeleted): ?>
<div style="display:flex;align-items:center;gap:10px;background:#d1fae5;border:1px solid #a7f3d0;
            color:#065f46;border-radius:8px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:500;">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <polyline points="20 6 9 17 4 12"/>
  </svg>
  <?= __t('acc_msg_draft_deleted') ?>
</div>
<?php endif; ?>

<?php if ($flashError === 'not_draft'): ?>
<div style="display:flex;align-items:center;gap:10px;background:#fee2e2;border:1px solid #fca5a5;
            color:#991b1b;border-radius:8px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:500;">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
  </svg>
  <?= __t('acc_msg_only_drafts') ?>
</div>
<?php endif; ?>

<?php if ($flashError === 'not_found'): ?>
<div style="display:flex;align-items:center;gap:10px;background:#fee2e2;border:1px solid #fca5a5;
            color:#991b1b;border-radius:8px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:500;">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
  </svg>
  <?= __t('acc_msg_not_found') ?>
</div>
<?php endif; ?>
    <div class="page-heading">
      <h1><?= __t('nav_account') ?></h1>
      <p><?= __t('acc_page_desc') ?></p>
    </div>

    <!-- ВКЛАДКИ -->
    <div class="account-tabs">
      <a href="?tab=overview" class="account-tab <?= $tab==='overview' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <?= __t('acc_overview') ?>
      </a>
      <a href="?tab=documents" class="account-tab <?= $tab==='documents' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <?= __t('acc_documents') ?>
        <?php if ($draftDocs > 0): ?>
          <span style="background:#fbbf24;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px"><?= $draftDocs ?></span>
        <?php endif; ?>
      </a>
      <a href="?tab=history" class="account-tab <?= $tab==='history' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <?= __t('acc_history') ?>
      </a>
      <a href="?tab=debts" class="account-tab <?= $tab==='debts' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= __t('acc_debts') ?>
        <?php if (count($debtCharges) > 0): ?>
          <span style="background:#ef4444;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px"><?= count($debtCharges) ?></span>
        <?php endif; ?>
      </a>
      <a href="?tab=overpayment" class="account-tab <?= $tab==='overpayment' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 11 21 7 17 3"/><line x1="21" y1="7" x2="9" y2="7"/><polyline points="7 21 3 17 7 13"/><line x1="15" y1="17" x2="3" y2="17"/></svg>
        <?= __t('acc_overpayment') ?>
        <?php if ($totalOverpay > 0): ?>
          <span style="background:#7c3aed;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px">!</span>
        <?php endif; ?>
      </a>
      <a href="?tab=charges" class="account-tab <?= $tab==='charges' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        <?= __t('acc_charges') ?>
      </a>
      <a href="?tab=profile" class="account-tab <?= $tab==='profile' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?= __t('acc_profile') ?>
      </a>
    </div>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ВКЛАДКА: ОБЗОР                             -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($tab === 'overview'): ?>

      <div class="kpi-grid">
        <div class="kpi-card accent">
          <div class="kpi-label"><?= __t('acc_total_docs') ?></div>
          <div class="kpi-value"><?= $totalDocs ?></div>
          <div class="kpi-sub"><?= $draftDocs ?> <?= __t('filter_drafts') ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label"><?= __t('status_accepted') ?></div>
          <div class="kpi-value" style="color:#059669"><?= $acceptedDocs ?></div>
          <div class="kpi-sub"><?= __t('acc_of') ?> <?= $submittedDocs ?> <?= __t('filter_submitted') ?></div>
        </div>
        <div class="kpi-card <?= $totalDebt > 0 ? 'danger' : '' ?>">
          <div class="kpi-label"><?= __t('acc_debts') ?></div>
          <div class="kpi-value"><?= number_format($totalDebt,0,'.', ' ') ?></div>
          <div class="kpi-sub"><?= __t('acc_tenge') ?></div>
        </div>
        <div class="kpi-card <?= $totalOverpay > 0 ? 'success' : '' ?>">
          <div class="kpi-label"><?= __t('acc_overpayment') ?></div>
          <div class="kpi-value"><?= number_format($totalOverpay,0,'.', ' ') ?></div>
          <div class="kpi-sub"><?= __t('acc_tenge') ?></div>
        </div>
      </div>

      <!-- Последние документы -->
      <div class="section-card">
        <div class="section-card-header">
          <div class="section-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <?= __t('acc_recent_docs') ?>
          </div>
          <a href="?tab=documents" class="btn-sm btn-sm-outline"><?= __t('acc_btn_all_docs') ?></a>
        </div>
        <?php $recent = array_slice($documents, 0, 5); ?>
        <?php if ($recent): ?>
        <table class="data-table">
          <thead>
            <tr>
              <th><?= __t('acc_table_form') ?></th><th><?= __t('acc_table_period') ?></th><th><?= __t('acc_table_status') ?></th><th><?= __t('acc_table_date_submit') ?></th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $doc):
              $st  = $statusLabels[$doc['status']] ?? ['label'=>$doc['status'],'class'=>'status-draft'];
              $per = $periodNames[$doc['report_period'] ?? ''] ?? ($doc['report_period'] ?? '—');
            ?>
            <tr>
              <td data-label="<?= __t('acc_table_form') ?>">
                <span class="doc-code"><?= htmlspecialchars($doc['type_code']) ?></span>
                <div style="font-size:11px;color:#9ca3af;margin-top:3px"><?= htmlspecialchars(mb_strimwidth(__t('doc_type_' . str_replace('.', '_', $doc['type_code'])),0,40,'…')) ?></div>
              </td>
              <td data-label="<?= __t('acc_table_period') ?>">
                <span class="period-tag"><?= htmlspecialchars($per) ?></span>
                <span style="font-size:12px;color:#6b7280;margin-left:4px"><?= (int)$doc['report_year'] ?></span>
              </td>
              <td data-label="<?= __t('acc_table_status') ?>"><span class="status-badge <?= $st['class'] ?>"><span class="status-dot"></span><?= $st['label'] ?></span></td>
              <td data-label="<?= __t('acc_table_date_submit') ?>" style="font-size:12px;color:#6b7280"><?= $doc['submitted_at'] ? date('d.m.Y', strtotime($doc['submitted_at'])) : '—' ?></td>
              <td data-label="">
                <?php if ($doc['status'] === 'draft'): ?>
                  <a href="<?= SITE_URL ?>/forms/form-<?= (int)$doc['type_code'] ?>.php?doc_id=<?= $doc['id'] ?>" class="btn-sm btn-sm-primary"><?= __t('acc_btn_continue') ?></a>
                <?php else: ?>
                  <a href="<?= SITE_URL ?>/document-view.php?id=<?= $doc['id'] ?>" class="btn-sm btn-sm-outline"><?= __t('acc_btn_view') ?></a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">📄</div>
          <div class="empty-text"><?= __t('acc_empty_docs') ?> <a href="<?= SITE_URL ?>/submit.php" style="color:#1a5fa8"><?= __t('acc_submit_first') ?></a></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Таймлайн -->
      <div class="section-card">
        <div class="section-card-header">
          <div class="section-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?= __t('acc_last_activity') ?>
          </div>
        </div>
        <div class="timeline">
          <?php
          $activities = [];
          foreach (array_slice($documents, 0, 8) as $d) {
              $activities[] = [
                  'date'  => $d['submitted_at'] ?: $d['created_at'],
                  'icon'  => match($d['status']) {
                      'accepted'  => ['dot'=>'timeline-dot-green','emoji'=>'✅'],
                      'rejected'  => ['dot'=>'timeline-dot-red',  'emoji'=>'❌'],
                      'submitted','in_review' => ['dot'=>'timeline-dot-blue','emoji'=>'📤'],
                      default     => ['dot'=>'timeline-dot-gray', 'emoji'=>'📝'],
                  },
                  'title' => ($d['status']==='draft' ? __t('acc_activity_draft') . ' ' : __t('acc_activity_submitted') . ' ') . $d['type_code'],
                  'sub'   => ($periodNames[$d['report_period']??''] ?? $d['report_period'] ?? '') . ' ' . $d['report_year'] . ' ' . __t('form_year_suffix'),
              ];
          }
          usort($activities, fn($a,$b) => strtotime($b['date']) - strtotime($a['date']));
          ?>
          <?php if ($activities): ?>
            <?php foreach ($activities as $act): ?>
            <div class="timeline-item">
              <div class="timeline-dot <?= $act['icon']['dot'] ?>"><?= $act['icon']['emoji'] ?></div>
              <div class="timeline-content">
                <div class="timeline-title"><?= htmlspecialchars($act['title']) ?></div>
                <div class="timeline-sub"><?= htmlspecialchars($act['sub']) ?> · <?= date('d.m.Y H:i', strtotime($act['date'])) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="color:#9ca3af;font-size:13px;padding:16px 0"><?= __t('acc_no_activity') ?></p>
          <?php endif; ?>
        </div>
      </div>

    <?php endif; ?>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ВКЛАДКА: ДОКУМЕНТЫ                         -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($tab === 'documents'): ?>

      <form method="GET" style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
        <input type="hidden" name="tab" value="documents">
        <select name="filter_status" style="padding:8px 12px;border:1px solid #e2e6ea;border-radius:7px;font-size:13px;outline:none;background:#fff">
          <option value=""><?= __t('acc_filter_all_statuses') ?></option>
          <option value="draft"     <?= ($_GET['filter_status']??'')==='draft'     ?'selected':'' ?>><?= __t('status_draft') ?></option>
          <option value="submitted" <?= ($_GET['filter_status']??'')==='submitted' ?'selected':'' ?>><?= __t('status_submitted') ?></option>
          <option value="accepted"  <?= ($_GET['filter_status']??'')==='accepted'  ?'selected':'' ?>><?= __t('status_accepted') ?></option>
          <option value="rejected"  <?= ($_GET['filter_status']??'')==='rejected'  ?'selected':'' ?>><?= __t('status_rejected') ?></option>
        </select>
        <select name="filter_year" style="padding:8px 12px;border:1px solid #e2e6ea;border-radius:7px;font-size:13px;outline:none;background:#fff">
          <option value=""><?= __t('acc_filter_all_years') ?></option>
          <?php for ($y = date('Y'); $y >= 2018; $y--): ?>
          <option value="<?= $y ?>" <?= ($_GET['filter_year']??'')==$y?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
        <button type="submit" style="padding:8px 18px;background:#1a3c6e;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer"><?= __t('acc_btn_filter') ?></button>
        <a href="?tab=documents" style="padding:8px 18px;background:#fff;color:#6b7280;border:1px solid #e2e6ea;border-radius:7px;font-size:13px;text-decoration:none"><?= __t('acc_btn_reset') ?></a>
        <a href="<?= SITE_URL ?>/submit.php" class="btn-sm btn-sm-primary" style="margin-left:auto;padding:8px 18px"><?= __t('acc_btn_submit_new') ?></a>
      </form>

      <?php
      $filteredDocs = $documents;
      if (!empty($_GET['filter_status'])) $filteredDocs = array_filter($filteredDocs, fn($d) => $d['status'] === $_GET['filter_status']);
      if (!empty($_GET['filter_year']))   $filteredDocs = array_filter($filteredDocs, fn($d) => (int)$d['report_year'] === (int)$_GET['filter_year']);
      ?>

      <div class="section-card">
        <div class="section-card-header">
          <div class="section-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <?= __t('acc_btn_all_docs') ?>
          </div>
          <span style="font-size:12px;color:#9ca3af"><?= count($filteredDocs) ?> <?= __t('acc_records') ?></span>
        </div>
        <?php if ($filteredDocs): ?>
        <table class="data-table">
          <thead>
            <tr><th><?= __t('acc_table_id') ?></th><th><?= __t('acc_table_form') ?></th><th><?= __t('acc_table_name') ?></th><th><?= __t('acc_table_period') ?></th><th><?= __t('acc_table_year') ?></th><th><?= __t('acc_table_status') ?></th><th><?= __t('acc_table_date_submit') ?></th><th><?= __t('acc_table_action') ?></th></tr>
          </thead>
          <tbody>
            <?php foreach ($filteredDocs as $doc):
              $st  = $statusLabels[$doc['status']] ?? ['label'=>$doc['status'],'class'=>'status-draft'];
              $per = $periodNames[$doc['report_period'] ?? ''] ?? ($doc['report_period'] ?? '—');
            ?>
            <tr>
              <td data-label="<?= __t('acc_table_id') ?>" style="color:#9ca3af;font-size:12px"><?= $doc['id'] ?></td>
              <td data-label="<?= __t('acc_table_form') ?>"><span class="doc-code"><?= htmlspecialchars($doc['type_code']) ?></span></td>
              <td data-label="<?= __t('acc_table_name') ?>" style="font-size:12px;color:#374151;max-width:200px"><?= htmlspecialchars(mb_strimwidth(__t('doc_type_' . str_replace('.', '_', $doc['type_code'])),0,50,'…')) ?></td>
              <td data-label="<?= __t('acc_table_period') ?>"><span class="period-tag"><?= htmlspecialchars($per) ?></span></td>
              <td data-label="<?= __t('acc_table_year') ?>" style="font-size:13px;color:#374151;font-weight:600"><?= (int)$doc['report_year'] ?></td>
              <td data-label="<?= __t('acc_table_status') ?>"><span class="status-badge <?= $st['class'] ?>"><span class="status-dot"></span><?= $st['label'] ?></span></td>
              <td data-label="<?= __t('acc_table_date_submit') ?>" style="font-size:12px;color:#6b7280"><?= $doc['submitted_at'] ? date('d.m.Y', strtotime($doc['submitted_at'])) : '—' ?></td>
              <td data-label="">
                <div style="display:flex;gap:6px">
                <?php if ($doc['status'] === 'draft'): ?>
                  <a href="<?= SITE_URL ?>/forms/form-<?= (int)$doc['type_code'] ?>.php?doc_id=<?= $doc['id'] ?>" class="btn-sm btn-sm-primary"><?= __t('acc_btn_continue') ?></a>
                  <a href="<?= SITE_URL ?>/delete-document.php?id=<?= $doc['id'] ?>&back=account" class="btn-sm btn-sm-danger" onclick="return confirm('<?= __t('acc_confirm_delete') ?>')"><?= __t('acc_btn_delete') ?></a>
                <?php else: ?>
                  <a href="<?= SITE_URL ?>/document-view.php?id=<?= $doc['id'] ?>" class="btn-sm btn-sm-outline"><?= __t('acc_btn_view') ?></a>
                <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">📭</div>
          <div class="empty-text"><?= __t('acc_empty_filtered') ?></div>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ВКЛАДКА: ИСТОРИЯ ПЛАТЕЖЕЙ                  -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($tab === 'history'): ?>

      <div class="info-banner info-banner-blue">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><?= __t('acc_total_paid_info') ?> <strong><?= number_format($totalPaid, 0, '.', ' ') ?> ₸</strong></div>
      </div>

      <div class="kpi-grid" style="grid-template-columns:repeat(3,1fr)">
        <div class="kpi-card">
          <div class="kpi-label"><?= __t('acc_total_charged') ?></div>
          <div class="kpi-value"><?= number_format($totalCharged,0,'.', ' ') ?></div>
          <div class="kpi-sub"><?= __t('acc_tenge') ?></div>
        </div>
        <div class="kpi-card success">
          <div class="kpi-label"><?= __t('acc_total_paid') ?></div>
          <div class="kpi-value"><?= number_format($totalPaid,0,'.', ' ') ?></div>
          <div class="kpi-sub"><?= __t('acc_tenge') ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label"><?= __t('acc_count_payments') ?></div>
          <div class="kpi-value"><?= count($historyCharges) ?></div>
          <div class="kpi-sub"><?= __t('acc_records') ?></div>
        </div>
      </div>

      <div class="section-card">
        <div class="section-card-header">
          <div class="section-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?= __t('acc_history') ?>
          </div>
        </div>
        <?php if ($historyCharges): ?>
        <table class="data-table">
          <thead>
            <tr><th><?= __t('acc_table_charge_date') ?></th><th><?= __t('acc_table_tax_type') ?></th><th><?= __t('acc_table_period') ?></th><th><?= __t('acc_table_charged') ?></th><th><?= __t('acc_table_paid') ?></th><th><?= __t('acc_table_balance') ?></th><th><?= __t('acc_table_status') ?></th></tr>
          </thead>
          <tbody>
            <?php foreach ($historyCharges as $c):
              $paid   = (float)($c['paid_amount'] ?? 0);
              $amount = (float)$c['amount'];
              $debt   = max(0, $amount - $paid);
              $pct    = $amount > 0 ? min(100, round($paid / $amount * 100)) : 100;
              $isPaid = $debt <= 0;
            ?>
            <tr>
              <td data-label="<?= __t('acc_table_date') ?>" style="font-size:12px;color:#6b7280"><?= date('d.m.Y', strtotime($c['charge_date'])) ?></td>
              <td>
                <?php if (!empty($c['type_code'])): ?>
                  <span class="doc-code"><?= htmlspecialchars($c['type_code']) ?></span>
                <?php endif; ?>
                <span style="font-size:12px;color:#374151;margin-left:4px"><?= htmlspecialchars($c['tax_type'] ?? '—') ?></span>
              </td>
              <td>
                <span class="period-tag"><?= htmlspecialchars($periodNames[$c['period']??''] ?? ($c['period']??'—')) ?></span>
                <span style="font-size:11px;color:#9ca3af;margin-left:4px"><?= $c['period_year'] ?? '' ?></span>
              </td>
              <td data-label="<?= __t('acc_table_charged') ?>" class="amount-cell amount-charged"><?= number_format($amount,0,'.', ' ') ?> ₸</td>
              <td data-label="<?= __t('acc_table_paid') ?>" class="amount-cell amount-paid"><?= number_format($paid,0,'.', ' ') ?> ₸</td>
              <td class="amount-cell <?= $debt > 0 ? 'amount-debt' : 'amount-paid' ?>"><?= number_format($debt,0,'.', ' ') ?> ₸</td>
              <td>
                <?php if ($isPaid): ?>
                  <span class="status-badge status-accepted"><span class="status-dot"></span><?= __t('acc_status_paid') ?></span>
                <?php else: ?>
                  <span class="status-badge status-review" style="background:#fef3c7;color:#92400e">
                    <span class="status-dot"></span><?= __t('acc_status_partial') ?> (<?= $pct ?>%)
                  </span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">💳</div>
          <div class="empty-text"><?= __t('acc_no_payments') ?></div>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ВКЛАДКА: ЗАДОЛЖЕННОСТИ                     -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($tab === 'debts'): ?>

      <?php if (count($debtCharges) > 0): ?>
      <div class="info-banner info-banner-red">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><?= __t('acc_debt_warning') ?> <strong><?= number_format($totalDebt,0,'.', ' ') ?> ₸</strong>. <?= __t('acc_debt_warning_2') ?></div>
      </div>
      <?php else: ?>
      <div class="info-banner info-banner-green">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <div><?= __t('acc_no_debts') ?></div>
      </div>
      <?php endif; ?>

      <div class="kpi-grid" style="grid-template-columns:repeat(3,1fr)">
        <div class="kpi-card <?= count($debtCharges) > 0 ? 'danger' : '' ?>">
          <div class="kpi-label"><?= __t('acc_count_debts') ?></div>
          <div class="kpi-value"><?= count($debtCharges) ?></div>
          <div class="kpi-sub"><?= __t('acc_positions') ?></div>
        </div>
        <div class="kpi-card <?= $totalDebt > 0 ? 'danger' : '' ?>">
          <div class="kpi-label"><?= __t('acc_total_debt') ?></div>
          <div class="kpi-value"><?= number_format($totalDebt,0,'.', ' ') ?></div>
          <div class="kpi-sub"><?= __t('acc_tenge') ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label"><?= __t('acc_paid_percent') ?></div>
          <div class="kpi-value"><?= $totalCharged > 0 ? round($totalPaid/$totalCharged*100) : 100 ?>%</div>
          <div class="kpi-sub"><?= __t('acc_of_total') ?></div>
        </div>
      </div>

      <div class="section-card">
        <div class="section-card-header">
          <div class="section-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= __t('acc_debts') ?>
          </div>
        </div>
        <?php if ($debtCharges): ?>
        <table class="data-table">
          <thead>
            <tr><th><?= __t('acc_table_charge_date') ?></th><th><?= __t('acc_table_tax_type') ?></th><th><?= __t('acc_table_period') ?></th><th><?= __t('acc_table_charged') ?></th><th><?= __t('acc_table_paid') ?></th><th><?= __t('acc_table_debt_rest') ?></th><th><?= __t('acc_table_progress') ?></th></tr>
          </thead>
          <tbody>
            <?php foreach ($debtCharges as $c):
              $paid   = (float)($c['paid_amount'] ?? 0);
              $amount = (float)$c['amount'];
              $debt   = max(0, $amount - $paid);
              $pct    = $amount > 0 ? min(100, round($paid / $amount * 100)) : 0;
            ?>
            <tr>
              <td data-label="<?= __t('acc_table_date') ?>" style="font-size:12px;color:#6b7280"><?= date('d.m.Y', strtotime($c['charge_date'])) ?></td>
              <td>
                <?php if (!empty($c['type_code'])): ?>
                  <span class="doc-code"><?= htmlspecialchars($c['type_code']) ?></span>
                <?php endif; ?>
                <span style="font-size:12px;color:#374151;margin-left:4px"><?= htmlspecialchars($c['tax_type'] ?? '—') ?></span>
              </td>
              <td>
                <span class="period-tag"><?= htmlspecialchars($periodNames[$c['period']??''] ?? ($c['period']??'—')) ?></span>
                <span style="font-size:11px;color:#9ca3af;margin-left:4px"><?= $c['period_year'] ?? '' ?></span>
              </td>
              <td data-label="<?= __t('acc_table_charged') ?>" class="amount-cell amount-charged"><?= number_format($amount,0,'.', ' ') ?> ₸</td>
              <td data-label="<?= __t('acc_table_paid') ?>" class="amount-cell amount-paid"><?= number_format($paid,0,'.', ' ') ?> ₸</td>
              <td class="amount-cell amount-debt"><?= number_format($debt,0,'.', ' ') ?> ₸</td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="progress-bar-wrap">
                    <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:#f59e0b"></div>
                  </div>
                  <span style="font-size:11px;color:#6b7280;white-space:nowrap"><?= $pct ?>%</span>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">✅</div>
          <div class="empty-text"><?= __t('acc_no_debts') ?></div>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ВКЛАДКА: ПЕРЕПЛАТА                         -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($tab === 'overpayment'): ?>

      <?php if ($totalOverpay > 0): ?>
      <div class="overpay-summary">
        <div class="overpay-label"><?= __t('acc_overpay_sum') ?></div>
        <div class="overpay-amount"><?= number_format($totalOverpay,0,'.', ' ') ?> ₸</div>
        <div class="overpay-note"><?= __t('acc_overpay_note') ?></div>
      </div>

      <div class="info-banner info-banner-purple">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
          <?= __t('acc_overpay_info') ?>
        </div>
      </div>
      <?php else: ?>
      <div class="info-banner info-banner-blue">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><?= __t('acc_overpay_none') ?></div>
      </div>
      <?php endif; ?>

      <div class="section-card">
        <div class="section-card-header">
          <div class="section-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 11 21 7 17 3"/><line x1="21" y1="7" x2="9" y2="7"/><polyline points="7 21 3 17 7 13"/><line x1="15" y1="17" x2="3" y2="17"/></svg>
            <?= __t('acc_overpayment') ?>
          </div>
        </div>
        <?php if ($overpayCharges): ?>
        <table class="data-table">
          <thead>
            <tr><th><?= __t('acc_table_date') ?></th><th><?= __t('acc_table_tax_type') ?></th><th><?= __t('acc_table_period') ?></th><th><?= __t('acc_table_charged') ?></th><th><?= __t('acc_table_paid') ?></th><th><?= __t('acc_table_overpay') ?></th></tr>
          </thead>
          <tbody>
            <?php foreach ($overpayCharges as $c):
              $paid   = (float)($c['paid_amount'] ?? 0);
              $amount = (float)$c['amount'];
              $over   = $paid - $amount;
            ?>
            <tr>
              <td data-label="<?= __t('acc_table_date') ?>" style="font-size:12px;color:#6b7280"><?= date('d.m.Y', strtotime($c['charge_date'])) ?></td>
              <td>
                <?php if (!empty($c['type_code'])): ?>
                  <span class="doc-code"><?= htmlspecialchars($c['type_code']) ?></span>
                <?php endif; ?>
                <span style="font-size:12px;color:#374151;margin-left:4px"><?= htmlspecialchars($c['tax_type'] ?? '—') ?></span>
              </td>
              <td>
                <span class="period-tag"><?= htmlspecialchars($periodNames[$c['period']??''] ?? ($c['period']??'—')) ?></span>
                <span style="font-size:11px;color:#9ca3af;margin-left:4px"><?= $c['period_year'] ?? '' ?></span>
              </td>
              <td data-label="<?= __t('acc_table_charged') ?>" class="amount-cell amount-charged"><?= number_format($amount,0,'.', ' ') ?> ₸</td>
              <td data-label="<?= __t('acc_table_paid') ?>" class="amount-cell amount-paid"><?= number_format($paid,0,'.', ' ') ?> ₸</td>
              <td class="amount-cell amount-over">+<?= number_format($over,0,'.', ' ') ?> ₸</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">⚖️</div>
          <div class="empty-text"><?= __t('acc_no_overpay') ?></div>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ВКЛАДКА: НАЧИСЛЕНИЯ                        -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($tab === 'charges'): ?>

      <div class="kpi-grid" style="grid-template-columns:repeat(3,1fr)">
        <div class="kpi-card">
          <div class="kpi-label"><?= __t('acc_total_charged') ?></div>
          <div class="kpi-value"><?= number_format($totalCharged,0,'.', ' ') ?></div>
          <div class="kpi-sub"><?= __t('acc_tenge') ?></div>
        </div>
        <div class="kpi-card success">
          <div class="kpi-label"><?= __t('acc_total_paid') ?></div>
          <div class="kpi-value"><?= number_format($totalPaid,0,'.', ' ') ?></div>
          <div class="kpi-sub"><?= __t('acc_tenge') ?></div>
        </div>
        <div class="kpi-card <?= $totalDebt > 0 ? 'danger' : '' ?>">
          <div class="kpi-label"><?= __t('acc_debts') ?></div>
          <div class="kpi-value"><?= number_format($totalDebt,0,'.', ' ') ?></div>
          <div class="kpi-sub"><?= __t('acc_tenge') ?></div>
        </div>
      </div>

      <div class="section-card">
        <div class="section-card-header">
          <div class="section-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            <?= __t('acc_all_charges') ?>
          </div>
        </div>
        <?php if ($charges): ?>
        <table class="data-table">
          <thead>
            <tr><th><?= __t('acc_table_date') ?></th><th><?= __t('acc_table_tax_type') ?></th><th><?= __t('acc_table_period') ?></th><th><?= __t('acc_table_charged') ?></th><th><?= __t('acc_table_paid') ?></th><th><?= __t('acc_table_balance') ?></th><th><?= __t('acc_table_progress') ?></th></tr>
          </thead>
          <tbody>
            <?php foreach ($charges as $c):
              $paid   = (float)($c['paid_amount'] ?? 0);
              $amount = (float)$c['amount'];
              $debt   = max(0, $amount - $paid);
              $pct    = $amount > 0 ? min(100, round($paid / $amount * 100)) : 0;
              $isPaid = $debt <= 0;
            ?>
            <tr class="<?= $isPaid ? 'charge-row-paid' : '' ?>">
              <td data-label="<?= __t('acc_table_date') ?>" style="font-size:12px;color:#6b7280"><?= date('d.m.Y', strtotime($c['charge_date'])) ?></td>
              <td>
                <?php if (!empty($c['type_code'])): ?>
                  <span class="doc-code"><?= htmlspecialchars($c['type_code']) ?></span>
                <?php endif; ?>
                <span style="font-size:12px;color:#374151;margin-left:4px"><?= htmlspecialchars($c['tax_type'] ?? '—') ?></span>
              </td>
              <td>
                <span class="period-tag"><?= htmlspecialchars($periodNames[$c['period']??''] ?? ($c['period']??'—')) ?></span>
                <span style="font-size:11px;color:#9ca3af;margin-left:4px"><?= $c['period_year'] ?? '' ?></span>
              </td>
              <td data-label="<?= __t('acc_table_charged') ?>" class="amount-cell amount-charged"><?= number_format($amount,0,'.', ' ') ?> ₸</td>
              <td data-label="<?= __t('acc_table_paid') ?>" class="amount-cell amount-paid"><?= number_format($paid,0,'.', ' ') ?> ₸</td>
              <td class="amount-cell <?= $debt > 0 ? 'amount-debt' : 'amount-paid' ?>"><?= number_format($debt,0,'.', ' ') ?> ₸</td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="progress-bar-wrap">
                    <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $isPaid ? '#059669' : '#f59e0b' ?>"></div>
                  </div>
                  <span style="font-size:11px;color:#6b7280;white-space:nowrap"><?= $pct ?>%</span>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">💳</div>
          <div class="empty-text"><?= __t('msg_no_data') ?></div>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ВКЛАДКА: ПРОФИЛЬ                           -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($tab === 'profile'): ?>

      <div class="profile-card">
        <div class="profile-header">
          <div class="profile-avatar"><?= mb_strtoupper(mb_substr($user['full_name'], 0, 1)) ?></div>
          <div>
            <div class="profile-name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="profile-role"><?= ($user['user_type'] ?? 'individual') === 'admin' ? __t('acc_profile_admin') : __t('acc_profile_taxpayer') ?></div>
          </div>
        </div>
        <div class="profile-body">
          <div class="profile-field">
            <div class="profile-field-label"><?= __t('field_iin') ?></div>
            <div class="profile-field-value"><?= htmlspecialchars($user['iin'] ?? '—') ?></div>
          </div>
          <div class="profile-field">
            <div class="profile-field-label"><?= __t('field_email') ?></div>
            <div class="profile-field-value"><?= htmlspecialchars($user['email'] ?? '—') ?></div>
          </div>
          <div class="profile-field">
            <div class="profile-field-label"><?= __t('field_phone') ?></div>
            <div class="profile-field-value"><?= htmlspecialchars($user['phone'] ?? '—') ?></div>
          </div>
          <div class="profile-field">
            <div class="profile-field-label"><?= __t('acc_profile_reg_date') ?></div>
            <div class="profile-field-value"><?= isset($user['created_at']) ? date('d.m.Y', strtotime($user['created_at'])) : '—' ?></div>
          </div>
          <div class="profile-field">
            <div class="profile-field-label"><?= __t('acc_profile_status') ?></div>
            <div class="profile-field-value">
              <?php if (($user['status'] ?? '') === 'active'): ?>
                <span class="status-badge status-accepted"><span class="status-dot"></span><?= __t('acc_profile_active') ?></span>
              <?php else: ?>
                <span class="status-badge status-draft"><span class="status-dot"></span><?= __t('acc_profile_inactive') ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="profile-field">
            <div class="profile-field-label"><?= __t('acc_profile_last_login') ?></div>
            <div class="profile-field-value"><?= isset($user['last_login']) ? date('d.m.Y H:i', strtotime($user['last_login'])) : '—' ?></div>
          </div>
        </div>
      </div>

      <!-- Смена пароля -->
      <?php
      $pwSuccess = $pwError = '';
      if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
          $oldPw  = $_POST['old_password'] ?? '';
          $newPw  = $_POST['new_password'] ?? '';
          $confPw = $_POST['confirm_password'] ?? '';
          if (!password_verify($oldPw, $user['password'])) {
              $pwError = __t('acc_pw_error_old');
          } elseif (strlen($newPw) < 6) {
              $pwError = __t('acc_pw_error_short');
          } elseif ($newPw !== $confPw) {
              $pwError = __t('acc_pw_error_match');
          } else {
              try {
                  $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPw, PASSWORD_DEFAULT), $userId]);
                  $pwSuccess = __t('acc_pw_success');
              } catch (Exception $e) {
                  $pwError = __t('acc_pw_error_save');
              }
          }
      }
      ?>

      <div class="section-card">
        <div class="section-card-header">
          <div class="section-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <?= __t('profile_password_change') ?>
          </div>
        </div>
        <div style="padding:20px 24px">
          <?php if ($pwSuccess): ?>
            <div style="padding:12px 16px;background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;border-radius:8px;font-size:13px;margin-bottom:16px"><?= htmlspecialchars($pwSuccess) ?></div>
          <?php endif; ?>
          <?php if ($pwError): ?>
            <div style="padding:12px 16px;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;font-size:13px;margin-bottom:16px"><?= htmlspecialchars($pwError) ?></div>
          <?php endif; ?>
          <form method="POST" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;align-items:end">
            <div>
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px"><?= __t('acc_pw_old') ?></label>
              <input type="password" name="old_password" required style="width:100%;padding:9px 12px;border:1px solid #e2e6ea;border-radius:7px;font-size:13px;outline:none" placeholder="••••••••">
            </div>
            <div>
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px"><?= __t('acc_pw_new') ?></label>
              <input type="password" name="new_password" required minlength="6" style="width:100%;padding:9px 12px;border:1px solid #e2e6ea;border-radius:7px;font-size:13px;outline:none" placeholder="<?= __t('auth_pass_min_hint') ?>">
            </div>
            <div>
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px"><?= __t('acc_pw_confirm') ?></label>
              <input type="password" name="confirm_password" required style="width:100%;padding:9px 12px;border:1px solid #e2e6ea;border-radius:7px;font-size:13px;outline:none" placeholder="<?= __t('auth_pass_confirm') ?>">
            </div>
            <div style="grid-column:1/-1">
              <button type="submit" name="change_password" value="1" style="padding:10px 24px;background:#1a3c6e;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">
                <?= __t('acc_pw_btn') ?>
              </button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>