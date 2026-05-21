<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

try {
    $stmtDocs = $db->prepare("
        SELECT ud.*, dt.name AS type_name,
               COALESCE(ud.submitted_at, ud.signed_at) AS display_date
        FROM user_documents ud
        JOIN document_types dt ON ud.doc_type_id = dt.id
        WHERE ud.user_id = ?
        ORDER BY ud.id DESC LIMIT 5
    ");
    $stmtDocs->execute([$userId]);
    $myDocs = $stmtDocs->fetchAll();
} catch (\Exception $e) { $myDocs = []; }

try {
    $stmtInc = $db->prepare("SELECT * FROM incoming_documents WHERE user_id = ? ORDER BY received_at DESC LIMIT 5");
    $stmtInc->execute([$userId]);
    $incomingDocs = $stmtInc->fetchAll();
} catch (\Exception $e) { $incomingDocs = []; }

try {
    $stmtMsg = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmtMsg->execute([$userId]);
    $unreadCount = (int)$stmtMsg->fetchColumn();
} catch (\Exception $e) { $unreadCount = 0; }

try {
    $stmtForms = $db->prepare("SELECT * FROM document_types WHERE is_active = 1 ORDER BY code LIMIT 6");
    $stmtForms->execute();
    $docForms = $stmtForms->fetchAll();
} catch (\Exception $e) { $docForms = []; }

// Календарь налогоплательщика 2026 (Новый Налоговый Кодекс)
$taxCalendar = [
    ['date' => '2026-05-15', 'code' => '200.00', 'name' => __t('nav_tax_reports') . ' (1 кв.)', 'urgent' => false],
    ['date' => '2026-08-15', 'code' => '910.00', 'name' => __t('calc_sn_title') . ' (1 полуг.)', 'urgent' => false],
    ['date' => '2026-09-15', 'code' => '270.00', 'name' => __t('calc_iit_title') . ' (Всеобщ. декл.)', 'urgent' => true],
    ['date' => '2026-09-15', 'code' => '250.00', 'name' => 'Декларация об активах (4-й этап)', 'urgent' => true],
    ['date' => '2026-11-15', 'code' => '200.00', 'name' => __t('nav_tax_reports') . ' (3 кв.)', 'urgent' => false],
    ['date' => '2027-02-15', 'code' => '910.00', 'name' => __t('calc_sn_title') . ' (2 полуг.)', 'urgent' => false],
];

// Фильтруем будущие события
$calendarEvents = array_filter($taxCalendar, fn($e) => strtotime($e['date']) >= strtotime('today'));
usort($calendarEvents, fn($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));

$months = [
    1 => __t('month_1'),
    2 => __t('month_2'),
    3 => __t('month_3'),
    4 => __t('month_4'),
    5 => __t('month_5'),
    6 => __t('month_6'),
    7 => __t('month_7'),
    8 => __t('month_8'),
    9 => __t('month_9'),
    10 => __t('month_10'),
    11 => __t('month_11'),
    12 => __t('month_12')
];

$pageTitle = __t('dashboard_title') . ' — ' . (defined('SITE_NAME') ? SITE_NAME : __t('auth_cabinet_name'));
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
.dashboard-grid{display:grid;grid-template-columns:1fr 360px;gap:16px}
.dashboard-left{display:flex;flex-direction:column;gap:16px}
.dashboard-right{display:flex;flex-direction:column;gap:16px}
.card{background:var(--bg-card);border-radius:10px;border:1px solid var(--border);overflow:hidden;box-shadow:0 1px 3px var(--shadow)}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:15px 20px 13px;border-bottom:1px solid var(--border-light);background:var(--bg-muted)}
.card-title{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:700;color:var(--text)}
.card-title svg{width:18px;height:18px;flex-shrink:0;color:currentColor}
.card-link{display:flex;align-items:center;gap:4px;font-size:13px;color:var(--link);font-weight:500;white-space:nowrap}
.card-link:hover{text-decoration:underline}
.card-link svg{width:13px;height:13px}
.doc-tabs{display:flex;border-bottom:1px solid var(--border);padding:0 20px}
.doc-tab{padding:11px 0;margin-right:22px;font-size:13px;font-weight:500;color:var(--text-muted);border:none;background:none;border-bottom:2px solid transparent;cursor:pointer;transition:color .15s,border-color .15s}
.doc-tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.doc-tab:hover{color:var(--accent)}
.doc-table{width:100%;border-collapse:collapse;font-size:13px}
.doc-table th{text-align:left;padding:9px 20px;font-size:12px;font-weight:500;color:var(--text-muted);background:var(--table-head);border-bottom:1px solid var(--border)}
.doc-table td{padding:11px 20px;border-bottom:1px solid var(--border-light);color:var(--text)}
.doc-table tr:last-child td{border-bottom:none}
.doc-table tr:hover td{background:var(--bg-hover)}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:36px 20px;color:var(--text-faint)}
.empty-state svg{width:68px;height:68px;margin-bottom:12px;opacity:.5}
.empty-state p{font-size:13px;color:var(--text-muted)}
.profile-mini{display:flex;align-items:center;gap:12px;padding:15px 20px}
.profile-mini-avatar{width:40px;height:40px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.profile-mini-avatar svg{width:20px;height:20px;color:#fff}
.profile-mini-type{font-size:11px;color:var(--text-muted);margin-bottom:2px}
.profile-mini-name{font-size:13px;font-weight:700;color:var(--link)}
.card-slider-header{display:flex;align-items:center;justify-content:space-between;padding:15px 20px 13px;border-bottom:1px solid var(--border-light);background:var(--bg-muted)}
.slider-nav{display:flex;gap:4px}
.slider-btn{width:24px;height:24px;border-radius:50%;border:1px solid var(--border);background:var(--bg-card);display:flex;align-items:center;justify-content:center;color:var(--text-muted);transition:background .15s,color .15s;cursor:pointer}
.slider-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.slider-btn svg{width:11px;height:11px}
.submit-doc-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border)}
.submit-doc-item{background:var(--bg-card);padding:13px 15px;display:flex;flex-direction:column;gap:3px;transition:background .15s;cursor:pointer}
.submit-doc-item:hover{background:var(--bg-hover)}
.submit-doc-type{font-size:10px;font-weight:600;color:var(--text-faint);text-transform:uppercase;letter-spacing:.3px;line-height:1.3}
.submit-doc-name{font-size:12px;font-weight:500;color:var(--text);line-height:1.4}
.submit-doc-action{font-size:12px;color:var(--link);font-weight:500;margin-top:3px;text-decoration:none}
.submit-doc-action:hover{text-decoration:underline}
.slider-dots{display:flex;justify-content:center;gap:5px;padding:9px;border-top:1px solid var(--border-light);background:var(--bg-muted)}
.slider-dot{width:6px;height:6px;border-radius:50%;background:var(--border)}
.slider-dot.active{background:var(--accent)}
.calendar-item{display:flex;gap:12px;padding:12px 20px;border-bottom:1px solid var(--border-light);transition:background .15s}
.calendar-item:hover{background:var(--bg-hover)}
.calendar-item:last-child{border-bottom:none}
.calendar-date{width:42px;height:42px;border-radius:8px;background:var(--bg-muted);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;border:1px solid var(--border)}
.calendar-date-day{font-size:16px;font-weight:700;color:var(--text);line-height:1}
.calendar-date-month{font-size:9px;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-top:2px}
.calendar-info{flex:1;min-width:0}
.calendar-name{font-size:12px;font-weight:600;color:var(--text);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.calendar-code{font-size:10px;color:var(--text-muted);font-weight:500}
.calendar-urgent .calendar-date{background:rgba(220, 38, 38, 0.1);border-color:rgba(220, 38, 38, 0.3)}
.calendar-urgent .calendar-date-day, .calendar-urgent .calendar-date-month{color:#dc2626}
@media(max-width:1024px){.dashboard-grid{grid-template-columns:1fr}}
@media(max-width:768px){
  .submit-doc-grid{grid-template-columns:1fr !important}
  .doc-tabs{overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .doc-tabs::-webkit-scrollbar{display:none}
  .doc-tab{white-space:nowrap;flex-shrink:0}
  .doc-table thead{display:none}
  .doc-table,.doc-table tbody,.doc-table tr,.doc-table td{display:block;width:100%}
  .doc-table tbody tr{border:1px solid #eef0f3;border-radius:8px;margin:8px 0;padding:2px 0;box-shadow:0 1px 3px rgba(0,0,0,.05)}
  .doc-table td{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;border-bottom:1px solid #f3f4f6;font-size:13px}
  .doc-table td:last-child{border-bottom:none}
  .doc-table td::before{content:attr(data-label);font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-right:10px;white-space:nowrap;flex-shrink:0}
  .card-header,.card-slider-header{padding:12px 14px}
  .dashboard-grid{gap:12px}
}
</style>

<div class="page-wrapper">
  <div class="container">
    <div class="dashboard-grid">

      <div class="dashboard-left">

          <!-- ДОКУМЕНТЫ -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
              </svg>
              <?= __t('nav_docs') ?>
            </span>
            <a href="<?= SITE_URL ?>/documents.php" class="card-link">
              <?= __t('btn_see_all') ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          </div>
          <div>
            <div class="doc-tabs">
              <button class="doc-tab active" onclick="switchTab(this,'my-docs')"><?= __t('docs_title') ?></button>
              <button class="doc-tab"        onclick="switchTab(this,'inc-docs')"><?= __t('nav_incoming_docs') ?></button>
            </div>
            <div id="my-docs" class="tab-panel">
              <?php if (empty($myDocs)): ?>
              <div class="empty-state">
                <svg viewBox="0 0 64 64" fill="none"><rect x="10" y="6" width="34" height="44" rx="3" stroke="#cbd5e1" stroke-width="2"/><path d="M10 18h34" stroke="#cbd5e1" stroke-width="1.5"/></svg>
                <p><?= __t('msg_no_data') ?></p>
              </div>
              <?php else: ?>
              <table class="doc-table">
                <thead><tr><th><?= __t('table_name') ?></th><th><?= __t('table_date') ?></th><th><?= __t('table_status') ?></th></tr></thead>
                <tbody>
                <?php foreach ($myDocs as $d): ?>
                <tr style="cursor:pointer" onclick="location.href='<?= SITE_URL ?>/document-view.php?id=<?= (int)$d['id'] ?>'">
                  <td data-label="<?= __t('table_name') ?>"><?= htmlspecialchars($d['type_name'] ?? '—') ?></td>
                  <td data-label="<?= __t('table_date') ?>"><?= !empty($d['display_date']) ? date('d.m.Y', strtotime($d['display_date'])) : '—' ?></td>
                  <td data-label="<?= __t('table_status') ?>"><?= statusBadge($d['status']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </div>
            <div id="inc-docs" class="tab-panel" style="display:none">
              <?php if (empty($incomingDocs)): ?>
              <div class="empty-state">
                <svg viewBox="0 0 64 64" fill="none"><rect x="10" y="6" width="34" height="44" rx="3" stroke="#cbd5e1" stroke-width="2"/><path d="M10 18h34" stroke="#cbd5e1" stroke-width="1.5"/></svg>
                <p><?= __t('msg_no_data') ?></p>
              </div>
              <?php else: ?>
              <table class="doc-table">
                <thead><tr><th><?= __t('table_name') ?></th><th><?= __t('table_date') ?></th><th><?= __t('table_status') ?></th></tr></thead>
                <tbody>
                <?php foreach ($incomingDocs as $d): ?>
                <tr>
                  <td data-label="<?= __t('table_name') ?>"><?= htmlspecialchars($d['subject'] ?? __t('nav_incoming_docs')) ?></td>
                  <td data-label="<?= __t('table_date') ?>"><?= !empty($d['received_at']) ? date('d.m.Y', strtotime($d['received_at'])) : '—' ?></td>
                  <td data-label="<?= __t('table_status') ?>"><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#dbeafe;color:#1e40af">Новый</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- СООБЩЕНИЯ -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
              </svg>
              <?= __t('messages_title') ?>
              <?php if ($unreadCount > 0): ?>
              <span class="badge badge-red" style="padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700"><?= $unreadCount ?></span>
              <?php endif; ?>
            </span>
            <a href="<?= SITE_URL ?>/messages.php" class="card-link">
              <?= __t('btn_see_all') ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          </div>
          <div class="empty-state">
            <svg viewBox="0 0 64 64" fill="none"><path d="M54 14a2 2 0 0 0-2-2H12a2 2 0 0 0-2 2v28a2 2 0 0 0 2 2h7l-3 8 12-8h24a2 2 0 0 0 2-2V14z" stroke="#cbd5e1" stroke-width="2"/></svg>
            <p><?= __t('msg_no_data') ?></p>
          </div>
        </div>

      </div>

      <div class="dashboard-right">

        <!-- ПРОФИЛЬ -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
              </svg>
              <?= __t('profile_title') ?>
            </span>
            <a href="<?= SITE_URL ?>/profile.php" class="card-link">
              <?= __t('btn_go_to') ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          </div>
          <div class="profile-mini">
            <div class="profile-mini-avatar">
              <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div>
              <div class="profile-mini-type"><?= __t('type_individual') ?></div>
              <div class="profile-mini-name"><?= htmlspecialchars(strtoupper($user['full_name'] ?? '')) ?></div>
            </div>
          </div>
        </div>

        <!-- КАЛЕНДАРЬ -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
              </svg>
              <?= __t('calendar_title') ?>
            </span>
            <a href="#" class="card-link" onclick="openCalendarModal(event)"><?= __t('all_events') ?></a>
          </div>
          <div class="calendar-list">
            <?php
            foreach ($calendarEvents as $event):
                $ts = strtotime($event['date']);
                $day = date('d', $ts);
                $month = __t('month_' . (int)date('m', $ts));
                $isUrgent = ($ts - time() < 86400 * 7);
            ?>
            <div class="calendar-item <?= $isUrgent ? 'calendar-urgent' : '' ?>">
              <div class="calendar-date">
                <span class="calendar-date-day"><?= $day ?></span>
                <span class="calendar-date-month"><?= $month ?></span>
              </div>
              <div class="calendar-info">
                <div class="calendar-name"><?= htmlspecialchars($event['name']) ?></div>
                <div class="calendar-code">Форма <?= htmlspecialchars($event['code']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- ПОДАТЬ ДОКУМЕНТ -->
        <div class="card">
          <div class="card-slider-header">
            <span class="card-title">
              <svg style="color:#e07b1a" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/>
              </svg>
              <?= __t('btn_submit_doc') ?>
            </span>
            <div style="display:flex;align-items:center;gap:10px">
              <a href="<?= SITE_URL ?>/submit.php" class="card-link"><?= __t('btn_see_all') ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></a>
              <div class="slider-nav">
                <button class="slider-btn" onclick="slideDocs(-1)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></button>
                <button class="slider-btn" onclick="slideDocs(1)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></button>
              </div>
            </div>
          </div>
          <?php if (empty($docForms)): ?>
          <div class="empty-state" style="padding:24px"><p><?= __t('msg_no_data') ?></p></div>
            <?php else: ?>
          <div class="submit-doc-grid">
            <?php foreach ($docForms as $f): ?>
            <div class="submit-doc-item">
              <div class="submit-doc-type"><?= __t('nav_tax_reports') ?></div>
              <div class="submit-doc-name"><?= htmlspecialchars($f['code'] . ' ' . mb_substr($f['name'], 0, 48) . (mb_strlen($f['name']) > 48 ? '...' : '')) ?></div>
              <a href="<?= SITE_URL ?>/submit.php?type=<?= (int)$f['id'] ?>" class="submit-doc-action"><?= __t('btn_submit_doc') ?></a>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="slider-dots">
            <div class="slider-dot active"></div><div class="slider-dot"></div>
            <div class="slider-dot"></div><div class="slider-dot"></div>
          </div>
          <?php endif; ?>
        </div>

        <!-- ПРЕДСТАВИТЕЛИ -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">
              <svg style="color:#7c3aed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
              <?= __t('representatives_title') ?>
            </span>
            <a href="<?= SITE_URL ?>/representatives.php" class="card-link"><?= __t('btn_see_all') ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></a>
          </div>
          <div class="empty-state">
            <svg viewBox="0 0 64 64" fill="none"><path d="M40 52v-4a10 10 0 0 0-10-10H14a10 10 0 0 0-10 10v4" stroke="#cbd5e1" stroke-width="2"/><circle cx="22" cy="20" r="9" stroke="#cbd5e1" stroke-width="2"/></svg>
            <p><?= __t('msg_no_data') ?></p>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<!-- МОДАЛКА КАЛЕНДАРЯ -->
<div id="calendarModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:28px 32px;width:560px;max-width:95vw;max-height:85vh;position:relative;box-shadow:0 8px 32px rgba(0,0,0,.18);display:flex;flex-direction:column">
    <button onclick="closeCalendarModal()" style="position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:50%;border:1px solid #e2e6ea;background:#fff;color:#6b7280;font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer">×</button>

    <h3 style="font-size:16px;font-weight:700;color:#1a1a2e;margin-bottom:20px;padding-right:24px"><?= __t('calendar_title') ?></h3>
    
    <div style="overflow-y:auto;flex:1;padding-right:8px">
      <?php foreach ($taxCalendar as $event): 
          $ts = strtotime($event['date']);
          $day = date('d', $ts);
          $month = __t('month_' . (int)date('m', $ts));
          $isUrgent = ($ts - time() < 86400 * 7 && $ts > time());
      ?>
      <div class="calendar-item <?= $isUrgent ? 'calendar-urgent' : '' ?>" style="border-bottom:1px solid #f3f4f6;padding:12px 0">
        <div class="calendar-date" style="background:#f0f4ff;border:1px solid #e0eaff">
          <span class="calendar-date-day" style="color:#1a3c6e"><?= $day ?></span>
          <span class="calendar-date-month" style="color:#1a5fa8"><?= $month ?></span>
        </div>
        <div class="calendar-info">
          <div class="calendar-name" style="font-size:13px;font-weight:600"><?= htmlspecialchars($event['name']) ?></div>
          <div class="calendar-code" style="font-size:11px;color:#9ca3af">Форма <?= htmlspecialchars($event['code']) ?> · <?= date('d.m.Y', $ts) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
const currentLang = '<?= $currentLang ?>';
function openCalendarModal(e) {
    if (e) e.preventDefault();
    document.getElementById('calendarModal').style.display = 'flex';
}
function closeCalendarModal() {
    document.getElementById('calendarModal').style.display = 'none';
}
document.getElementById('calendarModal').addEventListener('click', function(e) {
    if (e.target === this) closeCalendarModal();
});

function switchTab(btn, id) {
    document.querySelectorAll('.doc-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
    btn.classList.add('active');
    document.getElementById(id).style.display = 'block';
}
function slideDocs(dir) {
    const dots = document.querySelectorAll('.slider-dot');
    let cur = [...dots].findIndex(d => d.classList.contains('active'));
    dots[cur].classList.remove('active');
    cur = (cur + dir + dots.length) % dots.length;
    dots[cur].classList.add('active');
}
</script>