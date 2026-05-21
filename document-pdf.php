<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$docId  = (int)($_GET['id']   ?? 0);
$inline = (int)($_GET['view'] ?? 0);

if (!$docId) { http_response_code(404); exit('Документ не найден'); }

try {
    $stmt = $db->prepare("
        SELECT ud.*, dt.name AS type_name, dt.code AS type_code,
               COALESCE(ud.submitted_at, ud.signed_at, ud.created_at) AS display_date
        FROM user_documents ud
        JOIN document_types dt ON ud.doc_type_id = dt.id
        WHERE ud.id = ? AND ud.user_id = ?
    ");
    $stmt->execute([$docId, $userId]);
    $doc = $stmt->fetch();
} catch (Exception $e) { $doc = null; }

if (!$doc) { http_response_code(403); exit('Доступ запрещён'); }

$formData = [];
if (!empty($doc['form_data'])) {
    $formData = json_decode($doc['form_data'], true) ?? [];
}

// ── Русские названия полей ──────────────────────────────────────
$fieldLabels = [
    // Общие
    'tax_base'          => 'Налогооблагаемая база',
    'tax_calc'          => 'Исчисленный налог',
    'tax_due'           => 'Налог к уплате',
    'tax_paid'          => 'Уплаченный налог',
    'tax_rate'          => 'Налоговая ставка (%)',
    'tax_amount'        => 'Сумма налога',
    'total_income'      => 'Общий доход',
    'total_prop'        => 'Итого стоимость имущества',
    'asset_cash'          => 'Наличные деньги',
    'asset_deposit'       => 'Деньги на банковских счетах',
    'asset_realty'        => 'Стоимость недвижимости (активы)',
    'asset_transport'     => 'Стоимость транспорта (активы)',
    'asset_securities'    => 'Ценные бумаги и доли участия',
    'asset_other'         => 'Прочие активы',
    'liab_loans'          => 'Банковские кредиты и займы',
    'liab_mortgage'       => 'Ипотечные займы',
    'liab_other'          => 'Прочие обязательства',
    'total_assets'        => 'Итого активы',
    'total_liab'          => 'Итого обязательства',
    'net_worth'           => 'Чистые активы (собственный капитал)',
    'revenue'           => 'Доход (выручка)',
    'prop_realty_count' => 'Количество объектов недвижимости',
    'prop_transp_count' => 'Количество транспортных средств',

    // Доходы ф.240
    'inc_salary'        => 'Доход от работодателя (зарплата)',
    'inc_dividends'     => 'Дивиденды',
    'inc_rent'          => 'Доход от аренды имущества',
    'inc_sale_realty'   => 'Доход от продажи недвижимости',
    'inc_sale_transport'=> 'Доход от продажи транспортных средств',
    'inc_other'         => 'Прочие доходы',

    // Вычеты
    'deduct_pension'    => 'Вычет: пенсионные взносы (ОПВ)',
    'deduct_medical'    => 'Вычет: медицинские расходы',
    'deduct_education'  => 'Вычет: расходы на обучение',
    'deduct_mortgage'   => 'Вычет: вознаграждение по ипотеке',

    // Работник ф.200
    'emp_ipn_income'    => 'ИПН — облагаемый доход',
    'emp_ipn_amount'    => 'ИПН — сумма налога',
    'emp_opv_income'    => 'ОПВ — доход',
    'emp_opv_amount'    => 'ОПВ — сумма взносов',
    'emp_osms_income'   => 'ОСМС — доход',
    'emp_osms_amount'   => 'ОСМС — сумма взносов',
    'emp_vosms_income'  => 'ВОСМС — доход',
    'emp_vosms_amount'  => 'ВОСМС — сумма',
    'emp_so_income'     => 'СО — доход',
    'emp_so_amount'     => 'СО — сумма отчислений',
    'emp_sn_income'     => 'СН — доход',
    'emp_sn_amount'     => 'СН — сумма налога',

    // ИП ф.910
    'ip_opv_income'     => 'ОПВ (ИП) — доход',
    'ip_opv_amount'     => 'ОПВ (ИП) — сумма взносов',
    'ip_so_income'      => 'СО (ИП) — доход',
    'ip_so_amount'      => 'СО (ИП) — сумма отчислений',
    'ip_vosms_income'   => 'ВОСМС (ИП) — доход',
    'ip_vosms_amount'   => 'ВОСМС (ИП) — сумма',
    'ipn_income'        => 'ИПН — доход',
    'ipn_base'          => 'ИПН — налогооблагаемая база',
    'ipn_deduct'        => 'ИПН — вычеты',
    'ipn_rate'          => 'ИПН — ставка (%)',
    'ipn_amount'        => 'Сумма ИПН',
    'sn_so'             => 'СН за вычетом СО',
    'sn_rate'           => 'Ставка СН (%)',
    'so_rate'           => 'Ставка СО (%)',
    'opv_rate'          => 'Ставка ОПВ (%)',
    'emp_count'         => 'Количество работников',
    'osms_rate'         => 'Ставка ОСМС (%)',
    'vosms_rate'        => 'Ставка ВОСМС (%)',
    'tax_rate'          => 'Ставка налога (%)',
    'tax_amount'        => 'Сумма налога',
    'tax_office_code'   => 'Код налогового органа',
    'bin'               => 'БИН',
    'phone'             => 'Телефон',
    'total_deduct'      => 'Итого вычеты',
    'total_due'         => 'Итого к уплате',
    'opv_income'        => 'ОПВ — доход',
    'opv_amount'        => 'ОПВ — сумма',
    'osms_income'       => 'ОСМС — доход',
    'osms_amount'       => 'ОСМС — сумма',
    'vosms_income'      => 'ВОСМС — доход',
    'vosms_amount'      => 'ВОСМС — сумма',
    'so_income'         => 'СО — доход',
    'so_amount'         => 'СО — сумма',
    'sn_income'         => 'СН — доход',
    'sn_amount'         => 'СН — сумма',

    // Имущество ф.700
    'income_work'       => 'Доходы от трудовой деятельности',
    'income_business'   => 'Доходы от предпринимательской деятельности',
    'income_property'   => 'Доходы от имущества',
    'income_other'      => 'Прочие доходы',
    'asset_realty'      => 'Недвижимое имущество',
    'asset_transport'   => 'Транспортные средства',
    'asset_securities'  => 'Ценные бумаги',
    'asset_deposit'     => 'Банковские депозиты',
    'asset_cash'        => 'Наличные денежные средства',
    'asset_other'       => 'Прочие активы',
    'liab_mortgage'     => 'Ипотечные обязательства',
    'liab_loans'        => 'Займы и кредиты',
    'liab_other'        => 'Прочие обязательства',

    // Налог на имущество/транспорт/землю ф.700
    'prop_realty_val'   => 'Стоимость недвижимости',
    'prop_transp_val'   => 'Стоимость транспортных средств',
    'prop_other_val'    => 'Стоимость прочего имущества',
    'prop_value'        => 'Общая стоимость имущества',
    'prop_rate'         => 'Ставка налога на имущество (%)',
    'prop_tax'          => 'Исчисленный налог на имущество',
    'prop_paid'         => 'Уплаченный налог на имущество',
    'prop_due'          => 'Налог на имущество к доплате',
    'trans_tax'         => 'Исчисленный транспортный налог',
    'trans_paid'        => 'Уплаченный транспортный налог',
    'trans_due'         => 'Транспортный налог к доплате',
    'land_area'         => 'Площадь земельного участка (кв.м)',
    'land_rate'         => 'Ставка земельного налога',
    'land_tax'          => 'Исчисленный земельный налог',
    'land_paid'         => 'Уплаченный земельный налог',
    'land_due'          => 'Земельный налог к доплате',
];

// ── Статус ─────────────────────────────────────────────────────
$statusLabels = [
    'draft'     => 'Черновик',
    'submitted' => 'Подан',
    'in_review' => 'На рассмотрении',
    'accepted'  => 'Принят',
    'rejected'  => 'Отклонён',
    'cancelled' => 'Отменён',
];
$statusLabel = $statusLabels[$doc['status']] ?? $doc['status'];

$statusColors = [
    'accepted'              => ['bg'=>'#d1fae5','color'=>'#065f46'],
    'rejected'              => ['bg'=>'#fee2e2','color'=>'#991b1b'],
    'submitted'             => ['bg'=>'#dbeafe','color'=>'#1e40af'],
    'in_review'             => ['bg'=>'#dbeafe','color'=>'#1e40af'],
    'draft'                 => ['bg'=>'#f3f4f6','color'=>'#374151'],
    'cancelled'             => ['bg'=>'#f3f4f6','color'=>'#374151'],
];
$sc = $statusColors[$doc['status']] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];

$displayDate = !empty($doc['display_date']) ? date('d.m.Y H:i', strtotime($doc['display_date'])) : '—';
$year        = !empty($doc['tax_period_year']) ? (int)$doc['tax_period_year'] : date('Y');

// Группы полей для красивого разделения по разделам
$fieldGroups = [
    'Доходы' => ['inc_salary','inc_dividends','inc_rent','inc_sale_realty','inc_sale_transport','inc_other',
                 'income_work','income_business','income_property','income_other','revenue'],
    'Вычеты' => ['deduct_pension','deduct_medical','deduct_education','deduct_mortgage',
                 'total_deduct','ipn_deduct'],
    'Налог на доходы (ИПН)' => ['ipn_income','ipn_base','tax_base','total_income','tax_rate',
                                 'tax_calc','tax_amount','tax_paid','tax_due'],
    'ОПВ / ВОСМС / ОСМС / СО / СН' => ['opv_income','opv_amount','osms_income','osms_amount',
                                          'vosms_income','vosms_amount','so_income','so_amount',
                                          'sn_income','sn_amount',
                                          'emp_ipn_income','emp_ipn_amount',
                                          'emp_opv_income','emp_opv_amount',
                                          'emp_osms_income','emp_osms_amount',
                                          'emp_vosms_income','emp_vosms_amount',
                                          'emp_so_income','emp_so_amount',
                                          'emp_sn_income','emp_sn_amount',
                                          'ip_opv_income','ip_opv_amount',
                                          'ip_so_income','ip_so_amount',
                                          'ip_vosms_income','ip_vosms_amount'],
    'Имущество и активы' => ['asset_realty','asset_transport','asset_securities','asset_deposit',
                              'asset_cash','asset_other','total_assets',
                              'prop_realty_val','prop_transp_val','prop_other_val','prop_value'],
    'Обязательства' => ['liab_mortgage','liab_loans','liab_other','total_liab','net_worth'],
    'Налог на имущество' => ['prop_rate','prop_tax','prop_paid','prop_due'],
    'Транспортный налог' => ['trans_tax','trans_paid','trans_due'],
    'Земельный налог' => ['land_area','land_rate','land_tax','land_paid','land_due'],
    'Итого' => ['total_prop','total_due'],
];

// Сортируем formData по группам
$grouped = [];
$used    = [];
foreach ($fieldGroups as $groupName => $keys) {
    foreach ($keys as $k) {
        if (isset($formData[$k]) && $formData[$k] !== null && $formData[$k] !== '') {
            $grouped[$groupName][$k] = $formData[$k];
            $used[] = $k;
        }
    }
}
// Остаток (неизвестные ключи)
$rest = [];
foreach ($formData as $k => $v) {
    if (!in_array($k, $used) && $v !== null && $v !== '') {
        $rest[$k] = $v;
    }
}

ob_start(); ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Документ №<?= $docId ?> — <?= htmlspecialchars($doc['type_code']) ?></title>
<style>
  @page { margin: 18mm 16mm; }
  *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, 'Helvetica Neue', sans-serif; font-size: 12px; color: #1a1a2e; background:#f4f7fa; margin:0; padding:0; }

  /* ── ПРЕДПРОСМОТР В БРАУЗЕРЕ ── */
  .page-container {
    background: #fff;
    width: 210mm;
    min-height: 297mm;
    margin: 30px auto;
    padding: 20mm 15mm;
    box-shadow: 0 0 20px rgba(0,0,0,0.1);
    position: relative;
    overflow: hidden;
  }

  /* ── ПАНЕЛЬ ПЕЧАТИ ── */
  .print-toolbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
    background: #1a3c6e;
    padding: 10px 24px; display: flex; align-items: center; justify-content: space-between;
    font-family: Arial, sans-serif; font-size: 13px; color: #fff;
    box-shadow: 0 2px 12px rgba(0,0,0,0.35);
  }
  .print-toolbar-left { display: flex; align-items: center; gap: 10px; }
  .print-toolbar-right { display: flex; gap: 10px; }
  .print-btn-main {
    background: #c8962a; color: #fff; border: none;
    padding: 9px 20px; border-radius: 7px; font-size: 13px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; gap: 6px;
  }
  .print-btn-secondary {
    background: rgba(255,255,255,0.12); color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    padding: 9px 16px; border-radius: 7px; font-size: 13px; cursor: pointer;
  }

  @media (max-width: 210mm) {
    .page-container {
      width: 100%;
      margin: 0;
      padding: 10mm 8mm;
      box-shadow: none;
    }
    .doc-header {
      flex-direction: column;
      gap: 15px;
      align-items: center;
      text-align: center;
    }
    .doc-meta {
      text-align: center;
    }
    .logo-area {
      flex-direction: column;
      text-align: center;
    }
    .doc-footer {
      flex-direction: column;
      gap: 15px;
      text-align: center;
    }
    .doc-footer div {
      text-align: center !important;
      width: 100%;
    }
    .print-toolbar {
      padding: 10px 16px !important;
      flex-direction: column !important;
      gap: 12px !important;
      height: auto !important;
    }
    .print-toolbar > div {
      width: 100%;
      justify-content: center !important;
    }
    .print-spacer {
      height: 100px !important;
    }
    table.info td:first-child {
      width: 45%;
    }
  }

  /* ── ШАПКА ── */
  .doc-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    border-bottom: 2px solid #1a3c6e; padding-bottom: 15px; margin-bottom: 25px;
  }
  .logo-area { display:flex; align-items:center; gap:15px; }
  .logo-circle {
    width:54px; height:54px; border-radius:50%; background:#1a3c6e;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
    border: 2px solid #c8962a;
  }
  .logo-circle svg { width:32px; height:32px; }
  .org-name  { font-size:13px; font-weight:700; color:#1a3c6e; line-height:1.3; }
  .org-sub   { font-size:10px; color:#6b7280; margin-top:3px; text-transform: uppercase; letter-spacing: 0.5px; }
  .doc-meta  { text-align:right; font-size:11px; color:#4b5563; line-height:1.6; }
  .doc-meta .doc-num { font-size:16px; font-weight:800; color:#111827; margin-bottom: 4px; }
  .status-badge {
    display:inline-block; padding:4px 12px; border-radius:6px;
    font-size:11px; font-weight:700; margin-top: 5px;
    background:<?= $sc['bg'] ?>; color:<?= $sc['color'] ?>;
    border: 1px solid rgba(0,0,0,0.05);
  }

  /* ── ЗАГОЛОВОК ── */
  .doc-title { text-align:center; margin-bottom:30px; }
  .doc-title h1 { font-size:20px; font-weight:800; color:#111827; margin-bottom:8px; line-height: 1.2; }
  .doc-title p  { font-size:12px; color:#6b7280; font-weight: 500; }
  .divider-gold { height:4px; background:#c8962a; border-radius:2px; margin:15px auto 0; width:80px; }

  /* ── СЕКЦИЯ ── */
  .section-title {
    font-size:13px; font-weight:700; color:#1a3c6e;
    padding: 8px 0; margin-bottom:12px; margin-top:25px;
    border-bottom: 1px solid #e5e7eb;
    text-transform: uppercase; letter-spacing: 0.5px;
  }
  .section-title:first-of-type { margin-top:0; }

  /* ── ТАБЛИЦА ИНФО ── */
  table.info { width:100%; border-collapse:collapse; margin-bottom:10px; }
  table.info td {
    padding: 10px 14px; border:1px solid #e5e7eb;
    font-size:12px; vertical-align:middle;
  }
  table.info td:first-child {
    font-weight:600; color:#4b5563; width:35%;
    background:#f9fafb;
  }
  table.info td:last-child { color:#111827; font-weight:600; }

  /* ── ТАБЛИЦА ПОЛЕЙ ── */
  table.fields { width:100%; border-collapse:collapse; margin-bottom:10px; }
  table.fields thead th {
    background:#1f2937; color:#fff;
    padding: 12px 14px; font-size:11px; text-align:left;
    text-transform: uppercase; letter-spacing: 0.5px;
  }
  table.fields thead th:last-child { text-align:right; width:30%; }
  table.fields tbody td {
    padding: 10px 14px; border:1px solid #e5e7eb; font-size:12px;
  }
  table.fields tbody td:last-child { text-align:right; font-weight:700; color:#1a3c6e; }
  table.fields tbody tr:nth-child(even) td { background:#f9fafb; }

  /* ── ГРУППА-ЗАГОЛОВОК ── */
  .group-header td {
    background:#f3f4f6 !important; font-weight:800 !important;
    color:#374151 !important; font-size:11px !important;
    text-transform: uppercase; letter-spacing: 0.5px;
    padding:10px 14px !important;
    border-top: 2px solid #d1d5db !important;
  }

  /* ── ШТАМП ── */
  .official-stamp {
    position: absolute; bottom: 80px; right: 60px;
    width: 140px; height: 140px; opacity: 0.15;
    pointer-events: none;
  }

  /* ── ПОДВАЛ ── */
  .doc-footer {
    margin-top:40px; padding-top:20px;
    border-top: 2px solid #1a3c6e;
    display:flex; justify-content:space-between; align-items:flex-start;
    font-size:10px; color:#6b7280; line-height:1.6;
  }
  .footer-left strong { color: #374151; }

  @media print {
    body { background: #fff; }
    .page-container { margin:0; padding:0; box-shadow:none; width:100%; }
    .print-toolbar { display:none !important; }
    .print-spacer  { display:none !important; }
    .official-stamp { opacity: 0.1; }
  }
</style>
</head>
<body>

<div class="page-container">
  <!-- ШТАМП (SVG) -->
  <div class="official-stamp">
    <svg viewBox="0 0 200 200">
      <circle cx="100" cy="100" r="90" fill="none" stroke="#1a3c6e" stroke-width="2"/>
      <circle cx="100" cy="100" r="70" fill="none" stroke="#1a3c6e" stroke-width="1"/>
      <text x="50%" y="45%" text-anchor="middle" fill="#1a3c6e" font-size="12" font-weight="bold">КОМИТЕТ</text>
      <text x="50%" y="55%" text-anchor="middle" fill="#1a3c6e" font-size="12" font-weight="bold">ГОСУДАРСТВЕННЫХ</text>
      <text x="50%" y="65%" text-anchor="middle" fill="#1a3c6e" font-size="12" font-weight="bold">ДОХОДОВ</text>
      <path d="M40 100 Q100 40 160 100 T40 100" fill="none" stroke="#c8962a" stroke-width="2" opacity="0.5"/>
    </svg>
  </div>

  <!-- ШАПКА ДОКУМЕНТА -->
  <div class="doc-header">
    <div class="logo-area">
      <div class="logo-circle">
        <svg viewBox="0 0 32 32" fill="none">
          <circle cx="16" cy="16" r="14" stroke="#c8962a" stroke-width="2"/>
          <path d="M8 22L16 8L24 22" stroke="#c8962a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M10 18h12" stroke="#c8962a" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
      <div>
        <div class="org-name">Комитет государственных<br>доходов МФ РК</div>
        <div class="org-sub">Информационная система «Кабинет налогоплательщика»</div>
      </div>
    </div>
    <div class="doc-meta">
      <div class="doc-num">Электронный документ №<?= $docId ?></div>
      Дата формирования: <?= date('d.m.Y H:i') ?><br>
      Статус обработки: <span class="status-badge"><?= $statusLabel ?></span>
    </div>
  </div>

  <!-- ЗАГОЛОВОК -->
  <div class="doc-title">
    <h1><?= htmlspecialchars($doc['type_name']) ?></h1>
    <p>Код формы: <?= htmlspecialchars($doc['type_code']) ?> &nbsp;·&nbsp; Налоговый период: <?= $year ?> год</p>
    <div class="divider-gold"></div>
  </div>

  <!-- ОБЩАЯ ИНФОРМАЦИЯ -->
  <div class="section-title">Сведения о налогоплательщике и документе</div>
  <table class="info">
    <tr><td>Наименование / ФИО</td><td><?= htmlspecialchars($user['full_name'] ?? '—') ?></td></tr>
    <tr><td>ИИН / БИН</td>        <td><?= htmlspecialchars($user['iin'] ?? '—') ?></td></tr>
    <tr><td>Регистрационный номер</td><td><?= $docId ?>-<?= date('Y', strtotime($doc['created_at'])) ?></td></tr>
    <tr><td>Дата и время приема</td><td><?= $displayDate ?></td></tr>
    <tr><td>Способ приема</td>     <td>Электронный (через Кабинет налогоплательщика)</td></tr>
    <tr><td>Текущий статус</td>    <td><strong><?= $statusLabel ?></strong></td></tr>
  </table>

  <!-- ДАННЫЕ ФОРМЫ -->
  <div class="section-title">Показатели налоговой отчетности</div>
  <?php if (!empty($grouped) || !empty($rest)): ?>
  <table class="fields">
    <thead>
      <tr>
        <th>Наименование показателя</th>
        <th>Значение</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($grouped as $groupName => $fields): ?>
      <tr class="group-header"><td colspan="2"><?= htmlspecialchars($groupName) ?></td></tr>
      <?php foreach ($fields as $k => $v): ?>
      <tr>
        <td><?= htmlspecialchars($fieldLabels[$k] ?? ucfirst(str_replace('_',' ',$k))) ?></td>
        <td><?= htmlspecialchars(is_array($v) ? implode(', ', $v) : (string)$v) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <?php foreach ($rest as $k => $v): ?>
      <tr>
        <td><?= htmlspecialchars($fieldLabels[$k] ?? ucfirst(str_replace('_',' ',$k))) ?></td>
        <td><?= htmlspecialchars(is_array($v) ? implode(', ', $v) : (string)$v) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="empty-note">Детализированные данные по форме отсутствуют.</div>
  <?php endif; ?>

  <?php if (!empty($doc['comment']) || !empty($doc['reject_reason'])): ?>
  <div class="section-title">Результат обработки / Уведомление</div>
  <div style="padding: 15px; background: #fff7ed; border: 1px solid #ffedd5; border-radius: 8px; font-size: 12px; color: #9a3412; line-height: 1.5;">
    <strong>Сообщение системы:</strong><br>
    <?= nl2br(htmlspecialchars($doc['comment'] ?? $doc['reject_reason'])) ?>
  </div>
  <?php endif; ?>

  <!-- ПОДВАЛ -->
  <div class="doc-footer">
    <div class="footer-left">
      <strong>Комитет государственных доходов МФ РК</strong><br>
      Центр поддержки пользователей: 1414<br>
      Веб-портал: kgd.gov.kz
    </div>
    <div style="text-align:right">
      Документ сформирован автоматически в ИС КНП<br>
      Идентификатор проверки: <?= md5($docId . $userId . 'salt') ?><br>
      Пользователь: <?= htmlspecialchars($user['iin'] ?? '') ?>
    </div>
  </div>
</div> <!-- /page-container -->

</body>
</html>
<?php
$html = ob_get_clean();

// ── Панель управления (скрывается при печати) ─────────────────
$printBar = '
<div class="print-toolbar">
  <div class="print-toolbar-left">
    <div style="width:32px;height:32px;border-radius:50%;background:rgba(200,150,42,.25);display:flex;align-items:center;justify-content:center;font-size:16px;">📄</div>
    <div>
      <div style="font-weight:700;font-size:14px;">Документ №' . $docId . ' — ' . htmlspecialchars($doc['type_code']) . '</div>
      <div style="font-size:10px;color:rgba(255,255,255,.6);">' . htmlspecialchars($doc['type_name']) . '</div>
    </div>
  </div>
  <div class="print-toolbar-right">
    <button onclick="window.print()" class="print-btn-main">
      🖨 Печать / Сохранить PDF
    </button>
    <button onclick="if(window.opener || window.history.length === 1) { window.close(); } else { window.location.href=\'documents.php\'; }" class="print-btn-secondary">
      ✕ Закрыть
    </button>
  </div>
</div>
<div class="print-spacer" style="height:54px;"></div>
';

echo str_replace('<body>', '<body>' . $printBar, $html);
exit;
