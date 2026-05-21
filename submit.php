<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$search     = trim($_GET['q'] ?? '');
$activeTab  = $_GET['tab'] ?? 'all';
$category   = $_GET['cat'] ?? 'all'; // Новая фильтрация по категориям
$typeId     = (int)($_GET['type'] ?? 0);

// Все типы документов
try {
    // Проверка существования формы 4.00 в базе
    $checkStmt = $db->prepare("SELECT id FROM document_types WHERE code = '4.00'");
    $checkStmt->execute();
    if (!$checkStmt->fetch()) {
        $db->prepare("INSERT INTO document_types (name, code, description, is_active) VALUES ('Спецрежим для самозанятых', '4.00', 'Единый платеж для физических лиц (самозанятых)', 1)")->execute();
    }

    $sql = "SELECT * FROM document_types WHERE is_active = 1";
    $params = [];
    if ($search) {
        $sql .= " AND (name LIKE ? OR code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY code";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $docTypes = $stmt->fetchAll();
} catch (Exception $e) { $docTypes = []; }

// Избранные
try {
    $stmtFav = $db->prepare("SELECT doc_type_id FROM user_favorites WHERE user_id = ?");
    $stmtFav->execute([$userId]);
    $favorites = array_column($stmtFav->fetchAll(), 'doc_type_id');
} catch (Exception $e) { $favorites = []; }

// Открыть модалку подачи если передан type
$openModal = $typeId > 0 ? array_values(array_filter($docTypes, fn($t) => $t['id'] === $typeId)) : [];
$openModal = $openModal[0] ?? null;

$unreadCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $s->execute([$userId]);
    $unreadCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

$pageTitle = __t('btn_submit_doc') . ' — ' . SITE_NAME;
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="page-wrapper">
  <div class="container">

    <div class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard.php"><?= __t('nav_main') ?></a>
      <span>—</span>
      <span><?= __t('btn_submit_doc') ?></span>
    </div>

    <div class="page-heading">
      <h1><?= __t('btn_submit_doc') ?></h1>
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
      </form>
    </div>

    <!-- TABS -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px">
        <div class="filter-tabs" style="margin-bottom:0">
          <a href="?tab=all<?= $search?'&q='.urlencode($search):'' ?>&cat=<?= $category ?>"
             class="filter-tab <?= $activeTab==='all'?'active':'' ?>"><?= __t('filter_all') ?></a>
          <a href="?tab=fav<?= $search?'&q='.urlencode($search):'' ?>&cat=<?= $category ?>"
             class="filter-tab <?= $activeTab==='fav'?'active':'' ?>"><?= __t('btn_fav') ?></a>
          <a href="?tab=drafts<?= $search?'&q='.urlencode($search):'' ?>&cat=<?= $category ?>"
             class="filter-tab <?= $activeTab==='drafts'?'active':'' ?>"><?= __t('btn_drafts') ?></a>
        </div>

        <div style="display:flex; gap:8px">
            <a href="?tab=<?= $activeTab ?>&cat=all<?= $search?'&q='.urlencode($search):'' ?>" 
               style="font-size:12px; padding:4px 12px; border-radius:15px; text-decoration:none; background:<?= $category==='all'?'var(--accent)':'var(--bg-muted)' ?>; color:<?= $category==='all'?'#fff':'var(--text-muted)' ?>; font-weight:600; border:1px solid var(--border)">
               <?= __t('filter_all_forms') ?>
            </a>
            <a href="?tab=<?= $activeTab ?>&cat=individual<?= $search?'&q='.urlencode($search):'' ?>" 
               style="font-size:12px; padding:4px 12px; border-radius:15px; text-decoration:none; background:<?= $category==='individual'?'var(--accent)':'var(--bg-muted)' ?>; color:<?= $category==='individual'?'#fff':'var(--text-muted)' ?>; font-weight:600; border:1px solid var(--border)">
               <?= __t('filter_individuals_ip') ?>
            </a>
            <a href="?tab=<?= $activeTab ?>&cat=business<?= $search?'&q='.urlencode($search):'' ?>" 
               style="font-size:12px; padding:4px 12px; border-radius:15px; text-decoration:none; background:<?= $category==='business'?'var(--accent)':'var(--bg-muted)' ?>; color:<?= $category==='business'?'#fff':'var(--text-muted)' ?>; font-weight:600; border:1px solid var(--border)">
               <?= __t('filter_business') ?>
            </a>
        </div>
    </div>

    <!-- LIST -->
    <div class="submit-list">
      <div class="submit-list-header">
        <span><?= __t('table_name') ?></span>
        <span><?= __t('table_type') ?></span>
        <span><?= __t('btn_fav') ?></span>
      </div>

      <?php
      $show = $docTypes;
      if ($activeTab === 'fav') {
          $show = array_filter($docTypes, fn($t) => in_array($t['id'], $favorites));
      }
      
      // Фильтрация по категориям
      if ($category === 'individual') {
          $show = array_filter($show, fn($t) => in_array($t['code'], ['200.00', '240.00', '250.00', '270.00', '4.00', '910.00']));
      } elseif ($category === 'business') {
          $show = array_filter($show, fn($t) => in_array($t['code'], ['100.00', '300.00', '700.00', '910.00']));
      }

      $show = array_values($show);
      ?>

      <?php if (empty($show)): ?>
        <div class="empty-state" style="padding:48px">
          <svg viewBox="0 0 64 64" fill="none">
            <rect x="10" y="6" width="34" height="44" rx="3" stroke="#cbd5e1" stroke-width="2"/>
            <path d="M10 18h34" stroke="#cbd5e1" stroke-width="1.5"/>
            <rect x="16" y="24" width="18" height="2" rx="1" fill="#cbd5e1"/>
          </svg>
          <p><?= __t('msg_no_data') ?></p>
        </div>
      <?php else: ?>
        <?php foreach ($show as $t): 
          $typeNameKey = 'doc_type_' . str_replace('.', '_', $t['code']);
          $translatedName = __t($typeNameKey);
          if ($translatedName === $typeNameKey) $translatedName = $t['name'];
        ?>
        <div class="submit-list-row" onclick="openSubmitModal(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($translatedName)) ?>')">
          <div>
            <div class="submit-list-row-name"><?= htmlspecialchars($t['code'] . ' ' . $translatedName) ?></div>
          </div>
          <div class="submit-list-row-type"><?= __t('table_doc_type_label') ?></div>
          <button class="star-btn <?= in_array($t['id'], $favorites)?'active':'' ?>"
                  onclick="toggleFav(event, this, <?= $t['id'] ?>)"
                  title="<?= __t('btn_fav') ?>">
            <svg viewBox="0 0 24 24" fill="<?= in_array($t['id'],$favorites)?'currentColor':'none' ?>"
                 stroke="currentColor" stroke-width="2">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
          </button>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- МОДАЛКА ПОДАЧИ -->
<div id="submitModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:28px 32px;width:480px;max-width:95vw;position:relative;box-shadow:0 8px 32px rgba(0,0,0,.18)">
    <button onclick="closeModal()" style="position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:50%;border:1px solid #e2e6ea;background:#fff;color:#6b7280;font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer">×</button>

    <h3 id="modalTitle" style="font-size:15px;font-weight:700;color:#1a1a2e;margin-bottom:6px;line-height:1.4;padding-right:24px"></h3>
    <p id="modalPeriodLabel" style="font-size:12px;color:#6b7280;margin-bottom:20px"></p>

    <form method="POST" action="<?= SITE_URL ?>/submit-process.php">
      <input type="hidden" name="doc_type_id" id="modalTypeId">

      <!-- ГОД -->
      <div id="fieldYear" style="margin-bottom:14px">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px"><?= __t('form_year_label') ?></label>
        <select name="year" style="width:100%;padding:10px 14px;border:1px solid #e2e6ea;border-radius:8px;font-size:13px;outline:none;background:#fff">
          <?php for ($y = date('Y'); $y >= 2015; $y--): ?>
          <option value="<?= $y ?>" <?= $y == date('Y')-1 ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <!-- КВАРТАЛ (для формы 200.00) -->
      <div id="fieldQuarter" style="display:none;margin-bottom:14px">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px"><?= __t('form_period_quarter') ?></label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #e2e6ea;border-radius:8px;cursor:pointer;font-size:13px;transition:border-color .15s">
            <input type="radio" name="quarter" value="1" checked> <?= __t('form_period_q1') ?>
          </label>
          <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #e2e6ea;border-radius:8px;cursor:pointer;font-size:13px;transition:border-color .15s">
            <input type="radio" name="quarter" value="2"> <?= __t('form_period_q2') ?>
          </label>
          <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #e2e6ea;border-radius:8px;cursor:pointer;font-size:13px;transition:border-color .15s">
            <input type="radio" name="quarter" value="3"> <?= __t('form_period_q3') ?>
          </label>
          <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #e2e6ea;border-radius:8px;cursor:pointer;font-size:13px;transition:border-color .15s">
            <input type="radio" name="quarter" value="4"> <?= __t('form_period_q4') ?>
          </label>
        </div>
      </div>

      <!-- ПОЛУГОДИЕ (для формы 910.00) -->
      <div id="fieldHalfYear" style="display:none;margin-bottom:14px">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px"><?= __t('form_period_halfyear') ?></label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <label style="display:flex;align-items:center;gap:8px;padding:12px 16px;border:1px solid #e2e6ea;border-radius:8px;cursor:pointer;font-size:13px;transition:border-color .15s" id="half1label">
            <input type="radio" name="half_year" value="1" checked>
            <div>
              <div style="font-weight:600"><?= __t('form_period_h1') ?></div>
            </div>
          </label>
          <label style="display:flex;align-items:center;gap:8px;padding:12px 16px;border:1px solid #e2e6ea;border-radius:8px;cursor:pointer;font-size:13px;transition:border-color .15s" id="half2label">
            <input type="radio" name="half_year" value="2">
            <div>
              <div style="font-weight:600"><?= __t('form_period_h2') ?></div>
            </div>
          </label>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:24px">
        <button type="submit" name="action" value="submit"
                style="flex:1;padding:11px;background:#1a3c6e;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">
          <?= __t('btn_submit_doc') ?>
        </button>
        <button type="button" onclick="closeModal()"
                style="padding:11px 18px;background:#fff;color:#374151;border:1px solid #e2e6ea;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer">
          <?= __t('btn_cancel') ?>
        </button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
// Маппинг типов периода по doc_type_id
// 1=240, 2=250, 3=270, 4=328, 5=510, 6=590, 7=700, 8=910, 9=920, 10=200, 11=300, 12=100
const periodType = {
    10: 'quarter',   // 200.00 — квартальная
    7:  'year',      // 700.00 — годовая
    8:  'halfyear',  // 910.00 — полугодовая
};
const periodDesc = {
    'quarter':  '<?= __t('form_desc_quarter') ?>',
    'year':     '<?= __t('form_desc_year') ?>',
    'halfyear': '<?= __t('form_desc_halfyear') ?>',
    'default':  '<?= __t('form_desc_default') ?>',
};

function openSubmitModal(id, name) {
    document.getElementById('modalTitle').textContent = name;
    document.getElementById('modalTypeId').value = id;

    const type = periodType[id] || 'default';
    document.getElementById('modalPeriodLabel').textContent = periodDesc[type] || periodDesc['default'];

    // Скрываем все доп. поля
    document.getElementById('fieldQuarter').style.display  = 'none';
    document.getElementById('fieldHalfYear').style.display = 'none';
    document.getElementById('fieldYear').style.display     = 'block';

    if (type === 'quarter')  document.getElementById('fieldQuarter').style.display  = 'block';
    if (type === 'halfyear') document.getElementById('fieldHalfYear').style.display = 'block';

    document.getElementById('submitModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('submitModal').style.display = 'none';
}
document.getElementById('submitModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function toggleFav(e, btn, id) {
    e.stopPropagation();
    const isActive = btn.classList.toggle('active');
    btn.querySelector('polygon').setAttribute('fill', isActive ? 'currentColor' : 'none');
    fetch('<?= SITE_URL ?>/ajax/toggle-favorite.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'doc_type_id=' + id
    });
}

<?php if ($openModal): ?>
openSubmitModal(<?= $openModal['id'] ?>, '<?= htmlspecialchars(addslashes($openModal['code'] . ' ' . $openModal['name'])) ?>');
<?php endif; ?>
</script>
