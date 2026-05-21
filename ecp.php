<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/auth/EcpService.php';

$user   = requireAuth();
$userId = (int)$user['id'];

$success = '';
$error   = '';

// ============================================================
// Генерация нового ЭЦП
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_ecp'])) {

    $password  = $_POST['ecp_password']  ?? '';
    $password2 = $_POST['ecp_password2'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Пароль должен содержать минимум 6 символов';
    } elseif ($password !== $password2) {
        $error = 'Пароли не совпадают';
    } else {
        try {
            $result = EcpService::generateEcp($userId, $password, 'auth');

            // Сохраняем cert_id в сессию для скачивания
            $_SESSION['new_cert_id']   = $result['cert_id'];
            $_SESSION['ecp_generated'] = true;

            $success = 'ЭЦП успешно выпущен! Файл: ' . htmlspecialchars($result['filename']);

        } catch (\Exception $e) {
            $error = 'Ошибка генерации: ' . $e->getMessage();
        }
    }
}

// Получаем все сертификаты пользователя
$certs = EcpService::getUserCerts($userId);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Управление ЭЦП — <?= SITE_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">
<link rel="stylesheet" href="<?= SITE_URL ?>assets/css/theme.css">
<script>
(function(){
    var k='tax-cabinet-theme',t;
    try{t=localStorage.getItem(k);}catch(e){}
    if(t==='dark'||(t!=='light'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches))
        document.documentElement.setAttribute('data-theme','dark');
})();
</script>
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<div class="page-wrapper">
  <div class="container">

    <!-- Хлебные крошки -->
    <div class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard.php">Главная</a>
      <span>—</span>
      <span>Мои ЭЦП</span>
    </div>

    <h1 class="page-title">🔑 Управление ЭЦП</h1>
    <p class="page-subtitle">Генерация, просмотр и управление ключами электронной цифровой подписи</p>

    <!-- Уведомления -->
    <?php if (!empty($error)): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      ❌ <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
      ✅ <?= htmlspecialchars($success) ?>
      <?php if (!empty($_SESSION['new_cert_id'])): ?>
      <a href="<?= SITE_URL ?>/auth/download_ecp.php?cert_id=<?= (int)$_SESSION['new_cert_id'] ?>"
         class="btn btn-sm" style="margin-left:12px;background:#fff;color:var(--success);border:1px solid var(--success);">
        ⬇ Скачать файл
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="ecp-layout">

      <!-- Левая колонка — список сертификатов -->
      <div class="ecp-main">

        <div class="card">
          <div class="card-header">
            <h2 class="card-title">Мои сертификаты (<?= count($certs) ?>)</h2>
          </div>
          <div class="card-body">

            <?php if (empty($certs)): ?>
            <div class="empty-state">
              <div class="empty-icon">🔐</div>
              <div class="empty-title">Нет сертификатов</div>
              <div class="empty-text">Выпустите первый ЭЦП с помощью формы справа</div>
            </div>
            <?php else: ?>

            <?php foreach ($certs as $cert): ?>
            <div class="cert-card <?= $cert['is_active'] ? 'cert-active' : 'cert-inactive' ?>">
              <div class="cert-card-header">
                <div class="cert-card-title">
                  🔒 <?= $cert['cert_type'] === 'auth' ? 'AUTH (для входа)' : 'SIGN (для подписи)' ?>
                  <span class="cert-type-badge">RSA_<?= strtoupper(htmlspecialchars($cert['cert_type'] ?? 'AUTH')) ?></span>
                </div>
                <span class="cert-status-badge <?= $cert['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                  <?= $cert['is_active'] ? 'Активен' : 'Неактивен' ?>
                </span>
              </div>

              <div class="cert-card-grid">
                <div class="cert-field">
                  <span class="cert-field-label">СЕРИЙНЫЙ №</span>
                  <span class="cert-field-value"><?= htmlspecialchars($cert['serial_number'] ?? '—') ?></span>
                </div>
                <div class="cert-field">
                  <span class="cert-field-label">ФАЙЛ</span>
                  <span class="cert-field-value"><?= htmlspecialchars($cert['p12_filename'] ?? '—') ?></span>
                </div>
                <div class="cert-field">
                  <span class="cert-field-label">ВЫДАН</span>
                  <span class="cert-field-value">
                    <?= !empty($cert['valid_from']) ? date('d.m.Y', strtotime($cert['valid_from'])) : '—' ?>
                  </span>
                </div>
                <div class="cert-field">
                  <span class="cert-field-label">ИСТЕКАЕТ</span>
                  <span class="cert-field-value <?= !empty($cert['valid_to']) && strtotime($cert['valid_to']) < time() ? 'text-error' : 'text-success' ?>">
                    <?= !empty($cert['valid_to']) ? date('d.m.Y', strtotime($cert['valid_to'])) : '—' ?>
                  </span>
                </div>
              </div>

              <?php if ($cert['is_active']): ?>
              <div class="cert-card-actions">
                <a href="<?= SITE_URL ?>/auth/download_ecp.php?cert_id=<?= (int)$cert['id'] ?>"
                   class="btn btn-warning btn-sm">
                  ⬇ Скачать
                </a>
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>
          </div>
        </div>

      </div>

      <!-- Правая колонка — форма генерации -->
      <div class="ecp-sidebar">
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">🔧 Выпустить новый ЭЦП</h2>
          </div>
          <div class="card-body">

            <p style="font-size:13px;color:var(--gray-text);margin-bottom:16px;">
              Создайте новый ключ ЭЦП. Файл .p12 будет сгенерирован с помощью OpenSSL.
            </p>

            <div class="alert alert-info" style="font-size:13px;margin-bottom:16px;">
              🔑 Будет выпущен единый ЭЦП (RSA) — используется для входа и подписи документов.
            </div>

            <!-- Скрытый тип ключа -->
            <form method="POST" class="auth-form">
              <input type="hidden" name="key_type" value="auth">

              <div class="form-group">
                <label>Пароль ЭЦП <span class="required">*</span></label>
                <div class="input-eye">
                  <input type="password" name="ecp_password" id="ecpPwd"
                         class="form-control" placeholder="Минимум 6 символов"
                         minlength="6" required>
                  <button type="button" class="eye-btn" onclick="toggleEye('ecpPwd')">👁</button>
                </div>
              </div>

              <div class="form-group">
                <label>Подтверждение пароля <span class="required">*</span></label>
                <div class="input-eye">
                  <input type="password" name="ecp_password2" id="ecpPwd2"
                         class="form-control" placeholder="Повторите пароль"
                         minlength="6" required>
                  <button type="button" class="eye-btn" onclick="toggleEye('ecpPwd2')">👁</button>
                </div>
              </div>

              <div class="alert alert-warning" style="font-size:12px;margin-bottom:16px;">
                ⚠️ Запомните пароль! Восстановить его невозможно.
              </div>

              <button type="submit" name="generate_ecp" value="1"
                      class="btn btn-warning btn-full">
                ✨ Сгенерировать ЭЦП
              </button>
            </form>

          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
function toggleEye(id) {
  const el = document.getElementById(id);
  el.type = el.type === 'password' ? 'text' : 'password';
}
</script>

<style>
.ecp-layout {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 24px;
  margin-top: 24px;
}
.cert-card {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 18px;
  margin-bottom: 16px;
  background: var(--white);
}
.cert-card.cert-active  { border-color: #d1fae5; background: #f0fdf4; }
.cert-card.cert-inactive{ border-color: #e5e7eb; background: #f9fafb; opacity: .8; }
.cert-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}
.cert-card-title {
  font-weight: 700;
  font-size: 15px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.cert-type-badge {
  font-size: 11px;
  background: #1a3c6e;
  color: #fff;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 600;
}
.cert-status-badge {
  font-size: 12px;
  padding: 3px 10px;
  border-radius: 20px;
  font-weight: 600;
}
.badge-active   { background: #d1fae5; color: #065f46; }
.badge-inactive { background: #f3f4f6; color: #6b7280; }
.cert-card-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 14px;
}
.cert-field { display: flex; flex-direction: column; gap: 2px; }
.cert-field-label { font-size: 10px; color: var(--gray-text); font-weight: 700; letter-spacing: .5px; }
.cert-field-value { font-size: 13px; font-weight: 500; color: var(--gray-dark); word-break: break-all; }
.cert-card-actions { display: flex; gap: 8px; }
.text-success { color: #059669; }
.text-error   { color: #dc2626; }
@media (max-width: 768px) {
  .ecp-layout { grid-template-columns: 1fr; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>