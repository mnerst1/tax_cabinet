<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

// ── Таблица representatives (создать если нет) ────────────────
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS representatives (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            owner_id    INT NOT NULL,
            full_name   VARCHAR(255) NOT NULL,
            iin         VARCHAR(20)  NOT NULL,
            role        VARCHAR(100) DEFAULT 'Представитель',
            phone       VARCHAR(50)  DEFAULT NULL,
            email       VARCHAR(150) DEFAULT NULL,
            valid_from  DATE         DEFAULT NULL,
            valid_to    DATE         DEFAULT NULL,
            is_active   TINYINT(1)   DEFAULT 1,
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

$errors   = [];
$success  = '';

// ── Добавление ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $fullName  = trim($_POST['full_name']  ?? '');
    $iin       = trim($_POST['iin']        ?? '');
    $role      = trim($_POST['role']       ?? 'Представитель');
    $phone     = trim($_POST['phone']      ?? '');
    $email     = trim($_POST['email']      ?? '');
    $validFrom = $_POST['valid_from'] ?? null;
    $validTo   = $_POST['valid_to']   ?? null;

    if (!$fullName) $errors[] = 'Введите ФИО представителя';
    if (!$iin)      $errors[] = 'Введите ИИН представителя';
    if (strlen($iin) !== 12 || !ctype_digit($iin)) $errors[] = 'ИИН должен содержать 12 цифр';

    if (empty($errors)) {
        // Проверка дубликата
        $chk = $db->prepare("SELECT id FROM representatives WHERE owner_id = ? AND iin = ? AND is_active = 1");
        $chk->execute([$userId, $iin]);
        if ($chk->fetch()) {
            $errors[] = 'Представитель с таким ИИН уже добавлен';
        } else {
            $ins = $db->prepare("
                INSERT INTO representatives (owner_id, full_name, iin, role, phone, email, valid_from, valid_to)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $userId,
                $fullName,
                $iin,
                $role ?: 'Представитель',
                $phone ?: null,
                $email ?: null,
                $validFrom ?: null,
                $validTo   ?: null,
            ]);
            $success = 'Представитель успешно добавлен';
        }
    }
}

// ── Удаление / деактивация ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $repId = (int)($_POST['rep_id'] ?? 0);
    if ($repId) {
        $del = $db->prepare("UPDATE representatives SET is_active = 0 WHERE id = ? AND owner_id = ?");
        $del->execute([$repId, $userId]);
        $success = 'Представитель удалён';
    }
}

// ── Список ───────────────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT * FROM representatives
        WHERE owner_id = ? AND is_active = 1
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    $reps = $stmt->fetchAll();
} catch (Exception $e) { $reps = []; }

$unreadCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $s->execute([$userId]);
    $unreadCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

$pageTitle = 'Представители — ' . SITE_NAME;
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
/* ── Page ───────────────────── */
.page-wrapper { padding: 24px 0 60px; }

/* ── Breadcrumb ─────────────── */
.breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #9ca3af;
    margin-bottom: 18px;
}
.breadcrumb a { color: #1a5fa8; }
.breadcrumb a:hover { text-decoration: underline; }

/* ── Heading ────────────────── */
.page-heading { margin-bottom: 20px; }
.page-heading h1 { font-size: 22px; font-weight: 700; color: #1a1a2e; margin-bottom: 5px; }
.page-heading p  { font-size: 13px; color: #6b7280; max-width: 640px; line-height: 1.5; }

/* ── Alert ──────────────────── */
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

/* ── Toolbar ────────────────── */
.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.toolbar-left { display: flex; align-items: center; gap: 8px; }
.reps-count {
    background: #f0f4ff;
    color: #1a3c6e;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
}

/* ── Btn add ────────────────── */
.btn-add-rep {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #1a3c6e;
    color: #fff;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    font-family: inherit;
    transition: background .15s;
    text-decoration: none;
}
.btn-add-rep:hover { background: #122d54; }
.btn-add-rep svg { width: 15px; height: 15px; }

/* ── Card ───────────────────── */
.card {
    background: #fff;
    border: 1px solid #e2e6ea;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
}

/* ── Rep table ──────────────── */
.rep-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rep-table th {
    text-align: left;
    padding: 10px 20px;
    font-size: 11px;
    font-weight: 600;
    color: #6b7280;
    background: #f9fafb;
    border-bottom: 1px solid #eef0f3;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.rep-table td {
    padding: 14px 20px;
    border-bottom: 1px solid #f0f2f5;
    vertical-align: middle;
}
.rep-table tr:last-child td { border-bottom: none; }
.rep-table tr:hover td { background: #fafbff; }

.rep-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #7c3aed, #9f5cf5);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
}
.rep-name-cell { display: flex; align-items: center; gap: 10px; }
.rep-name      { font-weight: 600; color: #1a1a2e; margin-bottom: 1px; }
.rep-iin       { font-size: 11px; color: #9ca3af; }

.role-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    background: #f0f4ff;
    color: #1a3c6e;
}

.valid-date { font-size: 12px; color: #6b7280; }
.valid-date.expired { color: #dc2626; }
.valid-date.no-limit { color: #9ca3af; font-style: italic; }

.btn-delete-rep {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #dc2626;
    background: #fef2f2;
    border: 1px solid #fca5a5;
    cursor: pointer;
    font-family: inherit;
    transition: background .12s;
}
.btn-delete-rep:hover { background: #fee2e2; }
.btn-delete-rep svg { width: 13px; height: 13px; }

/* ── Empty ──────────────────── */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    color: #9ca3af;
}
.empty-state svg { width: 72px; height: 72px; opacity: .45; margin-bottom: 12px; }
.empty-state p { font-size: 13px; margin-bottom: 14px; }

/* ── Info block ─────────────── */
.info-block {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 10px;
    padding: 14px 18px;
    font-size: 12px;
    color: #0369a1;
    line-height: 1.7;
    margin-top: 16px;
}
.info-block strong { font-weight: 700; }

/* ══════════ МОДАЛЬНОЕ ОКНО ══════════ */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(10,20,40,.55);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.open { display: flex; }

.modal-box {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 540px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    animation: modalIn .2s cubic-bezier(.16,1,.3,1);
    overflow: hidden;
}
@keyframes modalIn {
    from { opacity:0; transform:translateY(20px) scale(.97); }
    to   { opacity:1; transform:none; }
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 22px;
    background: linear-gradient(135deg, #1a3c6e, #1a5fa8);
    color: #fff;
}
.modal-header h2 { font-size: 16px; font-weight: 700; }
.modal-close {
    width: 30px; height: 30px;
    border-radius: 50%;
    background: rgba(255,255,255,.15);
    border: none;
    color: #fff;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .15s;
}
.modal-close:hover { background: rgba(255,255,255,.3); }

.modal-body { padding: 22px; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group.full { grid-column: 1 / -1; }
.form-label { font-size: 12px; font-weight: 600; color: #374151; }
.form-label .req { color: #dc2626; }
.form-input, .form-select {
    padding: 9px 12px;
    border: 1.5px solid #e2e6ea;
    border-radius: 7px;
    font-size: 13px;
    font-family: inherit;
    color: #1a1a2e;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
}
.form-input:focus, .form-select:focus {
    border-color: #1a3c6e;
    box-shadow: 0 0 0 3px rgba(26,60,110,.1);
}
.form-hint { font-size: 11px; color: #9ca3af; }
.form-error-text { font-size: 11px; color: #dc2626; }

.modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 16px 22px;
    border-top: 1px solid #f0f2f5;
    background: #f9fafb;
}

.btn-cancel-modal {
    padding: 9px 18px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    background: #fff;
    border: 1.5px solid #e2e6ea;
    cursor: pointer;
    font-family: inherit;
    transition: background .12s;
}
.btn-cancel-modal:hover { background: #f3f4f6; }

.btn-submit-modal {
    padding: 9px 22px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    background: #1a3c6e;
    border: none;
    cursor: pointer;
    font-family: inherit;
    transition: background .12s;
    display: flex;
    align-items: center;
    gap: 7px;
}
.btn-submit-modal:hover { background: #122d54; }
.btn-submit-modal svg { width: 14px; height: 14px; }

/* ── Delete confirm modal ───── */
.confirm-modal-box {
    background: #fff;
    border-radius: 12px;
    width: 100%;
    max-width: 380px;
    box-shadow: 0 16px 48px rgba(0,0,0,.2);
    overflow: hidden;
    animation: modalIn .18s ease;
    text-align: center;
    padding: 28px 24px 22px;
}
.confirm-icon { font-size: 40px; margin-bottom: 10px; }
.confirm-title { font-size: 16px; font-weight: 700; color: #1a1a2e; margin-bottom: 8px; }
.confirm-text  { font-size: 13px; color: #6b7280; margin-bottom: 20px; line-height: 1.5; }
.confirm-btns  { display: flex; gap: 10px; justify-content: center; }
.btn-confirm-yes {
    padding: 9px 22px; border-radius: 7px; font-size: 13px; font-weight: 700;
    color: #fff; background: #dc2626; border: none; cursor: pointer;
    font-family: inherit; transition: background .12s;
}
.btn-confirm-yes:hover { background: #b91c1c; }
.btn-confirm-no {
    padding: 9px 22px; border-radius: 7px; font-size: 13px; font-weight: 600;
    color: #374151; background: #f3f4f6; border: 1.5px solid #e2e6ea;
    cursor: pointer; font-family: inherit; transition: background .12s;
}
.btn-confirm-no:hover { background: #e5e7eb; }

@media(max-width: 600px) {
    .form-grid { grid-template-columns: 1fr; }
    .toolbar { flex-direction: column; align-items: flex-start; }
}
</style>

<div class="page-wrapper">
  <div class="container">

    <!-- BREADCRUMB -->
    <div class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard.php"><?= __t('nav_main') ?></a>
      <span>—</span>
      <span><?= __t('nav_representatives') ?></span>
    </div>

    <!-- HEADING -->
    <div class="page-heading">
      <h1>👥 <?= __t('nav_representatives') ?></h1>
      <p><?= __t('rep_page_description') ?></p>
    </div>

    <!-- ALERTS -->
    <?php if ($success): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      ❌ <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
    </div>
    <?php endif; ?>

    <!-- TOOLBAR -->
    <div class="toolbar">
      <div class="toolbar-left">
        <span class="reps-count"><?= count($reps) ?> <?= __t('reps_count_suffix') ?></span>
      </div>
      <button class="btn-add-rep" onclick="openAddModal()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        <?= __t('rep_add_btn') ?>
      </button>
    </div>

    <!-- TABLE CARD -->
    <div class="card">
      <?php if (empty($reps)): ?>
        <div class="empty-state">
          <svg viewBox="0 0 80 80" fill="none">
            <path d="M50 52v-4a12 12 0 0 0-12-12H18a12 12 0 0 0-12 12v4" stroke="#cbd5e1" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="28" cy="22" r="10" stroke="#cbd5e1" stroke-width="2.5"/>
            <path d="M68 52v-4a12 12 0 0 0-8-11.3M50 11.3a12 12 0 0 1 0 21.4" stroke="#cbd5e1" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
          <p><?= __t('msg_no_data') ?></p>
          <button class="btn-add-rep" onclick="openAddModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= __t('rep_add_first') ?>
          </button>
        </div>
      <?php else: ?>
        <table class="rep-table">
          <thead>
            <tr>
              <th><?= __t('field_fio') ?></th>
              <th><?= __t('rep_role') ?></th>
              <th><?= __t('field_phone') ?></th>
              <th><?= __t('rep_validity') ?></th>
              <th><?= __t('table_date') ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reps as $rep): ?>
            <?php
              $initials = mb_substr($rep['full_name'], 0, 1);
              $isExpired = !empty($rep['valid_to']) && strtotime($rep['valid_to']) < time();
            ?>
            <tr>
              <td>
                <div class="rep-name-cell">
                  <div class="rep-avatar"><?= htmlspecialchars($initials) ?></div>
                  <div>
                    <div class="rep-name"><?= htmlspecialchars($rep['full_name']) ?></div>
                    <div class="rep-iin">ИИН: <?= htmlspecialchars($rep['iin']) ?></div>
                  </div>
                </div>
              </td>
              <td>
                <span class="role-badge"><?= htmlspecialchars($rep['role']) ?></span>
              </td>
              <td>
                <?php if (!empty($rep['phone'])): ?>
                  <a href="tel:<?= htmlspecialchars($rep['phone']) ?>"
                     style="color:#1a5fa8;font-size:13px">
                    <?= htmlspecialchars($rep['phone']) ?>
                  </a>
                <?php else: ?>
                  <span style="color:#9ca3af;font-size:12px">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($rep['valid_to'])): ?>
                  <div class="valid-date <?= $isExpired ? 'expired' : '' ?>">
                    <?= $isExpired ? '⚠️ ' : '' ?>
                    <?= !empty($rep['valid_from']) ? date('d.m.Y', strtotime($rep['valid_from'])) . ' — ' : '' ?>
                    <?= date('d.m.Y', strtotime($rep['valid_to'])) ?>
                    <?= $isExpired ? ' (' . __t('rep_expired') . ')' : '' ?>
                  </div>
                <?php else: ?>
                  <span class="valid-date no-limit"><?= __t('rep_unlimited') ?></span>
                <?php endif; ?>
              </td>
              <td style="color:#6b7280;font-size:12px">
                <?= date('d.m.Y', strtotime($rep['created_at'])) ?>
              </td>
              <td>
                <button class="btn-delete-rep"
                        onclick="confirmDelete(<?= (int)$rep['id'] ?>, '<?= htmlspecialchars($rep['full_name'], ENT_QUOTES) ?>')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6l-1 14H6L5 6"/>
                    <path d="M10 11v6M14 11v6"/>
                    <path d="M9 6V4h6v2"/>
                  </svg>
                  <?= __t('btn_delete') ?>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- INFO BLOCK -->
    <div class="info-block">
      <strong>ℹ️ <?= __t('rep_info_title') ?></strong><br>
      <?= __t('rep_info_text') ?>
      <strong>1414</strong> или <a href="https://t.me/FinQoldau_bot" target="_blank" style="color:#0369a1">@FinQoldau_bot</a>.
    </div>

  </div>
</div>

<!-- ═══════ MODAL: ДОБАВИТЬ ПРЕДСТАВИТЕЛЯ ═══════ -->
<div class="modal-overlay" id="addModal" onclick="closeOnOverlay(event,'addModal')">
  <div class="modal-box">
    <div class="modal-header">
      <h2>➕ <?= __t('rep_add_title') ?></h2>
      <button class="modal-close" onclick="closeAddModal()">✕</button>
    </div>

    <form method="POST" action="">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:14px">
          <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
        </div>
        <?php endif; ?>

        <div class="form-grid">

          <div class="form-group full">
            <label class="form-label"><?= __t('field_fio') ?> <span class="req">*</span></label>
            <input type="text" name="full_name" class="form-input"
                   placeholder="Иванов Иван Иванович"
                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                   required>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __t('field_iin') ?> <span class="req">*</span></label>
            <input type="text" name="iin" class="form-input"
                   placeholder="000000000000" maxlength="12"
                   value="<?= htmlspecialchars($_POST['iin'] ?? '') ?>"
                   required>
            <span class="form-hint"><?= __t('rep_iin_hint') ?></span>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __t('rep_role') ?></label>
            <select name="role" class="form-select">
              <?php
              $roles = ['Представитель','Уполномоченный представитель','Налоговый консультант','Бухгалтер','Юрист'];
              $selRole = $_POST['role'] ?? 'Представитель';
              foreach ($roles as $r):
              ?>
              <option value="<?= $r ?>" <?= $selRole===$r ? 'selected' : '' ?>><?= $r ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __t('field_phone') ?></label>
            <input type="text" name="phone" class="form-input"
                   placeholder="+7 (777) 000-00-00"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label"><?= __t('field_email') ?></label>
            <input type="email" name="email" class="form-input"
                   placeholder="example@mail.kz"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label"><?= __t('rep_field_valid_from') ?></label>
            <input type="date" name="valid_from" class="form-input"
                   value="<?= htmlspecialchars($_POST['valid_from'] ?? date('Y-m-d')) ?>">
          </div>

          <div class="form-group">
            <label class="form-label"><?= __t('rep_field_valid_to') ?></label>
            <input type="date" name="valid_to" class="form-input"
                   value="<?= htmlspecialchars($_POST['valid_to'] ?? '') ?>">
            <span class="form-hint"><?= __t('rep_field_hint_unlimited') ?></span>
          </div>

        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-cancel-modal" onclick="closeAddModal()"><?= __t('btn_cancel') ?></button>
        <button type="submit" class="btn-submit-modal">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          <?= __t('btn_add') ?>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════ MODAL: ПОДТВЕРЖДЕНИЕ УДАЛЕНИЯ ═══════ -->
<div class="modal-overlay" id="deleteModal" onclick="closeOnOverlay(event,'deleteModal')">
  <div class="confirm-modal-box">
    <div class="confirm-icon">🗑️</div>
    <div class="confirm-title"><?= __t('rep_delete_confirm_title') ?></div>
    <div class="confirm-text" id="deleteConfirmText">
      <?= __t('rep_delete_confirm_text') ?>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="action"  value="delete">
      <input type="hidden" name="rep_id"  id="deleteRepId" value="">
      <div class="confirm-btns">
        <button type="button" class="btn-confirm-no"  onclick="closeDeleteModal()"><?= __t('btn_cancel') ?></button>
        <button type="submit" class="btn-confirm-yes"><?= __t('btn_delete') ?></button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Add modal ────────────────────────────────────────────────
function openAddModal()  { document.getElementById('addModal').classList.add('open'); }
function closeAddModal() { document.getElementById('addModal').classList.remove('open'); }

// ── Delete modal ─────────────────────────────────────────────
function confirmDelete(id, name) {
    document.getElementById('deleteRepId').value = id;
    document.getElementById('deleteConfirmText').textContent =
        'Вы удаляете представителя: ' + name + '. Это действие нельзя отменить.';
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); }

// ── Закрыть по клику на фон ──────────────────────────────────
function closeOnOverlay(e, id) {
    if (e.target === document.getElementById(id)) {
        document.getElementById(id).classList.remove('open');
    }
}

// ── Escape ───────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    }
});

// ── Автооткрытие модала при ошибках добавления ───────────────
<?php if (!empty($errors)): ?>
openAddModal();
<?php endif; ?>

// ── Валидация ИИН на лету ────────────────────────────────────
document.querySelector('input[name="iin"]')?.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 12);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>