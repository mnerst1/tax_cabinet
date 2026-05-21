<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

// ── Автозаполнение тестовыми уведомлениями при первом визите ──
try {
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
    $cntStmt->execute([$userId]);
    $hasAny = (int)$cntStmt->fetchColumn();
    if ($hasAny === 0) {
        $seed = [
            ['security','🔐 Вход с нового устройства','Выполнен вход в ваш кабинет с нового устройства: Windows 11 / Chrome 124. IP-адрес: 95.56.180.12 (Алматы). Если это были не вы — немедленно смените пароль.',SITE_URL.'/profile.php','-5 minutes',false],
            ['security','Пароль успешно изменён','Пароль от вашего кабинета был изменён 14.05.2026 в 10:43. Если вы не выполняли это действие, обратитесь в службу поддержки.',null,'-2 days',true],
            ['security','ЭЦП выпущен и привязан','Новый сертификат ЭЦП успешно создан и привязан к вашей учётной записи. Срок действия: 1 год. Храните файл .p12 в надёжном месте.',SITE_URL.'/ecp.php','-3 days',true],
            ['document_submitted','Декларация 240.00 подана','Ваша декларация по индивидуальному подоходному налогу (форма 240.00) за 2025 год принята системой и направлена на проверку.',SITE_URL.'/documents.php','-1 hour',false],
            ['document_accepted','Декларация 910.00 принята','Декларация по упрощённому налогу (форма 910.00) за I полугодие 2025 года принята органом государственных доходов. Начислений не выявлено.',SITE_URL.'/documents.php','-1 day',false],
            ['document_rejected','Декларация 200.00 требует исправления','Декларация по форме 200.00 за II квартал 2025 года отклонена: обнаружено расхождение в разделе «ОПВ». Подайте корректирующую декларацию.',SITE_URL.'/documents.php','-2 days',false],
            ['document_review','Декларация 270.00 на проверке','Декларация по имущественному доходу (форма 270.00) передана на камеральный контроль. Срок рассмотрения — до 30 рабочих дней.',SITE_URL.'/documents.php','-4 hours',true],
            ['deadline','⏰ Срок подачи декларации — 31 марта','Напоминаем: срок подачи декларации по ИПН (форма 240.00) за 2025 год истекает 31 марта 2026 года. Просрочка влечёт штраф до 50 МРП.',SITE_URL.'/submit.php','-6 hours',false],
            ['deadline','Напоминание: уплата налога до 10 апреля','Согласно поданной декларации 910.00, сумма налога к уплате составляет 24 600 ₸. Срок уплаты — 10 апреля 2026 года.',null,'-5 days',true],
            ['payment','Налог 24 600 ₸ уплачен','Платёж на сумму 24 600 ₸ по КБК 101202 успешно зачислен в бюджет. Квитанция доступна в разделе «Документы».',SITE_URL.'/documents.php','-3 days',true],
            ['payment','Переплата 1 840 ₸ — возврат возможен','По итогам сверки лицевого счёта выявлена переплата в размере 1 840 ₸. Вы можете подать заявление на возврат через раздел «Лицевой счёт».',SITE_URL.'/account.php','-6 days',false],
            ['system','🆕 Обновление системы v4.2 — новые возможности','Добавлены: автозаполнение формы 240.00 по данным работодателя, экспорт деклараций в PDF, история входов и уведомления о новых устройствах.',null,'-7 days',false],
            ['system','Плановые технические работы 20 мая','20 мая 2026 года с 01:00 до 05:00 (UTC+5) система будет недоступна в связи с плановым обновлением серверного оборудования КГД МФ РК.',null,'-1 day',false],
            ['system','Новые налоговые ставки с 01.01.2026','С 1 января 2026 года вступили в силу изменения в Налоговый кодекс РК. Обновлены ставки КПН, ИПН и ставки социальных отчислений.',SITE_URL.'/help.php','-10 days',true],
            ['system','Интеграция с eGov.kz запущена','Данные о доходах от работодателей автоматически загружаются из базы eGov при заполнении формы 240.00.',SITE_URL.'/submit.php','-14 days',true],
            ['system','Расширен раздел «Помощь»','Добавлены видеоинструкции по заполнению форм 240.00, 910.00 и 700.00, а также FAQ с ответами на 50 наиболее частых вопросов.',SITE_URL.'/help.php','-20 days',true],
        ];
        $ins = $db->prepare("INSERT INTO notifications (user_id,type,title,message,link,is_read,read_at,created_at) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($seed as [$type,$title,$msg,$link,$ago,$read]) {
            $ins->execute([
                $userId, $type, $title, $msg, $link,
                $read ? 1 : 0,
                $read ? date('Y-m-d H:i:s', strtotime($ago.' +10 minutes')) : null,
                date('Y-m-d H:i:s', strtotime($ago)),
            ]);
        }
    }
} catch (Exception $e) { /* не критично */ }

// ── AJAX-обработчики ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($id === -1) {
                // Пометить все прочитанными
                $db->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=? AND is_read=0")
                   ->execute([$userId]);
            } else {
                $db->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=? AND user_id=?")
                   ->execute([$id, $userId]);
            }
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($id === -1) {
                $db->prepare("DELETE FROM notifications WHERE user_id=? AND is_read=1")
                   ->execute([$userId]);
            } else {
                $db->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")
                   ->execute([$id, $userId]);
            }
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}

// ── Фильтр ────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all'; // all | unread | read
$type   = $_GET['type']   ?? '';

// ── Пагинация ─────────────────────────────────────────────────
$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// ── Запрос уведомлений ────────────────────────────────────────
$where   = ['n.user_id = ?'];
$params  = [$userId];

if ($filter === 'unread') { $where[] = 'n.is_read = 0'; }
if ($filter === 'read')   { $where[] = 'n.is_read = 1'; }
if ($type)                { $where[] = 'n.type = ?'; $params[] = $type; }

$whereSQL = implode(' AND ', $where);

try {
    // Общее кол-во
    $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications n WHERE $whereSQL");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    // Данные
    $stmt = $db->prepare("
        SELECT n.*
        FROM notifications n
        WHERE $whereSQL
        ORDER BY n.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
} catch (Exception $e) {
    $notifications = [];
    $totalCount    = 0;
}

$totalPages  = max(1, ceil($totalCount / $perPage));

// ── Счётчики по фильтрам ──────────────────────────────────────
try {
    $s = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(is_read = 0) AS unread,
            SUM(is_read = 1) AS read_count
        FROM notifications WHERE user_id = ?
    ");
    $s->execute([$userId]);
    $counts = $s->fetch();
} catch (Exception $e) {
    $counts = ['total' => 0, 'unread' => 0, 'read_count' => 0];
}

// ── Типы уведомлений ──────────────────────────────────────────
try {
    $s = $db->prepare("SELECT DISTINCT type FROM notifications WHERE user_id=? AND type IS NOT NULL");
    $s->execute([$userId]);
    $notifTypes = $s->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $notifTypes = [];
}

$unreadCount = (int)($counts['unread'] ?? 0);
$pageTitle   = __t('nav_notifications') . ' — ' . SITE_NAME;

// Иконки и цвета по типу
function notifMeta(string $type): array {
    return match($type) {
        'document_accepted'  => ['icon'=>'✅','color'=>'#059669','bg'=>'#d1fae5','label'=>__t('status_accepted')],
        'document_rejected'  => ['icon'=>'❌','color'=>'#dc2626','bg'=>'#fee2e2','label'=>__t('status_rejected')],
        'document_submitted' => ['icon'=>'📤','color'=>'#1d4ed8','bg'=>'#dbeafe','label'=>__t('status_submitted')],
        'document_review'    => ['icon'=>'🔍','color'=>'#92400e','bg'=>'#fef3c7','label'=>__t('status_review')],
        'deadline'           => ['icon'=>'⏰','color'=>'#c2410c','bg'=>'#fff7ed','label'=>__t('table_date')],
        'system'             => ['icon'=>'⚙️','color'=>'#6b7280','bg'=>'#f3f4f6','label'=>'Система'],
        'payment'            => ['icon'=>'💳','color'=>'#7c3aed','bg'=>'#ede9fe','label'=>'Оплата'],
        'security'           => ['icon'=>'🔐','color'=>'#b45309','bg'=>'#fef3c7','label'=>__t('auth_step_security')],
        default              => ['icon'=>'🔔','color'=>'#1a5fa8','bg'=>'#dbeafe','label'=>__t('nav_notifications')],
    };
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
/* ── Layout ───────────────────────────────────────────────── */
.notif-layout{display:grid;grid-template-columns:240px 1fr;gap:20px;align-items:start}

/* ── Sidebar ──────────────────────────────────────────────── */
.notif-sidebar{background:#fff;border:1px solid #e2e6ea;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);position:sticky;top:80px}
.notif-sidebar-title{padding:14px 18px;font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #f0f2f5}
.notif-filter-item{display:flex;align-items:center;justify-content:space-between;padding:11px 18px;font-size:13px;font-weight:500;color:#374151;cursor:pointer;text-decoration:none;border-left:3px solid transparent;transition:background .15s,border-color .15s}
.notif-filter-item:hover{background:#f9fafb}
.notif-filter-item.active{background:#f0f4ff;color:#1a3c6e;font-weight:600;border-left-color:#1a3c6e}
.notif-filter-left{display:flex;align-items:center;gap:8px}
.notif-filter-left svg{width:15px;height:15px}
.count-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;background:#f3f4f6;color:#6b7280}
.count-badge.unread{background:#fee2e2;color:#dc2626}

.type-divider{padding:10px 18px 6px;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;border-top:1px solid #f0f2f5;margin-top:4px}

/* ── Main area ────────────────────────────────────────────── */
.notif-main{}
.notif-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px}
.notif-toolbar-left{display:flex;align-items:center;gap:8px}
.notif-toolbar-right{display:flex;align-items:center;gap:8px}
.btn-toolbar{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:background .15s}
.btn-mark-all{background:#fff;color:#1a3c6e;border-color:#1a3c6e}
.btn-mark-all:hover{background:#f0f4ff}
.btn-del-read{background:#fff;color:#dc2626;border-color:#fca5a5}
.btn-del-read:hover{background:#fff7f7}

/* ── Notification Card ────────────────────────────────────── */
.notif-list{display:flex;flex-direction:column;gap:0}
.notif-card{display:flex;align-items:flex-start;gap:14px;padding:16px 20px;background:#fff;border:1px solid #e2e6ea;border-radius:0;border-bottom:none;position:relative;transition:background .15s;cursor:pointer}
.notif-card:first-child{border-radius:10px 10px 0 0}
.notif-card:last-child{border-radius:0 0 10px 10px;border-bottom:1px solid #e2e6ea}
.notif-card:only-child{border-radius:10px;border-bottom:1px solid #e2e6ea}
.notif-card:hover{background:#fafbff}
.notif-card.unread{background:#f8faff;border-left:3px solid #1a5fa8}
.notif-card.unread:hover{background:#f0f4ff}

/* Левый индикатор непрочитанного */
.notif-unread-dot{width:8px;height:8px;border-radius:50%;background:#1a5fa8;flex-shrink:0;margin-top:6px}
.notif-unread-dot.hidden{visibility:hidden}

/* Иконка типа */
.notif-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}

/* Контент */
.notif-content{flex:1;min-width:0}
.notif-header{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.notif-title{font-size:13px;font-weight:600;color:#1a1a2e;line-height:1.4}
.notif-card.unread .notif-title{color:#1a3c6e}
.notif-time{font-size:11px;color:#9ca3af;white-space:nowrap;flex-shrink:0}
.notif-body{font-size:12px;color:#6b7280;margin-top:4px;line-height:1.5}
.notif-footer{display:flex;align-items:center;gap:8px;margin-top:8px}
.notif-type-badge{font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px}
.notif-link{font-size:12px;color:#1a5fa8;text-decoration:none;font-weight:500}
.notif-link:hover{text-decoration:underline}

/* Действия при ховере */
.notif-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;opacity:0;transition:opacity .15s}
.notif-card:hover .notif-actions{opacity:1}
.notif-action-btn{width:28px;height:28px;border-radius:6px;border:1px solid #e2e6ea;background:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;transition:background .15s}
.notif-action-btn:hover{background:#f3f4f6}
.notif-action-btn.del:hover{background:#fee2e2;border-color:#fca5a5}

/* ── Empty ────────────────────────────────────────────────── */
.notif-empty{background:#fff;border:1px solid #e2e6ea;border-radius:10px;padding:60px 20px;text-align:center}
.notif-empty-icon{font-size:48px;margin-bottom:14px}
.notif-empty-title{font-size:16px;font-weight:700;color:#1a1a2e;margin-bottom:6px}
.notif-empty-sub{font-size:13px;color:#9ca3af}

/* ── Pagination ───────────────────────────────────────────── */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px}
.page-btn{min-width:34px;height:34px;border-radius:7px;border:1px solid #e2e6ea;background:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:500;color:#374151;cursor:pointer;text-decoration:none;transition:background .15s}
.page-btn:hover{background:#f3f4f6}
.page-btn.active{background:#1a3c6e;color:#fff;border-color:#1a3c6e}
.page-btn.disabled{opacity:.4;pointer-events:none}

/* ── Toast ────────────────────────────────────────────────── */
.toast{position:fixed;bottom:24px;right:24px;background:#1a1a2e;color:#fff;padding:12px 18px;border-radius:8px;font-size:13px;font-weight:500;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2);transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
.toast.show{transform:translateY(0);opacity:1}

/* ── Responsive ───────────────────────────────────────────── */
@media(max-width:768px){
  .notif-layout{grid-template-columns:1fr}
  .notif-sidebar{position:static;display:flex;overflow-x:auto;border-radius:10px}
  .notif-sidebar-title,.type-divider{display:none}
  .notif-filter-item{padding:10px 14px;border-left:none;border-bottom:3px solid transparent;white-space:nowrap}
  .notif-filter-item.active{border-bottom-color:#1a3c6e;border-left:none;background:transparent}
  .notif-actions{opacity:1}
}
</style>

<div class="page-wrapper">
  <div class="container">

    <div class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard.php"><?= __t('nav_main') ?></a>
      <span>—</span>
      <span><?= __t('nav_notifications') ?></span>
    </div>

    <div class="page-heading">
      <h1><?= __t('nav_notifications') ?>
        <?php if ($unreadCount > 0): ?>
          <span style="display:inline-flex;align-items:center;justify-content:center;background:#dc2626;color:#fff;font-size:13px;font-weight:700;width:26px;height:26px;border-radius:50%;margin-left:8px;vertical-align:middle"><?= $unreadCount ?></span>
        <?php endif; ?>
      </h1>
      <p>Все системные оповещения по вашим документам и задолженностям</p>
    </div>

    <div class="notif-layout">

      <!-- ── САЙДБАР ─────────────────────────────────────── -->
      <aside class="notif-sidebar">
        <div class="notif-sidebar-title"><?= __t('nav_notifications') ?></div>

        <a href="?filter=all<?= $type ? '&type='.$type : '' ?>"
           class="notif-filter-item <?= $filter==='all' ? 'active' : '' ?>">
          <span class="notif-filter-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <?= __t('filter_all') ?>
          </span>
          <span class="count-badge"><?= (int)$counts['total'] ?></span>
        </a>

        <a href="?filter=unread" class="notif-filter-item <?= $filter==='unread' ? 'active' : '' ?>">
          <span class="notif-filter-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= __t('notif_unread') ?>
          </span>
          <span class="count-badge unread"><?= (int)$counts['unread'] ?></span>
        </a>

        <a href="?filter=read<?= $type ? '&type='.$type : '' ?>"
           class="notif-filter-item <?= $filter==='read' ? 'active' : '' ?>">
          <span class="notif-filter-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            <?= __t('notif_read') ?>
          </span>
          <span class="count-badge"><?= (int)$counts['read_count'] ?></span>
        </a>

        <div class="type-divider"><?= __t('notif_types') ?></div>
        <?php foreach ($notifTypes as $t): ?>
          <?php $m = notifMeta($t); ?>
          <a href="?filter=<?= $filter ?>&type=<?= urlencode($t) ?>"
             class="notif-filter-item <?= $type===$t ? 'active' : '' ?>">
            <span class="notif-filter-left">
              <span><?= $m['icon'] ?></span>
              <?= htmlspecialchars($m['label']) ?>
            </span>
          </a>
        <?php endforeach; ?>
        <?php if ($type): ?>
          <a href="?filter=<?= $filter ?>" class="notif-filter-item" style="font-size:12px;color:#9ca3af">
            <span class="notif-filter-left">✕ Сбросить тип</span>
          </a>
        <?php endif; ?>
      </aside>

      <!-- ── ОСНОВНАЯ ЧАСТЬ ──────────────────────────────── -->
      <main class="notif-main">

        <!-- Тулбар -->
        <div class="notif-toolbar">
          <div class="notif-toolbar-left">
            <span style="font-size:13px;color:#6b7280">
              <?= $totalCount ?> уведомлений
              <?php if ($filter === 'unread' && $unreadCount > 0): ?>
                · <strong style="color:#dc2626"><?= $unreadCount ?> непрочитанных</strong>
              <?php endif; ?>
            </span>
          </div>
          <div class="notif-toolbar-right">
            <?php if ($unreadCount > 0): ?>
            <button class="btn-toolbar btn-mark-all" id="btnMarkAll">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              Прочитать все
            </button>
            <?php endif; ?>
            <?php if ((int)$counts['read_count'] > 0): ?>
            <button class="btn-toolbar btn-del-read" id="btnDelRead">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              Удалить прочитанные
            </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Список уведомлений -->
        <div class="notif-list" id="notifList">
          <?php if ($notifications): ?>
            <?php foreach ($notifications as $n): ?>
            <?php
              $meta    = notifMeta($n['type'] ?? 'default');
              $isUnread= !(int)$n['is_read'];
              $created = strtotime($n['created_at']);
              $now     = time();
              $diff    = $now - $created;

              // Человекочитаемое время
              if ($diff < 60)           $timeAgo = 'только что';
              elseif ($diff < 3600)     $timeAgo = floor($diff/60) . ' мин назад';
              elseif ($diff < 86400)    $timeAgo = floor($diff/3600) . ' ч назад';
              elseif ($diff < 604800)   $timeAgo = floor($diff/86400) . ' дн назад';
              else                      $timeAgo = date('d.m.Y', $created);
            ?>
            <div class="notif-card <?= $isUnread ? 'unread' : '' ?>"
                 id="notif-<?= $n['id'] ?>"
                 data-id="<?= $n['id'] ?>"
                 onclick="markRead(<?= $n['id'] ?>, this)">

              <div class="notif-unread-dot <?= $isUnread ? '' : 'hidden' ?>" id="dot-<?= $n['id'] ?>"></div>

              <div class="notif-icon" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>">
                <?= $meta['icon'] ?>
              </div>

              <div class="notif-content">
                <div class="notif-header">
                  <div class="notif-title"><?= htmlspecialchars($n['title'] ?? 'Уведомление') ?></div>
                  <div style="display:flex;align-items:center;gap:8px">
                    <span class="notif-time"><?= $timeAgo ?></span>
                    <div class="notif-actions" onclick="event.stopPropagation()">
                      <?php if ($isUnread): ?>
                      <button class="notif-action-btn" title="Отметить прочитанным"
                              onclick="markRead(<?= $n['id'] ?>, document.getElementById('notif-<?= $n['id'] ?>'))">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                      </button>
                      <?php endif; ?>
                      <button class="notif-action-btn del" title="Удалить"
                              onclick="deleteNotif(<?= $n['id'] ?>, document.getElementById('notif-<?= $n['id'] ?>'))">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                      </button>
                    </div>
                  </div>
                </div>

                <div class="notif-body"><?= htmlspecialchars($n['message'] ?? '') ?></div>

                <div class="notif-footer">
                  <span class="notif-type-badge"
                        style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>">
                    <?= $meta['label'] ?>
                  </span>
                  <?php if (!empty($n['link'])): ?>
                    <a href="<?= htmlspecialchars($n['link']) ?>" class="notif-link"
                       onclick="event.stopPropagation()">Перейти →</a>
                  <?php endif; ?>
                  <?php if (!empty($n['read_at']) && !$isUnread): ?>
                    <span style="font-size:11px;color:#d1d5db">
                      Прочитано <?= date('d.m.Y H:i', strtotime($n['read_at'])) ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="notif-empty">
              <div class="notif-empty-icon">
                <?= $filter === 'unread' ? '✅' : '🔔' ?>
              </div>
              <div class="notif-empty-title">
                <?= $filter === 'unread' ? 'Нет непрочитанных уведомлений' : 'Уведомлений нет' ?>
              </div>
              <div class="notif-empty-sub">
                <?= $filter === 'unread' ? 'Вы всё прочитали — отлично!' : 'Здесь будут появляться оповещения по вашим документам.' ?>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Пагинация -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php
          $baseUrl = '?filter=' . $filter . ($type ? '&type='.urlencode($type) : '');
          ?>
          <a href="<?= $baseUrl ?>&page=<?= max(1,$page-1) ?>"
             class="page-btn <?= $page<=1 ? 'disabled' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          </a>
          <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
          <a href="<?= $baseUrl ?>&page=<?= $p ?>"
             class="page-btn <?= $p===$page ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>
          <a href="<?= $baseUrl ?>&page=<?= min($totalPages,$page+1) ?>"
             class="page-btn <?= $page>=$totalPages ? 'disabled' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        </div>
        <?php endif; ?>

      </main>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
function showToast(msg, ok = true) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = ok ? '#1a1a2e' : '#dc2626';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}

function ajax(data, cb) {
    fetch(location.pathname, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams(data)
    })
    .then(r => r.json())
    .then(cb)
    .catch(() => showToast('Ошибка соединения', false));
}

// Пометить одно как прочитанное
function markRead(id, card) {
    if (!card.classList.contains('unread')) return;
    ajax({ action: 'mark_read', id }, res => {
        if (res.ok) {
            card.classList.remove('unread');
            const dot = document.getElementById('dot-' + id);
            if (dot) dot.classList.add('hidden');
            // Убираем кнопку "прочитать"
            card.querySelectorAll('.notif-action-btn:not(.del)').forEach(b => b.remove());
            updateUnreadBadge(-1);
        }
    });
}

// Удалить одно
function deleteNotif(id, card) {
    ajax({ action: 'delete', id }, res => {
        if (res.ok) {
            const wasUnread = card.classList.contains('unread');
            card.style.transition = 'opacity .25s, max-height .3s, padding .3s, margin .3s';
            card.style.overflow   = 'hidden';
            card.style.opacity    = '0';
            card.style.maxHeight  = card.offsetHeight + 'px';
            requestAnimationFrame(() => {
                card.style.maxHeight = '0';
                card.style.padding   = '0';
                card.style.margin    = '0';
                card.style.border    = 'none';
            });
            setTimeout(() => {
                card.remove();
                if (wasUnread) updateUnreadBadge(-1);
                checkEmpty();
            }, 320);
        }
    });
}

// Прочитать все
document.getElementById('btnMarkAll')?.addEventListener('click', () => {
    ajax({ action: 'mark_read', id: -1 }, res => {
        if (res.ok) {
            document.querySelectorAll('.notif-card.unread').forEach(c => {
                c.classList.remove('unread');
                c.querySelectorAll('.notif-unread-dot').forEach(d => d.classList.add('hidden'));
                c.querySelectorAll('.notif-action-btn:not(.del)').forEach(b => b.remove());
            });
            updateUnreadBadge(0, true);
            document.getElementById('btnMarkAll')?.remove();
            showToast('Все уведомления отмечены как прочитанные');
        }
    });
});

// Удалить прочитанные
document.getElementById('btnDelRead')?.addEventListener('click', () => {
    if (!confirm('Удалить все прочитанные уведомления?')) return;
    ajax({ action: 'delete', id: -1 }, res => {
        if (res.ok) {
            document.querySelectorAll('.notif-card:not(.unread)').forEach(c => c.remove());
            document.getElementById('btnDelRead')?.remove();
            checkEmpty();
            showToast('Прочитанные уведомления удалены');
        }
    });
});

// Обновить счётчик в шапке
function updateUnreadBadge(delta, reset = false) {
    const badge = document.querySelector('.notif-header-badge, [data-unread-badge]');
    if (!badge) return;
    let val = reset ? 0 : Math.max(0, (parseInt(badge.textContent) || 0) + delta);
    badge.textContent = val;
    badge.style.display = val > 0 ? '' : 'none';
}

// Показать empty state если список пустой
function checkEmpty() {
    const list = document.getElementById('notifList');
    if (list && list.querySelectorAll('.notif-card').length === 0) {
        list.innerHTML = `
            <div class="notif-empty">
                <div class="notif-empty-icon">✅</div>
                <div class="notif-empty-title">Уведомлений нет</div>
                <div class="notif-empty-sub">Список пуст.</div>
            </div>`;
    }
}
</script>
