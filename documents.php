<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$activeTab = $_GET['tab'] ?? 'tax_report';
$statusFilter = $_GET['status'] ?? 'all';
$search    = trim($_GET['q'] ?? '');

// Мои документы
try {
    $sql = "
        SELECT ud.*, dt.name AS type_name, dt.code AS type_code,
               COALESCE(ud.submitted_at, ud.signed_at, ud.created_at) AS display_date
        FROM user_documents ud
        JOIN document_types dt ON ud.doc_type_id = dt.id
        WHERE ud.user_id = ?
    ";
    $params = [$userId];

    if ($statusFilter === 'submitted') {
        $sql .= " AND ud.status IN ('submitted', 'in_review', 'accepted', 'rejected')";
    } elseif ($statusFilter === 'draft') {
        $sql .= " AND ud.status = 'draft'";
    }

    if ($search) {
        $sql .= " AND (dt.name LIKE ? OR dt.code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY ud.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $allDocs = $stmt->fetchAll();
} catch (Exception $e) { $allDocs = []; }

// Входящие
try {
    $stmt2 = $db->prepare("SELECT * FROM incoming_documents WHERE user_id = ? ORDER BY received_at DESC");
    $stmt2->execute([$userId]);
    $incomingDocs = $stmt2->fetchAll();
} catch (Exception $e) { $incomingDocs = []; }

$pageTitle = __t('docs_title') . ' — ' . SITE_NAME;
$unreadCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $s->execute([$userId]);
    $unreadCount = (int)$s->fetchColumn();
} catch (Exception $e) {}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="page-wrapper">
  <div class="container">

    <!-- BREADCRUMB -->
    <div class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard.php"><?= __t('dashboard_title') ?></a>
      <span>—</span>
      <span><?= __t('docs_title') ?></span>
    </div>

    <!-- HEADING -->
    <div class="page-heading">
      <h1><?= __t('docs_title') ?></h1>
    </div>

    <!-- SEARCH -->
    <div class="search-bar">
      <form method="GET" style="display:contents">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
        <div class="search-input-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="q" class="search-input"
                 placeholder="<?= __t('search_placeholder') ?>"
                 value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit" class="btn-find"><?= __t('btn_find') ?></button>
        <a href="<?= SITE_URL ?>/export-docs.php?q=<?= urlencode($search) ?>" class="btn-advanced" style="text-decoration:none;display:inline-flex;align-items:center;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="margin-right:6px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          <?= __t('btn_export') ?>
        </a>
      </form>
    </div>

    <!-- FILTER TABS -->
    <div class="filter-controls">
        <div class="filter-tabs">
          <a href="?tab=tax_report<?= $search ? '&q='.urlencode($search) : '' ?>&status=<?= $statusFilter ?>"
             class="filter-tab <?= $activeTab==='tax_report' ? 'active' : '' ?>">
            <?= __t('tab_tax_report') ?>
          </a>
          <a href="?tab=tax_statement<?= $search ? '&q='.urlencode($search) : '' ?>&status=<?= $statusFilter ?>"
             class="filter-tab <?= $activeTab==='tax_statement' ? 'active' : '' ?>">
            <?= __t('nav_tax_statement') ?>
          </a>
          <a href="?tab=other<?= $search ? '&q='.urlencode($search) : '' ?>&status=<?= $statusFilter ?>"
             class="filter-tab <?= $activeTab==='other' ? 'active' : '' ?>">
            <?= __t('tab_other_docs') ?>
          </a>
        </div>
        
        <div class="status-filters">
            <a href="?tab=<?= $activeTab ?>&status=all<?= $search ? '&q='.urlencode($search) : '' ?>" 
               class="status-filter-btn <?= $statusFilter==='all'?'active':'' ?>">
               <?= __t('filter_all') ?>
            </a>
            <a href="?tab=<?= $activeTab ?>&status=submitted<?= $search ? '&q='.urlencode($search) : '' ?>" 
               class="status-filter-btn <?= $statusFilter==='submitted'?'active':'' ?>">
               <?= __t('filter_submitted') ?>
            </a>
            <a href="?tab=<?= $activeTab ?>&status=draft<?= $search ? '&q='.urlencode($search) : '' ?>" 
               class="status-filter-btn <?= $statusFilter==='draft'?'active':'' ?>">
               <?= __t('filter_drafts') ?>
            </a>
        </div>
    </div>

    <!-- TABLE CARD -->
    <div class="card" style="border-radius:0 8px 8px 8px">
      <?php
      // Фильтруем по табу
      $filtered = array_filter($allDocs, function($d) use ($activeTab) {
          if ($activeTab === 'tax_report')     return true; // все в налоговой отчётности
          if ($activeTab === 'tax_statement')  return false;
          if ($activeTab === 'other')          return false;
          return true;
      });
      $filtered = array_values($filtered);
      ?>

      <?php if (empty($filtered) && $activeTab !== 'tax_report'): ?>
        <div class="empty-state" style="padding:60px 20px">
          <svg viewBox="0 0 64 64" fill="none">
            <rect x="10" y="6" width="34" height="44" rx="3" stroke="#cbd5e1" stroke-width="2"/>
            <path d="M10 18h34" stroke="#cbd5e1" stroke-width="1.5"/>
            <rect x="16" y="24" width="18" height="2" rx="1" fill="#cbd5e1"/>
            <rect x="16" y="30" width="12" height="2" rx="1" fill="#cbd5e1"/>
            <rect x="16" y="36" width="15" height="2" rx="1" fill="#cbd5e1"/>
          </svg>
          <p><?= __t('msg_no_data') ?></p>
        </div>

      <?php elseif (empty($allDocs)): ?>
        <div class="empty-state" style="padding:60px 20px">
          <svg viewBox="0 0 64 64" fill="none">
            <rect x="10" y="6" width="34" height="44" rx="3" stroke="#cbd5e1" stroke-width="2"/>
            <path d="M10 18h34" stroke="#cbd5e1" stroke-width="1.5"/>
            <rect x="16" y="24" width="18" height="2" rx="1" fill="#cbd5e1"/>
            <rect x="16" y="30" width="12" height="2" rx="1" fill="#cbd5e1"/>
            <rect x="16" y="36" width="15" height="2" rx="1" fill="#cbd5e1"/>
          </svg>
          <p><?= __t('msg_no_data') ?></p>
        </div>

      <?php else: ?>
        <table class="doc-table">
    <thead>
      <tr>
        <th><?= __t('table_name') ?></th>
        <th><?= __t('field_iin') ?>/<?= __t('field_bin') ?></th>
        <th style="cursor:pointer">
          <?= __t('table_date') ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               width="11" height="11" style="vertical-align:middle;margin-left:3px">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </th>
        <th><?= __t('table_status') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($allDocs as $d):
        $typeNameKey = 'doc_type_' . str_replace('.', '_', $d['type_code']);
        $translatedName = __t($typeNameKey);
        if ($translatedName === $typeNameKey) $translatedName = $d['type_name'];
      ?>
      <tr style="cursor:pointer" onclick="location.href='<?= SITE_URL ?>/document-view.php?id=<?= (int)$d['id'] ?>'">
        <td data-label="<?= __t('table_name') ?>">
          <div style="font-weight:500"><?= htmlspecialchars($translatedName) ?></div>
          <div style="font-size:11px;color:#9ca3af"><?= htmlspecialchars($d['type_code']) ?></div>
        </td>
        <td data-label="<?= __t('field_iin') ?>"><?= htmlspecialchars($user['iin']) ?></td>
        <td data-label="<?= __t('table_date') ?>">
          <?= !empty($d['display_date']) ? date('d.m.Y', strtotime($d['display_date'])) : '—' ?>
        </td>
        <td data-label="<?= __t('table_status') ?>"><?= statusBadge($d['status']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
