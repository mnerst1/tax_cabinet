<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$docId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$back  = in_array($_GET['back'] ?? '', ['account', 'documents']) ? ($_GET['back'] ?? 'account') : 'account';
$backUrl = SITE_URL . ($back === 'documents' ? 'documents.php' : 'account.php?tab=documents');

// ─────────────────────────────────────────────
// Получаем документ — только черновик, только владелец
// ─────────────────────────────────────────────
$doc = null;
if ($docId > 0) {
    try {
        $stmt = $db->prepare(
            "SELECT ud.id, ud.status, ud.report_year, ud.report_period,
                    dt.code AS type_code, dt.name AS type_name
             FROM user_documents ud
             JOIN document_types dt ON ud.doc_type_id = dt.id
             WHERE ud.id = ? AND ud.user_id = ?"
        );
        $stmt->execute([$docId, $userId]);
        $doc = $stmt->fetch();
    } catch (Exception $e) {
        $doc = null;
    }
}

// Документ не найден → редирект
if (!$doc) {
    header('Location: ' . $backUrl . '&error=not_found');
    exit;
}

// Не черновик → редирект
if ($doc['status'] !== 'draft') {
    header('Location: ' . $backUrl . '&error=not_draft');
    exit;
}

$error = '';

// ─────────────────────────────────────────────
// POST: подтверждение удаления
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    // CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Попробуйте снова.';
    } else {
        try {
            // Удаляем черновик формы из document_drafts (если есть)
            $db->prepare(
                "DELETE FROM document_drafts
                 WHERE user_id = ? AND doc_type_id = (
                     SELECT doc_type_id FROM user_documents WHERE id = ? AND user_id = ?
                 )"
            )->execute([$userId, $docId, $userId]);

            // Удаляем сам документ (двойная проверка: user_id + status=draft)
            $del = $db->prepare(
                "DELETE FROM user_documents WHERE id = ? AND user_id = ? AND status = 'draft'"
            );
            $del->execute([$docId, $userId]);

            if ($del->rowCount() > 0) {
                // Аудит-лог
                try {
                    $db->prepare(
                        "INSERT INTO audit_log
                             (user_id, action, entity_type, entity_id, ip_address, user_agent, status)
                         VALUES (?, 'delete_doc', 'user_documents', ?, ?, ?, 'success')"
                    )->execute([
                        $userId,
                        $docId,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    ]);
                } catch (Exception $e) { /* не критично */ }

                header('Location: ' . $backUrl . '&deleted=1');
                exit;
            } else {
                $error = 'Не удалось удалить документ. Возможно, он уже был удалён.';
            }
        } catch (Exception $e) {
            $error = 'Ошибка БД: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// CSRF-токен
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle   = 'Удаление черновика — ' . SITE_NAME;
$unreadCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $s->execute([$userId]);
    $unreadCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>
<style>
/* ── Страница подтверждения удаления ─────────────── */
.del-wrap {
    max-width: 520px;
    margin: 48px auto 80px;
    background: #fff;
    border: 1px solid #e2e6ea;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,.08);
}
.del-header {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    padding: 24px 28px;
    display: flex;
    align-items: center;
    gap: 14px;
    color: #fff;
}
.del-icon {
    width: 46px; height: 46px;
    background: rgba(255,255,255,.15);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.del-icon svg { width: 22px; height: 22px; }
.del-title    { font-size: 18px; font-weight: 700; line-height: 1.2; }
.del-subtitle { font-size: 13px; opacity: .75; margin-top: 3px; }
.del-body { padding: 24px 28px; }

/* Инфо-карточка документа */
.doc-info-box {
    background: #f9fafb;
    border: 1px solid #e2e6ea;
    border-radius: 8px;
    padding: 16px 18px;
    margin-bottom: 20px;
    display: grid;
    gap: 9px;
}
.doc-info-row  { display: flex; align-items: center; gap: 10px; font-size: 13px; }
.doc-info-lbl  { color: #6b7280; min-width: 110px; font-weight: 500; flex-shrink: 0; }
.doc-info-val  { color: #1a1a2e; font-weight: 600; }
.doc-code-badge {
    background: #e8f0fb; color: #1a3c6e;
    font-size: 12px; font-weight: 700;
    padding: 2px 8px; border-radius: 5px;
    margin-right: 4px;
}
.period-tag {
    font-size: 11px; color: #6b7280;
    background: #f3f4f6; padding: 2px 8px;
    border-radius: 4px; font-weight: 500;
}

/* Предупреждение */
.warn-box {
    display: flex; gap: 10px; align-items: flex-start;
    background: #fff7f7; border: 1px solid #fca5a5;
    border-radius: 8px; padding: 12px 16px;
    font-size: 13px; color: #991b1b;
    margin-bottom: 24px; line-height: 1.55;
}
.warn-box svg { flex-shrink: 0; margin-top: 1px; width: 16px; height: 16px; }

/* Сообщение об ошибке */
.err-msg {
    background: #fee2e2; border: 1px solid #fca5a5;
    border-radius: 8px; padding: 12px 16px;
    font-size: 13px; color: #991b1b; margin-bottom: 20px;
}

/* Кнопки */
.del-actions { display: flex; gap: 10px; justify-content: flex-end; }
.btn-cancel {
    padding: 10px 22px;
    background: #fff; color: #374151;
    border: 1px solid #e2e6ea; border-radius: 8px;
    font-size: 13px; font-weight: 600;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
    transition: background .15s, border-color .15s;
    font-family: inherit;
}
.btn-cancel:hover { background: #f9fafb; border-color: #d1d5db; }
.btn-delete {
    padding: 10px 22px;
    background: #dc2626; color: #fff;
    border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: inherit;
    display: inline-flex; align-items: center; gap: 6px;
    transition: background .15s;
}
.btn-delete:hover { background: #b91c1c; }
.btn-delete svg, .btn-cancel svg { width: 15px; height: 15px; flex-shrink: 0; }
</style>

<div class="page-wrapper">
  <div class="container">

    <div class="breadcrumb" style="margin-bottom:16px;">
      <a href="<?= SITE_URL ?>account.php">Лицевой счёт</a>
      <span>›</span>
      <a href="<?= SITE_URL ?>account.php?tab=documents">Документы</a>
      <span>›</span>
      <span>Удаление черновика</span>
    </div>

    <div class="del-wrap">

      <!-- ── Шапка ─────────────────────────── -->
      <div class="del-header">
        <div class="del-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6M14 11v6"/>
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <div>
          <div class="del-title">Удаление черновика</div>
          <div class="del-subtitle">Это действие нельзя отменить</div>
        </div>
      </div>

      <!-- ── Тело ──────────────────────────── -->
      <div class="del-body">

        <?php if ($error): ?>
          <div class="err-msg"><strong>Ошибка:</strong> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Информация о документе -->
        <div class="doc-info-box">
          <div class="doc-info-row">
            <span class="doc-info-lbl">Форма</span>
            <span class="doc-info-val">
              <span class="doc-code-badge"><?= htmlspecialchars($doc['type_code']) ?></span>
              <?= htmlspecialchars(mb_strimwidth($doc['type_name'], 0, 55, '…')) ?>
            </span>
          </div>
          <div class="doc-info-row">
            <span class="doc-info-lbl">Отчётный год</span>
            <span class="doc-info-val"><?= (int)$doc['report_year'] ?></span>
          </div>
          <?php if (!empty($doc['report_period'])): ?>
          <div class="doc-info-row">
            <span class="doc-info-lbl">Период</span>
            <span class="doc-info-val">
              <span class="period-tag"><?= htmlspecialchars($doc['report_period']) ?></span>
            </span>
          </div>
          <?php endif; ?>
          <div class="doc-info-row">
            <span class="doc-info-lbl">ID</span>
            <span class="doc-info-val" style="color:#9ca3af;">#<?= $docId ?></span>
          </div>
        </div>

        <!-- Предупреждение -->
        <div class="warn-box">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
          <span>
            Черновик и все введённые данные будут <strong>безвозвратно удалены</strong>.
            Удалить можно только документы со статусом <strong>«Черновик»</strong>.
            Поданные формы удалению не подлежат.
          </span>
        </div>

        <!-- Форма подтверждения -->
        <form method="POST">
          <input type="hidden" name="confirm_delete" value="1">
          <input type="hidden" name="csrf_token"
                 value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

          <div class="del-actions">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn-cancel">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
              </svg>
              Отмена
            </a>
            <button type="submit" class="btn-delete">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              </svg>
              Удалить черновик
            </button>
          </div>
        </form>

      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>