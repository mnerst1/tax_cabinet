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

$tab       = $_GET['tab'] ?? 'contacts';
$pageTitle = __t('help_title') . ' — ' . SITE_NAME;
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
/* ── Page tabs ──────────────────────────────────────────── */
.help-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid #e2e6ea;
    margin-bottom: 28px;
}
.help-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 13px 24px;
    font-size: 14px;
    font-weight: 600;
    color: #6b7280;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    text-decoration: none;
    cursor: pointer;
    transition: color .15s, border-color .15s;
    white-space: nowrap;
}
.help-tab:hover  { color: #1a3c6e; }
.help-tab.active { color: #1a3c6e; border-bottom-color: #1a3c6e; }
.help-tab svg    { width: 17px; height: 17px; }

/* ── Contact card ───────────────────────────────────────── */
.contact-card {
    background: #fff;
    border: 1px solid #e2e6ea;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
    max-width: 560px;
}
.contact-card-header {
    background: linear-gradient(135deg, #1a3c6e 0%, #1a5fa8 100%);
    padding: 24px 28px 20px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 16px;
}
.contact-card-icon {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    background: rgba(255,255,255,.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,.25);
}
.contact-card-title { font-size: 18px; font-weight: 700; }
.contact-card-sub   { font-size: 13px; opacity: .75; margin-top: 3px; }

.contact-row {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 15px 28px;
    border-bottom: 1px solid #f0f2f5;
}
.contact-row:last-child { border-bottom: none; }
.contact-row-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    margin-top: 1px;
}
.contact-row-body {}
.contact-row-label { font-size: 11px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 3px; }
.contact-row-value { font-size: 14px; font-weight: 600; color: #1a1a2e; }
.contact-row-value a { color: #1a5fa8; text-decoration: none; }
.contact-row-value a:hover { text-decoration: underline; }
.contact-row-note  { font-size: 12px; color: #6b7280; margin-top: 2px; }

/* ── FAQ accordion ──────────────────────────────────────── */
.faq-section { margin-bottom: 10px; }

.faq-item {
    background: #fff;
    border: 1px solid #e2e6ea;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    transition: box-shadow .2s;
}
.faq-item:hover { box-shadow: 0 3px 10px rgba(0,0,0,.09); }
.faq-item.open  { box-shadow: 0 4px 16px rgba(26,63,110,.12); border-color: #c7d9f5; }

.faq-question {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
}
.faq-num {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: #eef3fb;
    color: #1a3c6e;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background .2s, color .2s;
}
.faq-item.open .faq-num { background: #1a3c6e; color: #fff; }

.faq-title {
    flex: 1;
    font-size: 14px;
    font-weight: 600;
    color: #1a1a2e;
    line-height: 1.4;
}
.faq-item.open .faq-title { color: #1a3c6e; }

.faq-chevron {
    width: 20px;
    height: 20px;
    color: #9ca3af;
    flex-shrink: 0;
    transition: transform .25s cubic-bezier(.4,0,.2,1);
}
.faq-item.open .faq-chevron { transform: rotate(180deg); color: #1a3c6e; }

.faq-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height .35s cubic-bezier(.4,0,.2,1);
}
.faq-body-inner {
    padding: 0 20px 18px 60px;
    font-size: 13px;
    color: #374151;
    line-height: 1.7;
    border-top: 1px solid #f0f2f5;
    padding-top: 14px;
}
.faq-body-inner p      { margin-bottom: 10px; }
.faq-body-inner p:last-child { margin-bottom: 0; }
.faq-body-inner ul     { padding-left: 18px; margin: 8px 0; }
.faq-body-inner ul li  { margin-bottom: 5px; }
.faq-body-inner strong { color: #1a1a2e; }
.faq-body-inner .step  {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 8px;
}
.faq-body-inner .step-num {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #1a3c6e;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 1px;
}
.faq-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 7px;
    padding: 10px 12px;
    font-size: 12px;
    color: #92400e;
    margin-top: 10px;
}
.faq-note-info {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1e40af;
}

/* ── Responsive ─────────────────────────────────────────── */
@media(max-width: 600px) {
    .help-tab { padding: 11px 14px; font-size: 13px; }
    .contact-card { max-width: 100%; }
    .faq-body-inner { padding-left: 20px; }
}
</style>

<div class="page-wrapper">
  <div class="container" style="max-width:860px">

    <div class="page-heading">
      <h1><?= __t('help_title') ?></h1>
      <p><?= __t('help_desc') ?></p>
    </div>

    <!-- ВКЛАДКИ -->
    <div class="help-tabs">
      <a href="?tab=contacts" class="help-tab <?= $tab === 'contacts' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.38 2 2 0 0 1 3.59 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.56a16 16 0 0 0 6.29 6.29l.87-.87a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
        </svg>
        <?= __t('help_tab_contacts') ?>
      </a>
      <a href="?tab=faq" class="help-tab <?= $tab === 'faq' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <?= __t('help_tab_faq') ?>
      </a>
    </div>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ВКЛАДКА: КОНТАКТЫ                          -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($tab === 'contacts'): ?>

    <div class="contact-card">
      <div class="contact-card-header">
        <div class="contact-card-icon">🏛</div>
        <div>
          <div class="contact-card-title"><?= __t('help_support_service') ?></div>
          <div class="contact-card-sub"><?= __t('help_knp') ?></div>
        </div>
      </div>

      <!-- Telegram bot -->
      <div class="contact-row">
        <div class="contact-row-icon" style="background:#e0f2fe">💬</div>
        <div class="contact-row-body">
          <div class="contact-row-label"><?= __t('help_tg_bot') ?></div>
          <div class="contact-row-value">
            <a href="https://t.me/FinQoldau_bot" target="_blank" rel="noopener">@FinQoldau_bot</a>
          </div>
          <div class="contact-row-note"><?= __t('help_tg_note') ?></div>
        </div>
      </div>

      <!-- Email -->
      <div class="contact-row">
        <div class="contact-row-icon" style="background:#f0fdf4">📧</div>
        <div class="contact-row-body">
          <div class="contact-row-label"><?= __t('help_email') ?></div>
          <div class="contact-row-value">
            <a href="mailto:knpsd@ecc.kz">knpsd@ecc.kz</a>
          </div>
          <div class="contact-row-note"><?= __t('help_email_note') ?></div>
        </div>
      </div>

      <!-- Адрес -->
      <div class="contact-row">
        <div class="contact-row-icon" style="background:#fdf4ff">📍</div>
        <div class="contact-row-body">
          <div class="contact-row-label"><?= __t('field_address') ?></div>
          <div class="contact-row-value"><?= __t('sys_address') ?></div>
          <div class="contact-row-note"><?= __t('help_address_note') ?></div>
        </div>
      </div>

      <!-- Канцелярия -->
      <div class="contact-row">
        <div class="contact-row-icon" style="background:#fff7ed">📞</div>
        <div class="contact-row-body">
          <div class="contact-row-label"><?= __t('help_chancery') ?></div>
          <div class="contact-row-value">
            <a href="tel:+77172709947">8 (7172) 70 99 47</a>
          </div>
          <div class="contact-row-note"><?= __t('help_chancery_note') ?></div>
        </div>
      </div>

      <!-- Единый контакт-центр -->
      <div class="contact-row">
        <div class="contact-row-icon" style="background:#fef2f2">☎️</div>
        <div class="contact-row-body">
          <div class="contact-row-label"><?= __t('help_contact_center') ?></div>
          <div class="contact-row-value" style="font-size:22px;font-weight:800;color:#1a3c6e;letter-spacing:1px">
            1414
          </div>
          <div class="contact-row-note"><?= __t('help_contact_center_note') ?></div>
        </div>
      </div>
    </div>

    <?php endif; ?>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ВКЛАДКА: СПРАВОЧНАЯ ИНФОРМАЦИЯ             -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($tab === 'faq'): ?>

    <?php
    $faqs = [
        ['title' => __t('faq_title_1'),  'body' => __t('faq_body_1')],
        ['title' => __t('faq_title_2'),  'body' => __t('faq_body_2')],
        ['title' => __t('faq_title_3'),  'body' => __t('faq_body_3')],
        ['title' => __t('faq_title_4'),  'body' => __t('faq_body_4')],
        ['title' => __t('faq_title_5'),  'body' => __t('faq_body_5')],
        ['title' => __t('faq_title_6'),  'body' => __t('faq_body_6')],
        ['title' => __t('faq_title_7'),  'body' => __t('faq_body_7')],
        ['title' => __t('faq_title_8'),  'body' => __t('faq_body_8')],
        ['title' => __t('faq_title_9'),  'body' => __t('faq_body_9')],
        ['title' => __t('faq_title_10'), 'body' => __t('faq_body_10')],
    ];
    ?>

    <div class="faq-section">
      <?php foreach ($faqs as $i => $faq): ?>
      <div class="faq-item" id="faq-<?= $i ?>">
        <div class="faq-question" onclick="toggleFaq(<?= $i ?>)">
          <div class="faq-num"><?= $i + 1 ?></div>
          <div class="faq-title"><?= htmlspecialchars($faq['title']) ?></div>
          <svg class="faq-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>
        <div class="faq-body" id="faq-body-<?= $i ?>">
          <div class="faq-body-inner">
            <?= $faq['body'] ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
const openItems = new Set();

function toggleFaq(i) {
    const item = document.getElementById('faq-' + i);
    const body = document.getElementById('faq-body-' + i);

    if (openItems.has(i)) {
        // Закрыть
        body.style.maxHeight = body.scrollHeight + 'px';
        requestAnimationFrame(() => {
            body.style.maxHeight = '0';
        });
        item.classList.remove('open');
        openItems.delete(i);
    } else {
        // Открыть
        body.style.maxHeight = body.scrollHeight + 'px';
        item.classList.add('open');
        openItems.add(i);
        // Убрать maxHeight после анимации чтобы контент не обрезался
        body.addEventListener('transitionend', function handler() {
            if (openItems.has(i)) body.style.maxHeight = 'none';
            body.removeEventListener('transitionend', handler);
        });
    }
}

// Открыть первый пункт по умолчанию
<?php if ($tab === 'faq'): ?>
toggleFaq(0);
<?php endif; ?>
</script>
