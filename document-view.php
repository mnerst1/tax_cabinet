<?php
if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst(string $str): string {
        return mb_strtoupper(mb_substr($str, 0, 1)) . mb_substr($str, 1);
    }
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$docId = (int)($_GET['id'] ?? 0);
if (!$docId) {
    header('Location: ' . SITE_URL . '/documents.php');
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT ud.*, dt.name AS type_name, dt.code AS type_code, dt.description AS type_desc,
               COALESCE(ud.submitted_at, ud.signed_at, ud.created_at) AS display_date
        FROM user_documents ud
        JOIN document_types dt ON ud.doc_type_id = dt.id
        WHERE ud.id = ? AND ud.user_id = ?
    ");
    $stmt->execute([$docId, $userId]);
    $doc = $stmt->fetch();
} catch (Exception $e) { $doc = null; }

if (!$doc) {
    header('Location: ' . SITE_URL . '/documents.php');
    exit;
}

$formData = [];
if (!empty($doc['form_data'])) {
    $formData = json_decode($doc['form_data'], true) ?? [];
}

$unreadCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $s->execute([$userId]);
    $unreadCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

$statusMap = [
    'draft'     => ['bg' => '#f3f4f6', 'color' => '#374151', 'label' => 'Черновик',        'icon' => '📝'],
    'submitted' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'label' => 'Подан',            'icon' => '📤'],
    'in_review' => ['bg' => '#fef3c7', 'color' => '#92400e', 'label' => 'На рассмотрении', 'icon' => '🔍'],
    'accepted'  => ['bg' => '#d1fae5', 'color' => '#065f46', 'label' => 'Принят',           'icon' => '✅'],
    'rejected'  => ['bg' => '#fee2e2', 'color' => '#991b1b', 'label' => 'Отклонён',         'icon' => '❌'],
    'cancelled' => ['bg' => '#f3f4f6', 'color' => '#6b7280', 'label' => 'Отменён',          'icon' => '🚫'],
];
$st = $statusMap[$doc['status']] ?? $statusMap['draft'];

// ══════════════════════════════════════════════════
// ПОЛНЫЙ СЛОВАРЬ ПЕРЕВОДОВ ВСЕХ ПОЛЕЙ ФОРМ
// ══════════════════════════════════════════════════
$fieldLabels = [
    // Контактные данные
    'phone'               => 'Телефон',
    'email'               => 'Электронная почта',
    'address'             => 'Адрес',
    'residence_address'   => 'Адрес проживания',
    'iin'                 => 'ИИН',
    'bin'                 => 'БИН',
    'full_name'           => 'ФИО',
    'tax_office_code'     => __t('f_tax_office_code'),
    'bin'                 => __t('f_bin'),
    'phone'               => __t('f_phone'),
    'sn_so'               => __t('f_sn_so'),
    'sn_rate'             => __t('f_sn_rate'),
    'so_rate'             => __t('f_so_rate'),
    'opv_rate'            => __t('f_opv_rate'),
    'emp_count'           => __t('f_emp_count'),
    'osms_rate'           => __t('f_osms_rate'),
    'vosms_rate'          => __t('f_vosms_rate'),
    'ipn_amount'          => __t('f_ipn_amount'),
    'ipn_income'          => __t('f_ipn_income'),
    'tax_rate'            => __t('f_tax_rate'),
    'tax_amount'          => __t('f_tax_amount'),
    'sn_rate'             => 'Ставка СН (%)',
    'so_rate'             => 'Ставка СО (%)',
    'opv_rate'            => 'Ставка ОПВ (%)',
    'sn_income'           => 'Доход для СН',
    'so_income'           => 'Доход для СО',
    'opv_income'          => 'Доход для ОПВ',
    'osms_income'         => 'Доход для ОСМС',
    'vosms_income'        => 'Доход для ВОСМС',
    'period'              => 'Отчётный период',
    'year'                => 'Год',
    'half'                => 'Полугодие',
    'quarter'             => 'Квартал',
    'report_year'         => 'Отчётный год',
    'report_period'       => 'Период отчётности',
    'correction_num'      => 'Номер корректировки',
    'is_correction'       => 'Корректировочная декларация',

    // Доходы
    'revenue'             => 'Доход за период',
    'income'              => 'Доход',
    'inc_salary'        => __t('f_inc_salary'),
    'inc_dividends'     => __t('f_inc_dividends'),
    'inc_rent'          => __t('f_inc_rent'),
    'inc_sale_realty'   => __t('f_inc_sale_realty'),
    'inc_sale_transport'=> __t('f_inc_sale_transport'),
    'inc_other'         => __t('f_inc_other'),
    'income_property'     => 'Доход от имущества',
    'total_income'        => __t('f_total_income'),
    'total_prop'          => __t('f_total_prop'),
    'prop_realty_count'   => __t('f_prop_realty_count'),
    'prop_realty_val'     => __t('f_prop_realty_val'),
    'prop_transp_count'   => __t('f_prop_transp_count'),
    'prop_transp_val'     => __t('f_prop_transp_val'),
    'prop_other_val'      => __t('f_prop_other_val'),

    // Форма 250
    'asset_cash'          => __t('f_asset_cash'),
    'asset_deposit'       => __t('f_asset_deposit'),
    'asset_realty'        => __t('f_asset_realty'),
    'asset_transport'     => __t('f_asset_transport'),
    'asset_securities'    => __t('f_asset_securities'),
    'asset_other'         => __t('f_asset_other'),
    'liab_loans'          => __t('f_liab_loans'),
    'liab_mortgage'       => __t('f_liab_mortgage'),
    'liab_other'          => __t('f_liab_other'),
    'total_assets'        => __t('f_total_assets'),
    'total_liab'          => __t('f_total_liab'),
    'net_worth'           => __t('f_net_worth'),

    // Вычеты
    'total_deduct'        => 'Общая сумма вычетов',
    'deduct_medical'      => 'Вычет на медицину',
    'deduct_pension'      => 'Вычет на пенсионные взносы',
    'deduct_mortgage'     => 'Вычет по ипотеке',
    'deduct_education'    => 'Вычет на образование',
    'deduct_standard'     => 'Стандартный вычет',
    'deduct_other'        => 'Прочие вычеты',

    // Налоговая база и расчёт
    'tax_base'            => 'Налогооблагаемая база',
    'tax_calc'            => 'Исчисленный налог',
    'tax_due'             => 'Налог к уплате',
    'tax_paid'            => 'Уплаченный налог',
    'tax_rate'            => 'Ставка налога',
    'tax_amount'          => 'Сумма налога',
    'total_due'           => 'Итого к уплате',
    'total'               => 'Итого',
    'amount'              => 'Сумма',

    // ИПН
    'ipn_amount'          => 'Индивидуальный подоходный налог (ИПН)',
    'ipn_income'          => 'ИПН — доход',
    'ipn_base'            => 'ИПН — налогооблагаемая база',
    'ipn_deduct'          => 'ИПН — вычеты',
    'ipn_rate'            => 'ИПН — ставка (%)',
    'total_deduct'        => 'Итого вычеты (ИПН)',
    'total_due'           => 'Итого к уплате (ИПН)',

    // Социальный налог (СН)
    'sn_amount'           => 'Социальный налог (СН)',
    'sn_rate'             => 'Ставка СН',
    'sn_income'           => 'Доход для расчёта СН',

    // ОПВ
    'opv_amount'          => 'ОПВ — сумма',
    'opv_rate'            => 'Ставка ОПВ',
    'opv_income'          => 'ОПВ — доход',

    // СО
    'so_amount'           => 'Социальные отчисления (СО)',
    'so_rate'             => 'Ставка СО',
    'so_income'           => 'Доход для расчёта СО',

    // ОСМС (отчисления работодателя)
    'osms_amount'         => 'Отчисления на ОСМС',
    'osms_rate'           => 'Ставка ОСМС',
    'osms_income'         => 'Доход для расчёта ОСМС',

    // ВОСМС (взносы работника)
    'vosms_amount'        => 'Взносы на ОСМС (работник)',
    'vosms_rate'          => 'Ставка ВОСМС',
    'vosms_income'        => 'Доход для расчёта ВОСМС',

    // Форма 910 — ИП (собственные взносы)
    'emp_count'           => 'Количество работников',
    'ip_so_amount'        => 'Социальные отчисления (ИП)',
    'ip_so_income'        => 'Доход ИП для расчёта СО',
    'ip_opv_amount'       => 'ОПВ за ИП',
    'ip_opv_income'       => 'Доход ИП для расчёта ОПВ',
    'ip_osms_amount'      => 'ОСМС за ИП',
    'ip_osms_income'      => 'Доход ИП для расчёта ОСМС',
    'ip_vosms_amount'     => 'ВОСМС (ИП) — сумма',
    'ip_vosms_income'     => 'ВОСМС (ИП) — доход',

    // Форма 910 — работники
    'emp_sn_amount'       => 'Социальный налог за работников',
    'emp_sn_income'       => 'Доход работников для расчёта СН',
    'emp_so_amount'       => 'Социальные отчисления за работников',
    'emp_so_income'       => 'Доход работников для расчёта СО',
    'emp_opv_amount'      => 'ОПВ за работников',
    'emp_opv_income'      => 'Доход работников для расчёта ОПВ',
    'emp_ipn_amount'      => 'ИПН за работников',
    'emp_ipn_income'      => 'Доход работников для расчёта ИПН',
    'emp_osms_amount'     => 'ОСМС за работников',
    'emp_osms_income'     => 'Доход работников для расчёта ОСМС',
    'emp_vosms_amount'    => 'ВОСМС за работников',
    'emp_vosms_income'    => 'Доход работников для расчёта ВОСМС',

    // Земельный налог (700)
    'land_area'           => 'Площадь земельного участка (кв.м)',
    'land_rate'           => 'Ставка земельного налога',
    'land_tax'            => 'Сумма земельного налога',
    'land_purpose'        => 'Целевое назначение земли',
    'cadastral_number'    => 'Кадастровый номер',

    // Налог на имущество (701)
    'property_value'      => 'Среднегодовая стоимость имущества',
    'property_rate'       => 'Ставка налога на имущество',
    'property_tax'        => 'Сумма налога на имущество',
    'property_type'       => 'Вид имущества',

    // НДС (320)
    'vat_taxable'         => 'Облагаемый оборот (НДС)',
    'vat_exempt'          => 'Необлагаемый оборот',
    'vat_rate'            => 'Ставка НДС',
    'vat_amount'          => 'Сумма НДС к уплате',
    'vat_credit'          => 'НДС в зачёт',
    'vat_payable'         => 'НДС к уплате в бюджет',

    // Транспортный налог
    'vehicle_type'        => 'Вид транспортного средства',
    'engine_volume'       => 'Объём двигателя (куб.см)',
    'transport_tax'       => 'Сумма транспортного налога',
    'vehicle_count'       => 'Количество транспортных средств',

    // Прочее
    'note'                => 'Примечание',
    'comment'             => 'Комментарий',
    'reg_number'          => 'Регистрационный номер',
    'okved'               => 'Вид деятельности (ОКЭД)',
    'activity_type'       => 'Вид деятельности',
    'tax_office'          => 'Налоговый орган',
    'sign_date'           => 'Дата подписания',
];
$moneyFields = [
    'revenue','income','income_work','income_other','income_business','income_property','total_income',
    'total_deduct','deduct_medical','deduct_pension','deduct_mortgage','deduct_education','deduct_standard','deduct_other',
    'tax_base','tax_calc','tax_due','tax_paid','tax_amount','total_due','total','amount',
    'ip_so_amount','ip_so_income','ip_opv_amount','ip_opv_income','ip_osms_amount','ip_osms_income','ip_vosms_amount','ip_vosms_income',
    'emp_sn_amount','emp_sn_income','emp_so_amount','emp_so_income',
    'emp_opv_amount','emp_opv_income','emp_ipn_amount','emp_ipn_income',
    'emp_osms_amount','emp_osms_income','emp_vosms_amount','emp_vosms_income',
    'ipn_amount','ipn_base','ipn_income','ipn_deduct',
    'sn_amount','sn_income','so_amount','so_income',
    'opv_amount','opv_income','osms_amount','osms_income','vosms_amount','vosms_income',
    'land_tax','property_value','property_tax',
    'vat_taxable','vat_exempt','vat_amount','vat_credit','vat_payable',
    'transport_tax',
];
// Поля со знаком %
$percentFields = [
    'tax_rate','land_rate','property_rate','vat_rate',
    'ipn_rate','sn_rate','opv_rate','so_rate',
    'osms_rate','vosms_rate',
];
$pageTitle = 'Документ #' . $docId . ' — ' . SITE_NAME;
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
.view-layout{display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start}
.view-card{background:#fff;border:1px solid #e2e6ea;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.07)}
.view-card-header{padding:20px 24px;border-bottom:1px solid #f0f2f5;background:linear-gradient(135deg,#1a3c6e 0%,#1a5fa8 100%);color:#fff}
.view-card-header-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.view-doc-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700}
.view-doc-title{font-size:20px;font-weight:700;margin-bottom:4px;line-height:1.3}
.view-doc-code{font-size:13px;opacity:.75}
.view-meta-row{display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.15);font-size:12px;color:rgba(255,255,255,.8)}
.view-meta-item{display:flex;align-items:center;gap:5px}
.view-meta-item strong{color:#fff}
.view-section{padding:20px 24px;border-bottom:1px solid #f0f2f5}
.view-section:last-child{border-bottom:none}
.view-section-title{font-size:13px;font-weight:700;color:#1a1a2e;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.view-section-title::after{content:'';flex:1;height:1px;background:#f0f2f5}
.info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
.info-label{font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.info-value{font-size:13px;font-weight:600;color:#1a1a2e}
.timeline{display:flex;flex-direction:column;gap:0}
.timeline-item{display:flex;align-items:flex-start;gap:14px;position:relative}
.timeline-item:not(:last-child)::before{content:'';position:absolute;left:13px;top:28px;width:2px;height:calc(100% - 4px);background:#e2e6ea}
.timeline-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;position:relative;z-index:1}
.timeline-dot.done{background:#d1fae5}
.timeline-dot.active{background:#dbeafe;box-shadow:0 0 0 3px rgba(59,130,246,.2)}
.timeline-dot.pending{background:#f3f4f6;opacity:.5}
.timeline-body{padding:4px 0 16px;flex:1}
.timeline-label{font-size:13px;font-weight:600;color:#1a1a2e;line-height:1.3}
.timeline-label.pending{color:#9ca3af}
.timeline-date{font-size:11px;color:#9ca3af;margin-top:2px}
.fields-table{width:100%;border-collapse:collapse;font-size:13px}
.fields-table tr{border-bottom:1px solid #f0f2f5}
.fields-table tr:last-child{border-bottom:none}
.fields-table td{padding:10px 0;vertical-align:middle}
.fields-table td:first-child{color:#6b7280;width:60%;padding-right:16px}
.fields-table td:last-child{font-weight:600;color:#1a1a2e;text-align:right}
.sidebar-block{background:#fff;border:1px solid #e2e6ea;border-radius:11px;overflow:hidden;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.sidebar-block-header{padding:12px 16px;border-bottom:1px solid #f0f2f5;font-size:13px;font-weight:700;color:#1a1a2e}
.sidebar-block-body{padding:14px 16px}
.action-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:11px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:background .15s,transform .1s;text-decoration:none;margin-bottom:8px}
.action-btn:last-child{margin-bottom:0}
.action-btn:active{transform:scale(.98)}
.action-btn svg{width:15px;height:15px;flex-shrink:0}
.btn-primary{background:#1a3c6e;color:#fff}
.btn-primary:hover{background:#122d54}
.btn-secondary{background:#f0f4ff;color:#1a3c6e;border:1.5px solid #c7d9f5}
.btn-secondary:hover{background:#e0eaff}
.btn-danger{background:#fef2f2;color:#dc2626;border:1.5px solid #fca5a5}
.btn-danger:hover{background:#fee2e2}
.status-display{display:flex;align-items:center;gap:12px;padding:12px;border-radius:9px;margin-bottom:12px}
.status-display-icon{font-size:24px}
.status-display-label{font-size:14px;font-weight:700}
.status-display-sub{font-size:12px;color:#6b7280;margin-top:2px}
.sidebar-info-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f7f8fa;font-size:12px}
.sidebar-info-row:last-child{border-bottom:none}
.sidebar-info-row .s-label{color:#9ca3af}
.sidebar-info-row .s-value{font-weight:600;color:#1a1a2e;text-align:right}
@media(max-width:900px){.view-layout{grid-template-columns:1fr}.info-grid{grid-template-columns:1fr}}
</style>

<div class="page-wrapper">
  <div class="container">

    <div class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard.php">Главная</a>
      <span>—</span>
      <a href="<?= SITE_URL ?>/documents.php">Мои документы</a>
      <span>—</span>
      <span><?= htmlspecialchars($doc['type_code']) ?></span>
    </div>

    <div class="view-layout">

      <!-- ОСНОВНАЯ КАРТОЧКА -->
      <div>
        <div class="view-card">

          <!-- Шапка -->
          <div class="view-card-header">
            <div class="view-card-header-top">
              <div>
                <div class="view-doc-title"><?= htmlspecialchars($doc['type_name']) ?></div>
                <div class="view-doc-code">Форма <?= htmlspecialchars($doc['type_code']) ?></div>
              </div>
              <span class="view-doc-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>">
                <?= $st['icon'] ?> <?= $st['label'] ?>
              </span>
            </div>
            <div class="view-meta-row">
              <div class="view-meta-item">📋 Номер: <strong>#<?= $docId ?></strong></div>
              <div class="view-meta-item">📅 Подан: <strong><?= !empty($doc['display_date']) ? date('d.m.Y', strtotime($doc['display_date'])) : '—' ?></strong></div>
              <div class="view-meta-item">👤 ИИН/БИН: <strong><?= htmlspecialchars($user['iin'] ?? '—') ?></strong></div>
              <?php if (!empty($doc['tax_period_year'])): ?>
              <div class="view-meta-item">📆 Год: <strong><?= (int)$doc['tax_period_year'] ?></strong></div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Общая информация -->
          <div class="view-section">
            <div class="view-section-title">📄 Общая информация</div>
            <div class="info-grid">
              <div>
                <div class="info-label">Наименование</div>
                <div class="info-value"><?= htmlspecialchars($user['full_name'] ?? '—') ?></div>
              </div>
              <div>
                <div class="info-label">ИИН / БИН</div>
                <div class="info-value"><?= htmlspecialchars($user['iin'] ?? '—') ?></div>
              </div>
              <div>
                <div class="info-label">Вид документа</div>
                <div class="info-value"><?= htmlspecialchars($doc['type_name']) ?></div>
              </div>
              <div>
                <div class="info-label">Код формы</div>
                <div class="info-value"><?= htmlspecialchars($doc['type_code']) ?></div>
              </div>
              <div>
                <div class="info-label">Дата подачи</div>
                <div class="info-value"><?= !empty($doc['display_date']) ? date('d.m.Y H:i', strtotime($doc['display_date'])) : '—' ?></div>
              </div>
              <div>
                <div class="info-label">Статус</div>
                <div class="info-value"><?= $st['icon'] . ' ' . $st['label'] ?></div>
              </div>
              <?php if (!empty($doc['tax_period_year'])): ?>
              <div>
                <div class="info-label">Отчётный период</div>
                <div class="info-value"><?= (int)$doc['tax_period_year'] ?> год</div>
              </div>
              <?php endif; ?>
              <?php if (!empty($doc['period'])): ?>
              <div>
                <div class="info-label">Период</div>
                <div class="info-value"><?= htmlspecialchars($doc['period']) ?></div>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Данные формы -->
          <?php if (!empty($formData)): ?>
          <div class="view-section">
            <div class="view-section-title">📊 Данные формы</div>
            <table class="fields-table">
              <?php foreach ($formData as $key => $val): ?>
              <?php if ($val === null || $val === '') continue; ?>
              <?php
                // Перевод ключа
                $label = $fieldLabels[$key] ?? mb_ucfirst(str_replace('_', ' ', $key));

                // Форматирование значения
                if (is_array($val)) {
                    $formatted = htmlspecialchars(implode(', ', $val));
                } elseif (in_array($key, $moneyFields) && is_numeric($val)) {
                    $formatted = '<span style="font-family:monospace">'
                        . number_format((float)$val, 2, '.', ' ')
                        . '</span> <span style="color:#9ca3af;font-weight:400;font-size:12px">₸</span>';
                } elseif (in_array($key, $percentFields) && is_numeric($val)) {
                    $formatted = htmlspecialchars((string)$val)
                        . ' <span style="color:#9ca3af;font-weight:400;font-size:12px">%</span>';
                } elseif ($key === 'is_correction') {
                    $formatted = $val ? 'Да' : 'Нет';
                } else {
                    $formatted = htmlspecialchars((string)$val);
                }
              ?>
              <tr>
                <td><?= htmlspecialchars($label) ?></td>
                <td><?= $formatted ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
          </div>
          <?php else: ?>
          <div class="view-section">
            <div class="view-section-title">📊 Данные формы</div>
            <table class="fields-table">
              <tr><td>Вид декларации</td><td><?= htmlspecialchars($doc['type_name']) ?></td></tr>
              <tr><td>Налогоплательщик</td><td><?= htmlspecialchars($user['full_name'] ?? '—') ?></td></tr>
              <tr><td>ИИН / БИН</td><td><?= htmlspecialchars($user['iin'] ?? '—') ?></td></tr>
              <?php if (!empty($doc['tax_period_year'])): ?>
              <tr><td>Отчётный год</td><td><?= (int)$doc['tax_period_year'] ?></td></tr>
              <?php endif; ?>
              <tr><td>Дата и время подачи</td><td><?= !empty($doc['display_date']) ? date('d.m.Y H:i:s', strtotime($doc['display_date'])) : '—' ?></td></tr>
              <tr><td>Статус обработки</td><td><?= $st['icon'] . ' ' . $st['label'] ?></td></tr>
              <tr><td>Номер документа</td><td>#<?= $docId ?></td></tr>
            </table>
          </div>
          <?php endif; ?>

          <!-- История статусов -->
          <div class="view-section">
            <div class="view-section-title">🕓 История статусов</div>
            <div class="timeline">

              <div class="timeline-item">
                <div class="timeline-dot done">✅</div>
                <div class="timeline-body">
                  <div class="timeline-label">Документ создан</div>
                  <div class="timeline-date"><?= !empty($doc['created_at']) ? date('d.m.Y H:i', strtotime($doc['created_at'])) : '—' ?></div>
                </div>
              </div>

              <?php
              $isSubmitted = in_array($doc['status'], ['submitted','in_review','accepted','rejected']);
              $submitDate  = !empty($doc['submitted_at'])
                  ? date('d.m.Y H:i', strtotime($doc['submitted_at']))
                  : (!empty($doc['display_date']) ? date('d.m.Y H:i', strtotime($doc['display_date'])) : '—');
              ?>
              <div class="timeline-item">
                <div class="timeline-dot <?= $isSubmitted ? 'done' : 'pending' ?>"><?= $isSubmitted ? '📤' : '⏳' ?></div>
                <div class="timeline-body">
                  <div class="timeline-label <?= !$isSubmitted ? 'pending' : '' ?>">Документ подан</div>
                  <div class="timeline-date"><?= $isSubmitted ? $submitDate : 'Ожидается' ?></div>
                </div>
              </div>

              <?php $isReview = in_array($doc['status'], ['in_review','accepted','rejected']); ?>
              <div class="timeline-item">
                <div class="timeline-dot <?= $isReview ? 'done' : ($isSubmitted ? 'active' : 'pending') ?>"><?= $isReview ? '🔍' : '⏳' ?></div>
                <div class="timeline-body">
                  <div class="timeline-label <?= !$isReview && !$isSubmitted ? 'pending' : '' ?>">На рассмотрении</div>
                  <div class="timeline-date">
                    <?= $isReview && !empty($doc['updated_at']) ? date('d.m.Y H:i', strtotime($doc['updated_at'])) : ($isSubmitted ? 'В обработке' : 'Ожидается') ?>
                  </div>
                </div>
              </div>

              <?php
              $isFinal    = in_array($doc['status'], ['accepted','rejected','cancelled']);
              $finalIcon  = $doc['status'] === 'accepted' ? '✅' : ($doc['status'] === 'rejected' ? '❌' : '🚫');
              $finalLabel = $doc['status'] === 'accepted' ? 'Принят' : ($doc['status'] === 'rejected' ? 'Отклонён' : 'Ожидание результата');
              ?>
              <div class="timeline-item">
                <div class="timeline-dot <?= $isFinal ? 'done' : 'pending' ?>"><?= $isFinal ? $finalIcon : '⏳' ?></div>
                <div class="timeline-body">
                  <div class="timeline-label <?= !$isFinal ? 'pending' : '' ?>"><?= $finalLabel ?></div>
                  <div class="timeline-date">
                    <?= $isFinal && !empty($doc['updated_at']) ? date('d.m.Y H:i', strtotime($doc['updated_at'])) : 'Ожидается' ?>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Комментарий органа -->
          <?php if (!empty($doc['comment']) || !empty($doc['reject_reason'])): ?>
          <div class="view-section">
            <div class="view-section-title">💬 Комментарий органа</div>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;font-size:13px;color:#92400e;line-height:1.6">
              <?= nl2br(htmlspecialchars($doc['comment'] ?? $doc['reject_reason'])) ?>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </div>

      <!-- САЙДБАР -->
      <aside>

        <div class="sidebar-block">
          <div class="sidebar-block-header">📊 Статус документа</div>
          <div class="sidebar-block-body">
            <div class="status-display" style="background:<?= $st['bg'] ?>">
              <div class="status-display-icon"><?= $st['icon'] ?></div>
              <div>
                <div class="status-display-label" style="color:<?= $st['color'] ?>"><?= $st['label'] ?></div>
                <div class="status-display-sub">
                  <?php
                  $subTexts = [
                      'draft'     => 'Не подан в орган',
                      'submitted' => 'Передан в ОГД',
                      'in_review' => 'Проверяется инспектором',
                      'accepted'  => 'Принят без замечаний',
                      'rejected'  => 'Требует исправлений',
                      'cancelled' => 'Документ отменён',
                  ];
                  echo $subTexts[$doc['status']] ?? '';
                  ?>
                </div>
              </div>
            </div>
            <div>
              <div class="sidebar-info-row">
                <span class="s-label">Документ №</span>
                <span class="s-value">#<?= $docId ?></span>
              </div>
              <div class="sidebar-info-row">
                <span class="s-label">Форма</span>
                <span class="s-value"><?= htmlspecialchars($doc['type_code']) ?></span>
              </div>
              <div class="sidebar-info-row">
                <span class="s-label">Дата подачи</span>
                <span class="s-value"><?= !empty($doc['display_date']) ? date('d.m.Y', strtotime($doc['display_date'])) : '—' ?></span>
              </div>
              <?php if (!empty($doc['tax_period_year'])): ?>
              <div class="sidebar-info-row">
                <span class="s-label">Отчётный год</span>
                <span class="s-value"><?= (int)$doc['tax_period_year'] ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="sidebar-block">
          <div class="sidebar-block-header">⚡ Действия</div>
          <div class="sidebar-block-body">
            <a href="<?= SITE_URL ?>/document-pdf.php?id=<?= $docId ?>&view=1"
               target="_blank" class="action-btn btn-secondary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
              </svg>
              Просмотреть PDF
            </a>
            <a href="<?= SITE_URL ?>/document-pdf.php?id=<?= $docId ?>&view=0"
               class="action-btn btn-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
              </svg>
              Скачать PDF
            </a>
            <a href="<?= SITE_URL ?>/documents.php" class="action-btn btn-secondary" style="margin-top:8px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"/>
              </svg>
              Назад к документам
            </a>
          </div>
        </div>

        <div class="sidebar-block">
          <div class="sidebar-block-header">❓ Вопросы?</div>
          <div class="sidebar-block-body" style="font-size:12px;color:#374151;line-height:1.6">
            <p style="margin-bottom:10px">Если у вас есть вопросы по данному документу, обратитесь в службу поддержки:</p>
            <div style="display:flex;flex-direction:column;gap:6px">
              <a href="https://t.me/FinQoldau_bot" target="_blank"
                 style="display:flex;align-items:center;gap:6px;color:#1a5fa8;font-weight:600;font-size:12px">
                💬 @FinQoldau_bot
              </a>
              <a href="tel:1414"
                 style="display:flex;align-items:center;gap:6px;color:#1a5fa8;font-weight:600;font-size:12px">
                ☎️ 1414 — контакт-центр
              </a>
            </div>
          </div>
        </div>

      </aside>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>