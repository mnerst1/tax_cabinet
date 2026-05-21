<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$unreadCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $s->execute([$userId]);
    $unreadCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── История обращений пользователя ───────────────────────────
try {
    $stmt = $db->prepare("
        SELECT * FROM feedback
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $history = $stmt->fetchAll();
} catch (Exception $e) { $history = []; }

$success = $error = '';

// ── Обработка отправки формы ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = __t('msg_csrf_error');
    } else {
        $topic   = trim($_POST['topic']   ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $urgency = trim($_POST['urgency'] ?? 'normal');

    if (!$topic || !$subject || !$message) {
        $error = __t('fb_msg_fill_all');
    } elseif (mb_strlen($message) < 20) {
        $error = __t('fb_msg_too_short');
    } else {
        try {
            $db->prepare("
                INSERT INTO feedback
                    (user_id, topic, subject, message, urgency, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'new', NOW())
            ")->execute([$userId, $topic, $subject, $message, $urgency]);

            $ticketId = $db->lastInsertId();

            // ── Отправка письма администратору ──────────────────────
            $adminEmail = 'mnerst1@gmail.com';
            $mailSubject = "Новое обращение #$ticketId: $subject";
            $mailBody = "Новое обращение в Кабинет налогоплательщика\n\n" .
                        "ID тикета: #$ticketId\n" .
                        "Тема: " . ($topics[$topic] ?? $topic) . "\n" .
                        "Предмет: $subject\n" .
                        "Срочность: $urgency\n" .
                        "Отправитель: " . ($user['full_name'] ?? 'Unknown') . " (ИИН: " . ($user['iin'] ?? '—') . ")\n" .
                        "Email отправителя: " . ($user['email'] ?? '—') . "\n\n" .
                        "Сообщение:\n$message\n\n" .
                        "--- \nДата: " . date('d.m.Y H:i:s');
            
            $headers = "From: no-reply@tax-cabinet.kz\r\n" .
                       "Reply-To: " . ($user['email'] ?? 'no-reply@tax-cabinet.kz') . "\r\n" .
                       "X-Mailer: PHP/" . phpversion();

            @mail($adminEmail, $mailSubject, $mailBody, $headers);

            // Уведомление пользователю
            createNotification(
                $userId,
                'system',
                sprintf(__t('fb_msg_success'), $ticketId),
                sprintf(__t('fb_msg_registered'), mb_strimwidth($subject, 0, 60, '…')),
            );

            $success = $ticketId;

            // Обновить историю
            $stmt = $db->prepare("SELECT * FROM feedback WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$userId]);
            $history = $stmt->fetchAll();

        } catch (Exception $e) {
            $error = __t('fb_msg_error');
        }
    }
    }
}

$topics = [
    'technical'  => '🔧 ' . __t('fb_topic_tech'),
    'documents'  => '📄 ' . __t('fb_topic_docs'),
    'account'    => '👤 ' . __t('fb_topic_acc'),
    'payment'    => '💳 ' . __t('fb_topic_pay'),
    'suggestion' => '💡 ' . __t('fb_topic_sug'),
    'other'      => '💬 ' . __t('fb_topic_other'),
];

$statusLabels = [
    'new'         => ['label' => __t('status_submitted'), 'class' => 'status-new'],
    'in_progress' => ['label' => __t('status_review'),    'class' => 'status-progress'],
    'answered'    => ['label' => __t('status_accepted'),  'class' => 'status-answered'],
    'closed'      => ['label' => __t('btn_close'),        'class' => 'status-closed'],
];

$pageTitle = __t('fb_title') . ' — ' . SITE_NAME;
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
/* ── Layout ───────────────────────────────────────────────── */
.fb-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 24px;
    align-items: start;
}

/* ── Form card ────────────────────────────────────────────── */
.fb-card {
    background: #fff;
    border: 1px solid #e2e6ea;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
}
.fb-card-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid #f0f2f5;
    display: flex;
    align-items: center;
    gap: 12px;
}
.fb-card-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #eef3fb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.fb-card-title { font-size: 15px; font-weight: 700; color: #1a1a2e; }
.fb-card-sub   { font-size: 12px; color: #9ca3af; margin-top: 2px; }
.fb-card-body  { padding: 24px; }

/* ── Form elements ────────────────────────────────────────── */
.form-group { margin-bottom: 18px; }
.form-group:last-child { margin-bottom: 0; }
.form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 7px;
}
.form-label .req { color: #ef4444; margin-left: 2px; }
.form-label .hint { font-size: 11px; color: #9ca3af; font-weight: 400; margin-left: 6px; }

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e2e6ea;
    border-radius: 8px;
    font-size: 13px;
    color: #1a1a2e;
    background: #fff;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    font-family: inherit;
    resize: vertical;
}
.form-control:focus {
    border-color: #1a5fa8;
    box-shadow: 0 0 0 3px rgba(26,95,168,.1);
}
.form-control.error { border-color: #ef4444; }

/* Тема — кнопки выбора */
.topic-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.topic-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    padding: 12px 8px;
    border: 1.5px solid #e2e6ea;
    border-radius: 9px;
    cursor: pointer;
    font-size: 11px;
    font-weight: 600;
    color: #6b7280;
    background: #fff;
    text-align: center;
    transition: border-color .15s, background .15s, color .15s;
    line-height: 1.3;
}
.topic-btn:hover { border-color: #1a5fa8; color: #1a3c6e; background: #f0f4ff; }
.topic-btn.selected { border-color: #1a3c6e; background: #eef3fb; color: #1a3c6e; font-weight: 700; }
.topic-btn .topic-emoji { font-size: 20px; }
input[name="topic"] { display: none; }

/* Срочность */
.urgency-row {
    display: flex;
    gap: 8px;
}
.urgency-opt {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 9px 12px;
    border: 1.5px solid #e2e6ea;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    background: #fff;
    transition: border-color .15s, background .15s, color .15s;
}
.urgency-opt:hover { border-color: #9ca3af; }
.urgency-opt.selected-low    { border-color: #059669; background: #f0fdf4; color: #059669; }
.urgency-opt.selected-normal { border-color: #1a5fa8; background: #eff6ff; color: #1a5fa8; }
.urgency-opt.selected-high   { border-color: #ef4444; background: #fef2f2; color: #ef4444; }
input[name="urgency"] { display: none; }

/* Счётчик символов */
.char-count {
    text-align: right;
    font-size: 11px;
    color: #9ca3af;
    margin-top: 5px;
}
.char-count.warn { color: #f59e0b; }
.char-count.over { color: #ef4444; }

/* Кнопка отправки */
.btn-submit {
    width: 100%;
    padding: 13px;
    background: #1a3c6e;
    color: #fff;
    border: none;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background .15s, transform .1s;
    margin-top: 8px;
}
.btn-submit:hover   { background: #122d54; }
.btn-submit:active  { transform: scale(.98); }
.btn-submit:disabled{ opacity: .6; cursor: not-allowed; }

/* ── Alert ────────────────────────────────────────────────── */
.alert {
    padding: 14px 18px;
    border-radius: 9px;
    font-size: 13px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.alert-success {
    background: #f0fdf4;
    border: 1px solid #a7f3d0;
    color: #065f46;
}
.alert-error {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}
.alert-icon { font-size: 18px; flex-shrink: 0; margin-top: -1px; }

/* ── Sidebar ──────────────────────────────────────────────── */
.sidebar-card {
    background: #fff;
    border: 1px solid #e2e6ea;
    border-radius: 11px;
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.sidebar-card-header {
    padding: 13px 18px;
    border-bottom: 1px solid #f0f2f5;
    font-size: 13px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 8px;
}
.sidebar-card-body { padding: 16px 18px; }

/* Контакты в сайдбаре */
.quick-contact {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f7f8fa;
}
.quick-contact:last-child { border-bottom: none; padding-bottom: 0; }
.qc-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.qc-label { font-size: 11px; color: #9ca3af; font-weight: 500; }
.qc-value { font-size: 13px; font-weight: 600; color: #1a1a2e; }
.qc-value a { color: #1a5fa8; text-decoration: none; }
.qc-value a:hover { text-decoration: underline; }

/* ── История ──────────────────────────────────────────────── */
.history-item {
    padding: 12px 0;
    border-bottom: 1px solid #f7f8fa;
}
.history-item:last-child { border-bottom: none; }
.history-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 4px;
}
.history-subject {
    font-size: 13px;
    font-weight: 600;
    color: #1a1a2e;
    line-height: 1.3;
    flex: 1;
}
.history-date { font-size: 11px; color: #9ca3af; white-space: nowrap; flex-shrink: 0; }
.history-topic { font-size: 11px; color: #6b7280; margin-bottom: 4px; }

/* Статусы */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
}
.status-new      { background: #dbeafe; color: #1d4ed8; }
.status-progress { background: #fef3c7; color: #92400e; }
.status-answered { background: #d1fae5; color: #065f46; }
.status-closed   { background: #f3f4f6; color: #6b7280; }

/* ── Success overlay ──────────────────────────────────────── */
.success-box {
    text-align: center;
    padding: 40px 24px;
}
.success-anim {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: #d1fae5;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    margin: 0 auto 16px;
    animation: popIn .4s cubic-bezier(.34,1.56,.64,1);
}
@keyframes popIn {
    from { transform: scale(0); opacity: 0; }
    to   { transform: scale(1); opacity: 1; }
}
.success-title { font-size: 18px; font-weight: 700; color: #1a1a2e; margin-bottom: 8px; }
.success-sub   { font-size: 13px; color: #6b7280; line-height: 1.6; margin-bottom: 20px; }
.success-ticket {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f0f4ff;
    border: 1.5px solid #c7d9f5;
    border-radius: 9px;
    padding: 10px 18px;
    font-size: 14px;
    font-weight: 700;
    color: #1a3c6e;
    margin-bottom: 24px;
}
.btn-new {
    padding: 10px 24px;
    background: #1a3c6e;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-new:hover { background: #122d54; }

/* ── Responsive ───────────────────────────────────────────── */
@media(max-width: 900px) {
    .fb-layout { grid-template-columns: 1fr; }
    .topic-grid { grid-template-columns: repeat(2, 1fr); }
}
@media(max-width: 480px) {
    .urgency-row { flex-direction: column; }
    .topic-grid  { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="page-wrapper">
  <div class="container">
    
    <div class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard.php"><?= __t('nav_main') ?></a>
      <span>—</span>
      <span><?= __t('fb_title') ?></span>
    </div>

    <div class="page-heading">
      <h1><?= __t('fb_title') ?></h1>
    </div>

    <div class="fb-layout">

      <!-- LEFT: FORM -->
      <div class="fb-main">
        <div class="fb-card">
          <div class="fb-card-header">
            <div class="fb-card-icon">✉️</div>
            <div>
              <div class="fb-card-title"><?= __t('fb_send_request') ?></div>
              <div class="fb-card-sub"><?= __t('fb_min_chars') ?></div>
            </div>
          </div>

          <div class="fb-card-body">

            <?php if ($success): ?>
            <!-- SUCCESS STATE -->
            <div class="success-box">
              <div class="success-anim">✅</div>
              <div class="success-title"><?= __t('fb_success_title') ?></div>
              <div class="success-sub">
                <?= sprintf(__t('fb_success_sub'), htmlspecialchars($user['email'] ?? '')) ?>
              </div>
              <div class="success-ticket">
                🎫 <?= __t('fb_ticket_number') ?> <span>#<?= (int)$success ?></span>
              </div>
              <br>
              <a href="?new=1" class="btn-new">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= __t('fb_btn_new') ?>
              </a>
            </div>

            <?php else: ?>

            <?php if ($error): ?>
            <div class="alert alert-error">
              <span class="alert-icon">⚠️</span>
              <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" id="feedbackForm">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

              <!-- Тема обращения -->
              <div class="form-group">
                <label class="form-label"><?= __t('fb_topic_label') ?> <span class="req">*</span></label>
                <input type="hidden" name="topic" id="topicInput" value="<?= htmlspecialchars($_POST['topic'] ?? '') ?>">
                <div class="topic-grid">
                  <?php foreach ($topics as $key => $label): ?>
                  <?php
                    preg_match('/^(\S+)\s(.+)$/', $label, $m);
                    $emoji = $m[1] ?? '💬';
                    $text  = $m[2] ?? $label;
                    $sel   = ($_POST['topic'] ?? '') === $key ? 'selected' : '';
                  ?>
                  <button type="button" class="topic-btn <?= $sel ?>"
                          data-value="<?= $key ?>"
                          onclick="selectTopic(this)">
                    <span class="topic-emoji"><?= $emoji ?></span>
                    <span><?= htmlspecialchars($text) ?></span>
                  </button>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Тема сообщения -->
              <div class="form-group">
                <label class="form-label" for="subject">
                  <?= __t('field_subject') ?> <span class="req">*</span>
                  <span class="hint"><?= __t('fb_subject_hint') ?></span>
                </label>
                <input type="text"
                       id="subject"
                       name="subject"
                       class="form-control"
                       maxlength="120"
                       placeholder="<?= __t('fb_subject_placeholder') ?>"
                       value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                       required>
              </div>

              <!-- Сообщение -->
              <div class="form-group">
                <label class="form-label" for="message">
                  <?= __t('field_message') ?> <span class="req">*</span>
                </label>
                <textarea id="message"
                          name="message"
                          class="form-control"
                          rows="6"
                          maxlength="2000"
                          minlength="20"
                          placeholder="<?= __t('fb_message_placeholder') ?>"
                          oninput="updateCharCount(this)"
                          required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                <div class="char-count" id="charCount"><?= mb_strlen($_POST['message'] ?? '') ?> / 2000</div>
              </div>

              <!-- Срочность -->
              <div class="form-group">
                <label class="form-label"><?= __t('field_urgency') ?></label>
                <input type="hidden" name="urgency" id="urgencyInput" value="<?= htmlspecialchars($_POST['urgency'] ?? 'normal') ?>">
                <div class="urgency-row">
                  <button type="button" class="urgency-opt <?= ($_POST['urgency']??'normal')==='low' ? 'selected-low' : '' ?>"
                          data-value="low" onclick="selectUrgency(this)">
                    🟢 <?= __t('urgency_low') ?>
                  </button>
                  <button type="button" class="urgency-opt <?= ($_POST['urgency']??'normal')==='normal' ? 'selected-normal' : '' ?>"
                          data-value="normal" onclick="selectUrgency(this)">
                    🔵 <?= __t('urgency_normal') ?>
                  </button>
                  <button type="button" class="urgency-opt <?= ($_POST['urgency']??'normal')==='high' ? 'selected-high' : '' ?>"
                          data-value="high" onclick="selectUrgency(this)">
                    🔴 <?= __t('urgency_high') ?>
                  </button>
                </div>
              </div>

              <!-- E-mail пользователя (только чтение) -->
              <div class="form-group">
                <label class="form-label"><?= __t('fb_email_info') ?></label>
                <input type="text" class="form-control" readonly
                       value="<?= htmlspecialchars($user['email'] ?? '—') ?>"
                       style="background:#f9fafb;color:#6b7280">
              </div>

              <button type="submit" class="btn-submit" id="submitBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
                <?= __t('btn_send') ?>
              </button>

            </form>
            <?php endif; ?>

          </div>
        </div>

        <!-- История обращений -->
        <?php if ($history): ?>
        <div class="fb-card" style="margin-top:16px">
          <div class="fb-card-header">
            <div class="fb-card-icon" style="background:#fef3c7">🗂</div>
            <div>
              <div class="fb-card-title"><?= __t('fb_my_requests') ?></div>
              <div class="fb-card-sub"><?= sprintf(__t('fb_recent_records'), count($history)) ?></div>
            </div>
          </div>
          <div class="fb-card-body" style="padding:16px 24px">
            <?php foreach ($history as $h): ?>
            <?php $st = $statusLabels[$h['status']] ?? ['label'=>$h['status'],'class'=>'status-new']; ?>
            <div class="history-item">
              <div class="history-top">
                <div class="history-subject"><?= htmlspecialchars(mb_strimwidth($h['subject'], 0, 60, '…')) ?></div>
                <span class="status-badge <?= $st['class'] ?>"><?= $st['label'] ?></span>
              </div>
              <div class="history-topic">
                <?= htmlspecialchars($topics[$h['topic']] ?? $h['topic']) ?>
              </div>
              <div class="history-date">
                <?= date('d.m.Y H:i', strtotime($h['created_at'])) ?>
                · #<?= $h['id'] ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── САЙДБАР ─────────────────────────────────── -->
      <aside>

        <!-- Быстрые контакты -->
        <div class="sidebar-card">
          <div class="sidebar-card-header">
            📞 <?= __t('fb_quick_contact') ?>
          </div>
          <div class="sidebar-card-body">
            <div class="quick-contact">
              <div class="qc-icon" style="background:#e0f2fe">💬</div>
              <div>
                <div class="qc-label"><?= __t('fb_tg_bot') ?></div>
                <div class="qc-value"><a href="https://t.me/FinQoldau_bot" target="_blank">@FinQoldau_bot</a></div>
              </div>
            </div>
            <div class="quick-contact">
              <div class="qc-icon" style="background:#f0fdf4">📧</div>
              <div>
                <div class="qc-label"><?= __t('field_email') ?></div>
                <div class="qc-value"><a href="mailto:knpsd@ecc.kz">knpsd@ecc.kz</a></div>
              </div>
            </div>
            <div class="quick-contact">
              <div class="qc-icon" style="background:#fff7ed">📞</div>
              <div>
                <div class="qc-label"><?= __t('help_chancery') ?></div>
                <div class="qc-value"><a href="tel:+77172709947">8 (7172) 70 99 47</a></div>
              </div>
            </div>
            <div class="quick-contact">
              <div class="qc-icon" style="background:#fef2f2">☎️</div>
              <div>
                <div class="qc-label"><?= __t('help_contact_center') ?></div>
                <div class="qc-value" style="font-size:18px;font-weight:800;color:#1a3c6e">1414</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Советы -->
        <div class="sidebar-card">
          <div class="sidebar-card-header">
            💡 <?= __t('fb_tips_title') ?>
          </div>
          <div class="sidebar-card-body">
            <div style="display:flex;flex-direction:column;gap:12px">
              <div style="display:flex;gap:10px;align-items:flex-start">
                <span style="font-size:16px;flex-shrink:0">1️⃣</span>
                <div style="font-size:12px;color:#374151;line-height:1.5">
                  <?= __t('fb_tip_1') ?>
                </div>
              </div>
              <div style="display:flex;gap:10px;align-items:flex-start">
                <span style="font-size:16px;flex-shrink:0">2️⃣</span>
                <div style="font-size:12px;color:#374151;line-height:1.5">
                  <?= __t('fb_tip_2') ?>
                </div>
              </div>
              <div style="display:flex;gap:10px;align-items:flex-start">
                <span style="font-size:16px;flex-shrink:0">3️⃣</span>
                <div style="font-size:12px;color:#374151;line-height:1.5">
                  <?= __t('fb_tip_3') ?>
                </div>
              </div>
              <div style="display:flex;gap:10px;align-items:flex-start">
                <span style="font-size:16px;flex-shrink:0">4️⃣</span>
                <div style="font-size:12px;color:#374151;line-height:1.5">
                  <?= __t('fb_tip_4') ?>
                </div>
              </div>
            </div>

            <div style="margin-top:16px;padding:12px;background:#f0f4ff;border-radius:8px;border:1px solid #c7d9f5">
              <div style="font-size:11px;font-weight:700;color:#1a3c6e;margin-bottom:4px">⏱ <?= __t('fb_response_time') ?></div>
              <div style="font-size:12px;color:#374151;line-height:1.5">
                <?= __t('fb_response_normal') ?><br>
                <?= __t('fb_response_high') ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Ссылка на справку -->
        <div class="sidebar-card">
          <div class="sidebar-card-body" style="padding:16px">
            <a href="<?= SITE_URL ?>/help.php?tab=faq"
               style="display:flex;align-items:center;gap:12px;text-decoration:none;color:#1a3c6e;padding:12px;background:#f0f4ff;border-radius:9px;border:1.5px solid #c7d9f5;transition:background .15s"
               onmouseover="this.style.background='#e8f0fb'"
               onmouseout="this.style.background='#f0f4ff'">
              <span style="font-size:24px">📖</span>
              <div>
                <div style="font-size:13px;font-weight:700"><?= __t('fb_faq_link_title') ?></div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px"><?= __t('fb_faq_link_sub') ?></div>
              </div>
              <svg style="margin-left:auto;flex-shrink:0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          </div>
        </div>

      </aside>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
// ── Выбор темы ────────────────────────────────────────────────
function selectTopic(btn) {
    document.querySelectorAll('.topic-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('topicInput').value = btn.dataset.value;
}

// ── Выбор срочности ───────────────────────────────────────────
function selectUrgency(btn) {
    document.querySelectorAll('.urgency-opt').forEach(b => {
        b.classList.remove('selected-low', 'selected-normal', 'selected-high');
    });
    const cls = { low: 'selected-low', normal: 'selected-normal', high: 'selected-high' };
    btn.classList.add(cls[btn.dataset.value]);
    document.getElementById('urgencyInput').value = btn.dataset.value;
}

// ── Счётчик символов ─────────────────────────────────────────
function updateCharCount(el) {
    const len = el.value.length;
    const max = parseInt(el.getAttribute('maxlength')) || 2000;
    const el2 = document.getElementById('charCount');
    el2.textContent = len + ' / ' + max;
    el2.className = 'char-count' + (len > max * 0.9 ? (len >= max ? ' over' : ' warn') : '');
}

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', () => {
    // Счётчик
    const msgEl = document.getElementById('message');
    if (msgEl) updateCharCount(msgEl);

    // По умолчанию выбрать "Обычная" срочность если ничего не выбрано
    if (!document.querySelector('.urgency-opt[class*="selected"]')) {
        const normal = document.querySelector('.urgency-opt[data-value="normal"]');
        if (normal) normal.classList.add('selected-normal');
    }

    // Валидация перед отправкой
    const form = document.getElementById('feedbackForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const topic = document.getElementById('topicInput').value;
            if (!topic) {
                e.preventDefault();
                // Подсветить блок выбора темы
                document.querySelectorAll('.topic-btn').forEach(b => {
                    b.style.borderColor = '#ef4444';
                });
                setTimeout(() => {
                    document.querySelectorAll('.topic-btn').forEach(b => {
                        b.style.borderColor = '';
                    });
                }, 2000);
                alert('<?= __t('fb_alert_select_topic') ?>');
                return;
            }
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').innerHTML = '⏳ <?= __t('fb_msg_sending') ?>';
        });
    }
});
</script>
