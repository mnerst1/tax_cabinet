<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/auth/EcpService.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];



// РРЎРџР РђР’Р›Р•РќРР•: Р±РµР·РѕРїР°СЃРЅРѕ РґРѕСЃС‚Р°С‘Рј РІСЃРµ РїРѕР»СЏ СЃ РґРµС„РѕР»С‚РЅС‹РјРё Р·РЅР°С‡РµРЅРёСЏРјРё
$personType  = $user['person_type']  ?? 'individual';
$isActive    = $user['is_active']    ?? 1;
$fullName    = $user['full_name']    ?? '';
$iin         = $user['iin']          ?? '';
$phone       = $user['phone']        ?? '';
$email       = $user['email']        ?? '';
$createdAt   = $user['created_at']   ?? '';
$taxOfficeId = $user['tax_office_id'] ?? null;

// РџРѕР»СѓС‡Р°РµРј РћР“Р”
$taxOfficeName = 'вЂ”';
if ($taxOfficeId) {
    $stmt2 = $db->prepare("SELECT name FROM tax_offices WHERE id = ?");
    $stmt2->execute([$taxOfficeId]);
    $taxOfficeName = $stmt2->fetchColumn() ?: 'вЂ”';
}

// РСЃС‚РѕСЂРёСЏ РІС…РѕРґРѕРІ
$stmt3 = $db->prepare("SELECT * FROM login_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt3->execute([$_SESSION['user_id']]);
$loginHistory = $stmt3->fetchAll(PDO::FETCH_ASSOC);

// Р­Р¦Рџ СЃРµСЂС‚РёС„РёРєР°С‚С‹
$stmt4 = $db->prepare("SELECT * FROM ecp_certificates WHERE user_id = ? ORDER BY created_at DESC");
$stmt4->execute([$_SESSION['user_id']]);
$certificates = $stmt4->fetchAll(PDO::FETCH_ASSOC);

function profileTableColumns(PDO $db, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        $cache[$table] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Throwable $e) {
        $cache[$table] = [];
    }

    return $cache[$table];
}

function profileAuthMethodLabel(?string $method): string {
    return match ($method) {
        'ecp' => 'ЭЦП',
        'iin_password', 'elk' => 'ИИН/Пароль',
        'digital_id' => 'Digital ID',
        default => 'Неизвестно',
    };
}

function profileSessionStatusLabel(array $session): array {
    $now = time();
    $expiresAt = !empty($session['expires_at']) ? strtotime($session['expires_at']) : null;

    if (!empty($session['logged_out_at'])) {
        return ['Завершена', 'badge-gray'];
    }
    if ($expiresAt && $expiresAt <= $now) {
        return ['Истекла', 'badge-red'];
    }
    if (array_key_exists('is_active', $session) && !(int)$session['is_active']) {
        return ['Неактивна', 'badge-gray'];
    }

    return ['Активна', 'badge-green'];
}

function profileLoginStatusLabel(array $log): array {
    $status = $log['status'] ?? null;
    $success = $log['is_success'] ?? $log['success'] ?? null;

    if ($status === 'success' || (string)$success === '1') {
        return ['Успешно', 'badge-green'];
    }
    if ($status === 'blocked') {
        return ['Заблокировано', 'badge-red'];
    }

    return ['Ошибка', 'badge-red'];
}

function profileDeviceLabel(?string $userAgent): string {
    $ua = (string)$userAgent;
    if ($ua === '') {
        return 'Неизвестное устройство';
    }

    $os = 'Устройство';
    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Mac OS') !== false || stripos($ua, 'Macintosh') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    $browser = 'Браузер';
    if (stripos($ua, 'Edg/') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $browser = 'Opera';
    elseif (stripos($ua, 'Chrome/') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'Firefox/') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Safari/') !== false) $browser = 'Safari';

    return $os . ' / ' . $browser;
}

$currentSession = null;
$previousSessions = [];
$sessionColumns = profileTableColumns($db, 'user_sessions');
if ($sessionColumns) {
    try {
        if (!empty($_SESSION['session_token']) && in_array('session_token', $sessionColumns, true)) {
            $stmtSession = $db->prepare("SELECT * FROM user_sessions WHERE user_id = ? AND session_token = ? LIMIT 1");
            $stmtSession->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
            $currentSession = $stmtSession->fetch(PDO::FETCH_ASSOC) ?: null;

            $stmtPrevSessions = $db->prepare("
                SELECT * FROM user_sessions
                WHERE user_id = ? AND session_token <> ?
                ORDER BY created_at DESC
                LIMIT 8
            ");
            $stmtPrevSessions->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
        } else {
            $stmtPrevSessions = $db->prepare("
                SELECT * FROM user_sessions
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 8
            ");
            $stmtPrevSessions->execute([$_SESSION['user_id']]);
        }

        $previousSessions = $stmtPrevSessions->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $currentSession = null;
        $previousSessions = [];
    }
}

if (!$currentSession) {
    $currentSession = [
        'auth_method' => $_SESSION['auth_method'] ?? 'iin_password',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => null,
    ];
}

// РћР±СЂР°Р±РѕС‚РєР° РѕР±РЅРѕРІР»РµРЅРёСЏ РЅР°СЃС‚СЂРѕРµРє
$successMsg = '';
$errorMsg   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'РћС€РёР±РєР° Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё (CSRF). РџРѕР¶Р°Р»СѓР№СЃС‚Р°, РѕР±РЅРѕРІРёС‚Рµ СЃС‚СЂР°РЅРёС†Сѓ.';
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'update_contacts') {
        $newPhone = trim($_POST['phone'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');
        $stmt5 = $db->prepare("UPDATE users SET phone = ?, email = ? WHERE id = ?");
        if ($stmt5->execute([$newPhone ?: null, $newEmail ?: null, $_SESSION['user_id']])) {
            $phone = $newPhone;
            $email = $newEmail;
            $successMsg = __t('msg_contact_updated');
        } else {
            $errorMsg = __t('msg_save_error');
        }
    }

    if ($action === 'change_password') {
        $oldPass  = $_POST['old_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        $stmtPass = $db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmtPass->execute([$_SESSION['user_id']]);
        $currentPasswordHash = (string)($stmtPass->fetchColumn() ?: '');

        if (!password_verify($oldPass, $currentPasswordHash)) {
            $errorMsg = __t('msg_wrong_pass');
        } elseif (strlen($newPass) < 6) {
            $errorMsg = __t('msg_pass_short');
        } elseif ($newPass !== $confPass) {
            $errorMsg = __t('msg_pass_mismatch');
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $stmtUpdatePass = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            if ($stmtUpdatePass->execute([$hash, $_SESSION['user_id']]) && $stmtUpdatePass->rowCount() > 0) {
                $user['password_hash'] = $hash;
                $successMsg = __t('msg_pass_changed');
            } else {
                $errorMsg = __t('msg_save_error');
            }
        }
    }

    if ($action === 'generate_ecp') {
        $password = $_POST['ecp_password'] ?? '';
        if (strlen($password) < 4) {
            $errorMsg = __t('msg_pass_short');
        } else {
            try {
                $result = EcpService::generateEcp((int)$_SESSION['user_id'], $password, 'auth');
                if (!empty($result['cert_id'])) {
                    $_SESSION['new_cert_id'] = (int)$result['cert_id'];
                    $successMsg = __t('msg_ecp_created') . ' Р¤Р°Р№Р»: ' . ($result['filename'] ?? 'Р­Р¦Рџ');
                    // РџРµСЂРµР·Р°РіСЂСѓР¶Р°РµРј СЃРїРёСЃРѕРє СЃРµСЂС‚РёС„РёРєР°С‚РѕРІ
                    $stmt4 = $db->prepare("SELECT * FROM ecp_certificates WHERE user_id = ? ORDER BY created_at DESC");
                    $stmt4->execute([$_SESSION['user_id']]);
                    $certificates = $stmt4->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $errorMsg = __t('status_error') . ': ' . ($result['error'] ?? 'unknown');
                }
            } catch (Exception $e) {
                $errorMsg = __t('status_error') . ': ' . $e->getMessage();
            }
        }
    }
}
}

$personTypeLabel = match($personType) {
    'individual'  => __t('type_individual'),
    'entrepreneur'=> __t('type_entrepreneur'),
    'legal'       => __t('type_legal'),
    default       => __t('type_individual')
};

$pageTitle = __t('profile_title') . ' вЂ” ' . __t('auth_cabinet_name');
$activeTab = $_GET['tab'] ?? 'profile';
if (!in_array($activeTab, ['profile', 'settings'], true)) {
    $activeTab = 'profile';
}
include __DIR__ . '/includes/header.php';
?>

<div class="container page-wrapper">
  <div class="breadcrumb">
    <a href="<?= SITE_URL ?>/dashboard.php"><?= __t('nav_main') ?></a>
    <span>вЂ”</span>
    <span><?= __t('nav_profile') ?></span>
  </div>

  <div class="page-heading">
    <h1><?= htmlspecialchars(strtoupper($fullName)) ?></h1>
    <p><?= __t('auth_register_sub') ?></p>
  </div>

  <?php if ($successMsg): ?>
  <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px;">
    вњ… <?= htmlspecialchars($successMsg) ?>
    <?php if (!empty($_SESSION['new_cert_id'])): ?>
      <a href="<?= SITE_URL ?>/auth/download_ecp.php?cert_id=<?= (int)$_SESSION['new_cert_id'] ?>" style="margin-left:auto;background:#fff;color:#065f46;border:1px solid #6ee7b7;border-radius:6px;padding:6px 10px;text-decoration:none;font-weight:600">в¬‡ РЎРєР°С‡Р°С‚СЊ Р­Р¦Рџ</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
  <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px;">
    вќЊ <?= htmlspecialchars($errorMsg) ?>
  </div>
  <?php endif; ?>

  <!-- Р’РљР›РђР”РљР -->
  <div class="profile-tabs">
    <button class="profile-tab-btn <?= $activeTab === 'profile'  ? 'active' : '' ?>" onclick="switchTab('profile', this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      <?= __t('nav_profile') ?>
    </button>
    <button class="profile-tab-btn <?= $activeTab === 'settings' ? 'active' : '' ?>" onclick="switchTab('settings', this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
      <?= __t('nav_settings') ?>
    </button>
  </div>

  <!-- РўРђР‘: РџР РћР¤РР›Р¬ -->
  <div id="tab-profile" class="tab-content <?= $activeTab !== 'profile' ? 'tab-hidden' : '' ?>">
    <div class="profile-panel-grid">
      <div>
        <div class="card" style="margin-bottom:16px">
          <div class="profile-section-title"><?= __t('profile_main_data') ?></div>
          <div class="profile-field">
            <span class="profile-field-label"><?= __t('field_fio') ?></span>
            <span class="profile-field-value"><?= htmlspecialchars(strtoupper($fullName)) ?></span>
            <span></span>
          </div>
          <div class="profile-field">
            <span class="profile-field-label"><?= __t('field_iin') ?></span>
            <span class="profile-field-value"><?= htmlspecialchars($iin) ?></span>
            <span></span>
          </div>
          <div class="profile-field">
            <span class="profile-field-label"><?= __t('field_tax_type') ?></span>
            <span class="profile-field-value"><?= $personTypeLabel ?></span>
            <span></span>
          </div>
          <div class="profile-field">
            <span class="profile-field-label"><?= __t('table_status') ?></span>
            <span class="profile-field-value">
              <?php if ($isActive): ?>
                <span class="badge badge-green"><?= __t('status_active') ?></span>
              <?php else: ?>
                <span class="badge badge-red"><?= __t('status_blocked') ?></span>
              <?php endif; ?>
            </span>
            <span></span>
          </div>
          <div class="profile-field">
            <span class="profile-field-label"><?= __t('field_reg_date') ?></span>
            <span class="profile-field-value"><?= $createdAt ? date('d.m.Y', strtotime($createdAt)) : 'вЂ”' ?></span>
            <span></span>
          </div>
          <div class="profile-field">
            <span class="profile-field-label"><?= __t('field_tax_office') ?></span>
            <span class="profile-field-value"><?= htmlspecialchars($taxOfficeName) ?></span>
            <span></span>
          </div>
        </div>

        <!-- Р­Р¦Рџ РЎР•Р РўРР¤РРљРђРўР« -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <?= __t('profile_ecp_certs') ?>
            </span>
            <button type="button" class="btn-submit" style="padding:6px 14px;font-size:12px;border:none;cursor:pointer" onclick="openEcpModal()">+ <?= __t('btn_create_ecp') ?></button>
          
</div>
          <?php if (empty($certificates)): ?>
            <div class="empty-state" style="padding:24px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <p><?= __t('msg_empty_docs') ?></p>
            </div>
          <?php else: ?>
            <table class="doc-table">

    <thead>
        <tr>
            <th><?= __t('table_serial') ?></th>
            <th><?= __t('table_type') ?></th>
            <th><?= __t('table_valid_until') ?></th>
            <th><?= __t('table_status') ?></th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($certificates as $cert): ?>

        <tr>

            <td data-label="<?= __t('table_serial') ?>"
                style="font-family:monospace;font-size:12px">

                <?= htmlspecialchars($cert['serial_number'] ?? 'вЂ”') ?>

            </td>

            <td data-label="<?= __t('table_type') ?>">

                <?= htmlspecialchars($cert['cert_type'] ?? 'RSA') ?>

            </td>

            <td data-label="<?= __t('table_valid_until') ?>">

                <?= isset($cert['valid_to'])
                    ? date('d.m.Y', strtotime($cert['valid_to']))
                    : 'вЂ”'
                ?>

            </td>

            <td data-label="<?= __t('table_status') ?>">

                <?php
                $certActive =
                    ($cert['is_active'] ?? 0)
                    &&
                    (
                        !isset($cert['valid_to'])
                        ||
                        strtotime($cert['valid_to']) > time()
                    );
                ?>

                <span class="badge <?= $certActive ? 'badge-green' : 'badge-red' ?>">

                    <?= $certActive ? __t('status_valid') : __t('status_expired') ?>

                </span>

            </td>

        </tr>

    <?php endforeach; ?>

    </tbody>

</table>
          <?php endif; ?>
        </div>

        <!-- РРЎРўРћР РРЇ Р’РҐРћР”РћР’ -->
        <div class="card" style="margin-top:16px">
          <div class="card-header">
            <span class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
              <?= __t('profile_history') ?>
            </span>
          </div>
          <div class="session-stack">
            <?php [$currentStatusText, $currentStatusClass] = profileSessionStatusLabel($currentSession); ?>
            <div class="session-current">
              <div class="session-current-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
              </div>
              <div class="session-current-body">
                <div class="session-current-top">
                  <strong>Текущая сессия</strong>
                  <span class="badge <?= $currentStatusClass ?>"><?= $currentStatusText ?></span>
                </div>
                <div class="session-current-meta">
                  <span><?= htmlspecialchars(profileDeviceLabel($currentSession['user_agent'] ?? null)) ?></span>
                  <span><?= htmlspecialchars($currentSession['ip_address'] ?? '—') ?></span>
                  <span><?= htmlspecialchars(profileAuthMethodLabel($currentSession['auth_method'] ?? null)) ?></span>
                  <span>Вход: <?= !empty($currentSession['created_at']) ? date('d.m.Y H:i', strtotime($currentSession['created_at'])) : '—' ?></span>
                  <?php if (!empty($currentSession['expires_at'])): ?>
                    <span>До: <?= date('d.m.Y H:i', strtotime($currentSession['expires_at'])) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="session-subtitle">Предыдущие сессии</div>
            <?php if (!empty($previousSessions)): ?>
              <table class="doc-table session-table">
                <thead>
                  <tr>
                    <th>Дата входа</th>
                    <th><?= __t('table_ip') ?></th>
                    <th>Устройство</th>
                    <th><?= __t('table_method') ?></th>
                    <th><?= __t('table_status') ?></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($previousSessions as $session): ?>
                  <?php [$sessionStatusText, $sessionStatusClass] = profileSessionStatusLabel($session); ?>
                  <tr>
                    <td data-label="Дата входа"><?= !empty($session['created_at']) ? date('d.m.Y H:i', strtotime($session['created_at'])) : '—' ?></td>
                    <td data-label="<?= __t('table_ip') ?>" style="font-family:monospace;font-size:12px"><?= htmlspecialchars($session['ip_address'] ?? '—') ?></td>
                    <td data-label="Устройство"><?= htmlspecialchars(profileDeviceLabel($session['user_agent'] ?? null)) ?></td>
                    <td data-label="<?= __t('table_method') ?>">
                      <span class="badge <?= ($session['auth_method'] ?? '') === 'ecp' ? 'badge-blue' : 'badge-gray' ?>"><?= htmlspecialchars(profileAuthMethodLabel($session['auth_method'] ?? null)) ?></span>
                    </td>
                    <td data-label="<?= __t('table_status') ?>"><span class="badge <?= $sessionStatusClass ?>"><?= $sessionStatusText ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php elseif (!empty($loginHistory)): ?>
              <table class="doc-table session-table">
                <thead>
                  <tr>
                    <th>Дата входа</th>
                    <th><?= __t('table_ip') ?></th>
                    <th>Устройство</th>
                    <th><?= __t('table_method') ?></th>
                    <th><?= __t('table_status') ?></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($loginHistory as $log): ?>
                  <?php [$logStatusText, $logStatusClass] = profileLoginStatusLabel($log); ?>
                  <tr>
                    <td data-label="Дата входа"><?= !empty($log['created_at']) ? date('d.m.Y H:i', strtotime($log['created_at'])) : '—' ?></td>
                    <td data-label="<?= __t('table_ip') ?>" style="font-family:monospace;font-size:12px"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                    <td data-label="Устройство"><?= htmlspecialchars(profileDeviceLabel($log['user_agent'] ?? null)) ?></td>
                    <td data-label="<?= __t('table_method') ?>">
                      <span class="badge <?= ($log['auth_method'] ?? '') === 'ecp' ? 'badge-blue' : 'badge-gray' ?>"><?= htmlspecialchars(profileAuthMethodLabel($log['auth_method'] ?? null)) ?></span>
                    </td>
                    <td data-label="<?= __t('table_status') ?>"><span class="badge <?= $logStatusClass ?>"><?= $logStatusText ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="empty-state session-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/></svg>
                <p>Предыдущих сессий пока нет</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- РўРђР‘: РќРђРЎРўР РћР™РљР -->
  <div id="tab-settings" class="tab-content <?= $activeTab !== 'settings' ? 'tab-hidden' : '' ?>">
    <div class="settings-panel-grid">
      <div>
        <!-- РљРѕРЅС‚Р°РєС‚РЅС‹Рµ РґР°РЅРЅС‹Рµ -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-header">
            <span class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <?= __t('profile_account_settings') ?>
            </span>
          </div>
          <div class="profile-section-title"><?= __t('profile_contacts') ?></div>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_contacts">
            <div class="profile-field">
              <span class="profile-field-label"><?= __t('field_phone') ?></span>
              <input type="text" name="phone" value="<?= htmlspecialchars($phone) ?>" placeholder="+7 (___) ___-__-__"
                style="border:1px solid #e2e6ea;border-radius:6px;padding:6px 10px;font-size:13px;width:220px;outline:none">
              <button type="submit" class="btn-change"><?= __t('btn_change') ?></button>
            </div>
            <div class="profile-field">
              <span class="profile-field-label"><?= __t('field_email') ?></span>
              <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" placeholder="example@mail.com"
                style="border:1px solid #e2e6ea;border-radius:6px;padding:6px 10px;font-size:13px;width:220px;outline:none">
              <button type="submit" class="btn-change"><?= __t('btn_change') ?></button>
            </div>
          </form>
        </div>

        <!-- РЎРјРµРЅР° РїР°СЂРѕР»СЏ -->
        <div class="card">
          <div class="profile-section-title"><?= __t('profile_password_change') ?></div>
          <form method="POST" style="padding:16px 20px;display:flex;flex-direction:column;gap:12px">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="change_password">
            <div>
              <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:5px">Введите текущий пароль</label>
              <input type="password" name="old_password" required
                style="width:100%;max-width:320px;border:1px solid #e2e6ea;border-radius:6px;padding:8px 12px;font-size:13px;outline:none">
            </div>
            <div>
              <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:5px"><?= __t('auth_pass_label') ?></label>
              <input type="password" name="new_password" required minlength="6"
                style="width:100%;max-width:320px;border:1px solid #e2e6ea;border-radius:6px;padding:8px 12px;font-size:13px;outline:none">
            </div>
            <div>
              <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:5px"><?= __t('auth_pass_confirm') ?></label>
              <input type="password" name="confirm_password" required minlength="6"
                style="width:100%;max-width:320px;border:1px solid #e2e6ea;border-radius:6px;padding:8px 12px;font-size:13px;outline:none">
            </div>
            <div>
              <button type="submit" class="btn-find" style="border-radius:6px"><?= __t('btn_save') ?></button>
            </div>
          </form>
        </div>
      </div>

    </div>
  </div>


<style>
.tab-hidden { display: none !important; }
.tab-content { display: block; }

.profile-tabs{
  width:fit-content;
  max-width:100%;
  margin-left:auto;
  margin-right:auto;
}

.profile-panel-grid,
.settings-panel-grid{
  display:grid;
  grid-template-columns:minmax(0, 680px);
  justify-content:center;
  align-items:start;
  max-width:720px;
  margin:0 auto;
}

.page-wrapper{
  flex:1 0 auto;
  width:100%;
}

.site-footer{
  flex-shrink:0;
  width:100%;
}

.session-stack{
  padding:16px;
}

.session-current{
  display:flex;
  align-items:flex-start;
  gap:14px;
  padding:16px;
  border:1px solid #dbeafe;
  border-radius:10px;
  background:#f8fbff;
  margin-bottom:16px;
}

.session-current-icon{
  width:38px;
  height:38px;
  border-radius:10px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:#e0ecff;
  color:#1a3c6e;
  flex-shrink:0;
}

.session-current-icon svg{
  width:20px;
  height:20px;
}

.session-current-body{
  min-width:0;
  flex:1;
}

.session-current-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:8px;
}

.session-current-top strong{
  font-size:14px;
  color:#0f172a;
}

.session-current-meta{
  display:flex;
  align-items:center;
  flex-wrap:wrap;
  gap:7px 12px;
  font-size:12px;
  color:#64748b;
}

.session-current-meta span{
  display:inline-flex;
  align-items:center;
}

.session-current-meta span:not(:last-child)::after{
  content:"";
  width:4px;
  height:4px;
  border-radius:50%;
  background:#cbd5e1;
  margin-left:12px;
}

.session-subtitle{
  font-size:11px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.5px;
  color:#94a3b8;
  margin:4px 0 10px;
}

.session-table th,
.session-table td{
  vertical-align:middle;
}

.session-table td:nth-child(3){
  min-width:150px;
}

.session-empty{
  padding:28px 16px !important;
  border:1px dashed #dbe3ee;
  border-radius:10px;
  background:#fafcff;
}

#tab-settings .profile-field{
  grid-template-columns:160px minmax(0, 260px) auto;
}

#tab-settings .card{
  box-shadow:0 1px 2px rgba(15, 23, 42, .05);
}

#tab-settings form[method="POST"]{
  max-width:520px;
}

@media(max-width:1024px){

  /* GRID */
  .profile-panel-grid,
  .settings-panel-grid{
    grid-template-columns:1fr !important;
    max-width:720px;
  }

}

@media(max-width:768px){

  /* PAGE */
  .page-wrapper{
    padding:16px 0 32px;
  }

  .page-heading h1{
    font-size:18px;
    line-height:1.3;
    word-break:break-word;
  }

  .page-heading p{
    font-size:12px;
    line-height:1.5;
  }

  /* TABS */
  .profile-tabs{
    overflow-x:auto;
    flex-wrap:nowrap;
    scrollbar-width:none;
    -webkit-overflow-scrolling:touch;
    width:100%;
    margin-left:0;
    margin-right:0;
  }

  .profile-tabs::-webkit-scrollbar{
    display:none;
  }

  .profile-tab-btn{
    flex-shrink:0;
    white-space:nowrap;
    padding:10px 14px;
    font-size:12px;
  }

  .profile-tab-btn svg{
    width:14px;
    height:14px;
  }

  /* CARD */
  .card-header{
    padding:12px 14px;
    gap:10px;
    align-items:flex-start;
  }

  .card-title{
    font-size:13px;
    line-height:1.4;
  }

  /* PROFILE FIELDS */
  .profile-field{
    grid-template-columns:1fr !important;
    gap:6px;
    padding:12px 14px;
    align-items:flex-start;
  }

  #tab-settings form[method="POST"]{
    max-width:none;
  }

  .profile-field-label{
    font-size:11px;
  }

  .profile-field-value{
    font-size:13px;
    word-break:break-word;
  }

  .session-stack{
    padding:12px;
  }

  .session-current{
    padding:14px;
  }

  .session-current-top{
    align-items:flex-start;
    flex-direction:column;
  }

  .session-current-meta{
    flex-direction:column;
    align-items:flex-start;
    gap:5px;
  }

  .session-current-meta span::after{
    display:none;
  }

  /* INPUTS */
  .profile-field input{
    width:100% !important;
    max-width:none !important;
  }

  /* PASSWORD FORM */
  form[method="POST"] input[type="password"]{
    max-width:none !important;
  }

  /* BUTTONS */
  .btn-change,
  .btn-find,
  .btn-submit{
    width:100%;
    justify-content:center;
  }

  /* TABLES */
  .doc-table thead{
    display:none;
  }

  .doc-table,
  .doc-table tbody,
  .doc-table tr,
  .doc-table td{
    display:block;
    width:100%;
  }

  .doc-table tbody tr{
    border:1px solid #eef0f3;
    border-radius:10px;
    margin:10px 0;
    overflow:hidden;
    background:#fff;
  }

  .doc-table td{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:10px 14px;
    border-bottom:1px solid #f3f4f6;
    font-size:12px;
  }

  .doc-table td:last-child{
    border-bottom:none;
  }

  .doc-table td::before{
    content:attr(data-label);
    font-size:10px;
    font-weight:700;
    color:#9ca3af;
    text-transform:uppercase;
    letter-spacing:.4px;
    flex-shrink:0;
  }

  /* EMPTY STATE */
  .empty-state{
    padding:24px 14px !important;
  }

}

@media(max-width:480px){

  .container{
    padding:0 10px;
  }

  .breadcrumb{
    font-size:11px;
    flex-wrap:wrap;
  }

  .page-heading{
    margin-bottom:16px;
  }

  .page-heading h1{
    font-size:16px;
  }

  .profile-tab-btn{
    padding:9px 12px;
    font-size:11px;
  }

  .card{
    border-radius:8px;
  }

  .badge{
    font-size:10px;
    padding:3px 8px;
  }

}
</style>

<script>

function switchTab(tab, btn){

  document.querySelectorAll('.tab-content')
    .forEach(el => el.classList.add('tab-hidden'));

  document.querySelectorAll('.profile-tab-btn')
    .forEach(el => el.classList.remove('active'));

  document
    .getElementById('tab-' + tab)
    .classList.remove('tab-hidden');

  btn.classList.add('active');
}

</script>

<!-- РњРћР”РђР›РљРђ РЎРћР—Р”РђРќРРЇ Р­Р¦Рџ -->
<div id="ecpModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:28px 32px;width:420px;max-width:95vw;position:relative;box-shadow:0 8px 32px rgba(0,0,0,.18)">
    <button onclick="closeEcpModal()" style="position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:50%;border:1px solid #e2e6ea;background:#fff;color:#6b7280;font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer">Г—</button>

    <h3 style="font-size:16px;font-weight:700;color:#1a1a2e;margin-bottom:8px"><?= __t('auth_ecp_creating') ?></h3>
    <p style="font-size:12px;color:#6b7280;margin-bottom:20px"><?= __t('auth_ecp_creating_hint') ?></p>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="generate_ecp">
      
      <div style="margin-bottom:16px">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px"><?= __t('auth_ecp_pass_label') ?></label>
        <input type="password" name="ecp_password" required minlength="4" placeholder="<?= __t('auth_pass_min_hint') ?>"
               style="width:100%;padding:10px 14px;border:1px solid #e2e6ea;border-radius:8px;font-size:13px;outline:none">
      </div>

      <div style="display:flex;gap:10px;margin-top:24px">
        <button type="submit" style="flex:1;padding:11px;background:#c8962a;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer"><?= __t('btn_generate') ?> Р­Р¦Рџ</button>
        <button type="button" onclick="closeEcpModal()" style="padding:11px 18px;background:#fff;color:#374151;border:1px solid #e2e6ea;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer"><?= __t('btn_cancel') ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openEcpModal() {
    document.getElementById('ecpModal').style.display = 'flex';
}
function closeEcpModal() {
    const modal = document.getElementById('ecpModal');
    if (modal) modal.style.display = 'none';
}
const ecpModal = document.getElementById('ecpModal');
if (ecpModal) {
    ecpModal.addEventListener('click', function(e) {
        if (e.target === this) closeEcpModal();
    });
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
