<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$unreadCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $s->execute([$userId]);
    $unreadCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

$pageTitle = 'Налоговый калькулятор — ' . SITE_NAME;
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
.calc-grid{display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start}
.calc-sidebar{display:flex;flex-direction:column;gap:12px}
.calc-mode-btn{display:flex;align-items:center;gap:12px;padding:14px 16px;background:#fff;border:1px solid #e2e6ea;border-radius:10px;cursor:pointer;text-align:left;transition:all .15s;font-family:inherit;width:100%}
.calc-mode-btn:hover{border-color:#1a3c6e;background:#f5f8ff}
.calc-mode-btn.active{border-color:#1a3c6e;background:#eef2fb;box-shadow:0 0 0 2px rgba(26,60,110,.12)}
.calc-mode-icon{width:40px;height:40px;border-radius:10px;background:#1a3c6e;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.calc-mode-icon svg{width:20px;height:20px;color:#fff}
.calc-mode-btn.active .calc-mode-icon{background:#c8962a}
.calc-mode-title{font-size:13px;font-weight:600;color:#1a1a2e;line-height:1.3}
.calc-mode-sub{font-size:11px;color:#9ca3af;margin-top:2px}

.calc-panel{display:none}
.calc-panel.active{display:block}

.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px}
.form-label span{color:#9ca3af;font-weight:400;margin-left:4px}
.form-input,.form-select{width:100%;padding:10px 14px;border:1px solid #e2e6ea;border-radius:8px;font-size:13px;color:#1a1a2e;outline:none;background:#fff;transition:border-color .15s,box-shadow .15s;font-family:inherit}
.form-input:focus,.form-select:focus{border-color:#1a3c6e;box-shadow:0 0 0 3px rgba(26,60,110,.08)}
.form-input::placeholder{color:#b0b7c3}
.form-input-prefix{display:flex;align-items:center;border:1px solid #e2e6ea;border-radius:8px;overflow:hidden;background:#fff;transition:border-color .15s,box-shadow .15s}
.form-input-prefix:focus-within{border-color:#1a3c6e;box-shadow:0 0 0 3px rgba(26,60,110,.08)}
.form-prefix-label{padding:10px 12px;background:#f8fafc;border-right:1px solid #e2e6ea;font-size:12px;color:#6b7280;font-weight:500;white-space:nowrap;flex-shrink:0}
.form-prefix-input{flex:1;padding:10px 14px;border:none;outline:none;font-size:13px;color:#1a1a2e;font-family:inherit;background:transparent;min-width:0}
.form-suffix-label{padding:10px 12px;background:#f8fafc;border-left:1px solid #e2e6ea;font-size:12px;color:#6b7280;font-weight:500;white-space:nowrap;flex-shrink:0}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}

.calc-result{background:linear-gradient(135deg,#1a3c6e 0%,#122d54 100%);border-radius:12px;padding:24px;color:#fff;margin-top:20px}
.calc-result-title{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.6);margin-bottom:16px}
.calc-result-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
.calc-result-item{background:rgba(255,255,255,.08);border-radius:8px;padding:14px}
.calc-result-item-label{font-size:11px;color:rgba(255,255,255,.6);margin-bottom:4px}
.calc-result-item-value{font-size:17px;font-weight:700;color:#fff;word-break:break-word}
.calc-result-item.accent{background:rgba(200,150,42,.2);border:1px solid rgba(200,150,42,.4)}
.calc-result-item.accent .calc-result-item-value{color:#f0c060}
.calc-result-item.full{grid-column:1/-1}
.calc-result-divider{border:none;border-top:1px solid rgba(255,255,255,.12);margin:14px 0}
.calc-result-note{font-size:11px;color:rgba(255,255,255,.5);line-height:1.5}

.btn-calc{width:100%;padding:12px;background:#c8962a;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;transition:background .15s;font-family:inherit;margin-top:4px}
.btn-calc:hover{background:#a87820}

.mrp-hint{background:#f0f4ff;border:1px solid #c7d7f5;border-radius:8px;padding:10px 14px;font-size:12px;color:#374151;margin-bottom:14px;display:flex;align-items:flex-start;gap:8px;line-height:1.6}
.mrp-hint svg{flex-shrink:0;margin-top:2px;color:#1a3c6e;width:14px;height:14px}

.employer-box{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:12px 14px;margin-top:4px}
.employer-box-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:rgba(255,255,255,.5);margin-bottom:10px}
.employer-box-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.employer-box-item-label{font-size:10px;color:rgba(255,255,255,.45);margin-bottom:3px}
.employer-box-item-value{font-size:14px;font-weight:700;color:#fff}

@media(max-width:900px){.calc-grid{grid-template-columns:1fr}}
@media(max-width:600px){.form-row{grid-template-columns:1fr}.calc-result-grid{grid-template-columns:1fr}.employer-box-grid{grid-template-columns:1fr 1fr}}
</style>

<div class="page-wrapper">
  <div class="container">

    <div class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard.php"><?= __t('nav_main') ?></a>
      <span>—</span>
      <span><?= __t('nav_calc') ?></span>
    </div>

    <div class="page-heading">
      <h1><?= __t('nav_calc') ?></h1>
      <p>Расчёт налоговых обязательств по актуальным ставкам 2026 года</p>
    </div>

    <div class="calc-grid">

      <!-- ===== БОКОВАЯ ПАНЕЛЬ ===== -->
      <div class="calc-sidebar">
        <div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;padding:0 4px;margin-bottom:4px"><?= __t('calc_mode_select') ?></div>

        <button class="calc-mode-btn active" onclick="switchMode('iit')" id="btn-iit">
          <div class="calc-mode-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
          </div>
          <div>
            <div class="calc-mode-title"><?= __t('calc_iit_title') ?></div>
            <div class="calc-mode-sub"><?= __t('calc_iit_sub') ?></div>
          </div>
        </button>

        <button class="calc-mode-btn" onclick="switchMode('sn')" id="btn-sn">
          <div class="calc-mode-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
            </svg>
          </div>
          <div>
            <div class="calc-mode-title"><?= __t('calc_sn_title') ?></div>
            <div class="calc-mode-sub"><?= __t('calc_sn_sub') ?></div>
          </div>
        </button>

        <button class="calc-mode-btn" onclick="switchMode('pat')" id="btn-pat">
          <div class="calc-mode-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
            </svg>
          </div>
          <div>
            <div class="calc-mode-title"><?= __t('calc_pat_title') ?></div>
            <div class="calc-mode-sub"><?= __t('calc_pat_sub') ?></div>
          </div>
        </button>

        <button class="calc-mode-btn" onclick="switchMode('vat')" id="btn-vat">
          <div class="calc-mode-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
          </div>
          <div>
            <div class="calc-mode-title"><?= __t('calc_vat_title') ?></div>
            <div class="calc-mode-sub"><?= __t('calc_vat_sub') ?></div>
          </div>
        </button>

        <button class="calc-mode-btn" onclick="switchMode('salary')" id="btn-salary">
          <div class="calc-mode-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>
            </svg>
          </div>
          <div>
            <div class="calc-mode-title"><?= __t('calc_salary_title') ?></div>
            <div class="calc-mode-sub"><?= __t('calc_salary_sub') ?></div>
          </div>
        </button>

        <button class="calc-mode-btn" onclick="switchMode('ip')" id="btn-ip">
          <div class="calc-mode-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>
            </svg>
          </div>
          <div>
            <div class="calc-mode-title"><?= __t('calc_ip_title') ?></div>
            <div class="calc-mode-sub"><?= __t('calc_ip_sub') ?></div>
          </div>
        </button>

      </div>

      <!-- ===== ПРАВАЯ ПАНЕЛЬ ===== -->
      <div>

        <div class="calc-panel active card" id="panel-iit">
          <div class="card-header">
            <div class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1a3c6e" stroke-width="2" width="16" height="16"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <?= __t('calc_iit_title') ?> — <?= __t('calc_iit_sub') ?>
            </div>
          </div>
          <div style="padding:20px">
            <div class="mrp-hint">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div>МРП 2026: <strong>4 325 тг</strong>. Ставки: <strong>10%</strong> — до 8 500 МРП/год (36,7 млн тг), <strong>15%</strong> — с суммы превышения. Стандартный вычет: <strong>30 МРП = 129 750 тг/мес</strong> (1 557 000 тг/год).</div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label"><?= __t('calc_income_label') ?> <span>(тенге)</span></label>
                <div class="form-input-prefix">
                  <span class="form-prefix-label">₸</span>
                  <input type="number" class="form-prefix-input" id="iit_income" placeholder="0" min="0" oninput="calcIIT()">
                </div>
              </div>
              <div class="form-group">
                <label class="form-label"><?= __t('calc_expenses_label') ?> <span>(вычеты)</span></label>
                <div class="form-input-prefix">
                  <span class="form-prefix-label">₸</span>
                  <input type="number" class="form-prefix-input" id="iit_expenses" placeholder="0" min="0" oninput="calcIIT()">
                </div>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label"><?= __t('calc_taxpayer_type') ?></label>
              <select class="form-select" id="iit_type" onchange="calcIIT()">
                <option value="resident"><?= __t('calc_resident') ?> — 10% / 15% (прогрессивно)</option>
                <option value="nonresident"><?= __t('calc_nonresident') ?> — 20% (без вычетов)</option>
              </select>
            </div>

            <button class="btn-calc" onclick="calcIIT()"><?= __t('calc_btn_calculate') ?></button>

            <div class="calc-result" id="result-iit" style="display:none">
              <div class="calc-result-title"><?= __t('calc_result_title') ?></div>
              <div class="calc-result-grid">
                <div class="calc-result-item">
                  <div class="calc-result-item-label"><?= __t('calc_income_label') ?></div>
                  <div class="calc-result-item-value" id="r-iit-income">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">Вычеты (стандарт + расходы)</div>
                  <div class="calc-result-item-value" id="r-iit-deduct">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">Налогооблагаемый доход</div>
                  <div class="calc-result-item-value" id="r-iit-taxable">—</div>
                </div>
                <div class="calc-result-item accent">
                  <div class="calc-result-item-label"><?= __t('calc_tax_to_pay') ?></div>
                  <div class="calc-result-item-value" id="r-iit-tax">—</div>
                </div>
              </div>
              <hr class="calc-result-divider">
              <div class="calc-result-note">* Расчёт приблизительный. Стандартный вычет применяется только для резидентов. Для нерезидентов ставка 20% без вычетов.</div>
            </div>
          </div>
        </div>

        <div class="calc-panel card" id="panel-sn">
          <div class="card-header">
            <div class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1a3c6e" stroke-width="2" width="16" height="16"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
              <?= __t('calc_sn_title') ?> (форма 910.00)
            </div>
          </div>
          <div style="padding:20px">
            <div class="mrp-hint">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div>С 2026 года ставка: <strong>4%</strong> — единый платёж (не делится на ИПН и соцналог). Маслихат вправе изменить до 2–6%. Лимит дохода: <strong>600 000 МРП/год (~2,595 млрд ₸)</strong>. ИП на упрощёнке <strong>освобождён от НДС</strong>.</div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Доход за полугодие <span>(тенге)</span></label>
                <div class="form-input-prefix">
                  <span class="form-prefix-label">₸</span>
                  <input type="number" class="form-prefix-input" id="sn_income" placeholder="0" min="0" oninput="calcSN()">
                </div>
              </div>
              <div class="form-group">
                <label class="form-label"><?= __t('calc_rate_label') ?> <span>(%)</span></label>
                <div class="form-input-prefix">
                  <input type="number" class="form-prefix-input" id="sn_rate" value="4" min="2" max="6" step="0.5" oninput="calcSN()">
                  <span class="form-suffix-label">%</span>
                </div>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Полугодие</label>
              <select class="form-select" id="sn_period">
                <option value="1">1-е полугодие (январь — июнь)</option>
                <option value="2">2-е полугодие (июль — декабрь)</option>
              </select>
            </div>

            <button class="btn-calc" onclick="calcSN()"><?= __t('calc_btn_calculate') ?></button>

            <div class="calc-result" id="result-sn" style="display:none">
              <div class="calc-result-title"><?= __t('calc_result_title') ?> СНР (910.00) 2026</div>
              <div class="calc-result-grid">
                <div class="calc-result-item">
                  <div class="calc-result-item-label">Доход за полугодие</div>
                  <div class="calc-result-item-value" id="r-sn-income">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">Применяемая ставка</div>
                  <div class="calc-result-item-value" id="r-sn-rate">—</div>
                </div>
                <div class="calc-result-item accent full">
                  <div class="calc-result-item-label">Единый налог к уплате</div>
                  <div class="calc-result-item-value" id="r-sn-total">—</div>
                </div>
                <div class="calc-result-item full" style="background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3)">
                  <div class="calc-result-item-label">✓ Освобождение от НДС</div>
                  <div class="calc-result-item-value" style="font-size:13px;color:#6ee7b7">Плательщики СНР 910 освобождены от постановки на учёт по НДС с 2026 года</div>
                </div>
              </div>
              <hr class="calc-result-divider">
              <div class="calc-result-note">* С 2026 налог не делится на ИПН и соцналог — уплачивается единым платежом. Отчётность раз в полугодие.</div>
            </div>
          </div>
        </div>

        <!-- ПАТЕНТ -->
        <div class="calc-panel card" id="panel-pat">
          <div class="card-header">
            <div class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1a3c6e" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <?= __t('calc_pat_title') ?> (форма 911.00)
            </div>
          </div>
          <div style="padding:20px">
            <div class="mrp-hint">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div>Ставка: <strong>1%</strong> от заявленного дохода. Применяется только для ИП без работников. Лимит дохода: <strong>3 524 МРП/год (~15,2 млн ₸)</strong>.</div>
            </div>

            <div class="form-group">
              <label class="form-label">Предполагаемый доход <span>(тенге)</span></label>
              <div class="form-input-prefix">
                <span class="form-prefix-label">₸</span>
                <input type="number" class="form-prefix-input" id="pat_income" placeholder="0" min="0" oninput="calcPAT()">
              </div>
            </div>

            <button class="btn-calc" onclick="calcPAT()"><?= __t('calc_btn_calculate') ?></button>

            <div class="calc-result" id="result-pat" style="display:none">
              <div class="calc-result-title"><?= __t('calc_result_title') ?> (Патент)</div>
              <div class="calc-result-grid">
                <div class="calc-result-item full">
                  <div class="calc-result-item-label">Предполагаемый доход</div>
                  <div class="calc-result-item-value" id="r-pat-income">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">ИПН — стоимость патента (1%)</div>
                  <div class="calc-result-item-value" id="r-pat-ipn">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">ОПВ (10% от МЗП — мин.)</div>
                  <div class="calc-result-item-value" id="r-pat-opv">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">СО (5% от МЗП — мин.)</div>
                  <div class="calc-result-item-value" id="r-pat-so">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">ВОСМС (5% × 1,4 × МЗП)</div>
                  <div class="calc-result-item-value" id="r-pat-vosms">—</div>
                </div>
                <div class="calc-result-item accent full">
                  <div class="calc-result-item-label">Итого к уплате</div>
                  <div class="calc-result-item-value" id="r-pat-total">—</div>
                </div>
              </div>
              <hr class="calc-result-divider">
              <div class="calc-result-note" id="r-pat-note">* Социальные платежи рассчитаны от минимальной базы (1 МЗП). Реальные суммы зависят от фактического дохода.</div>
            </div>
          </div>
        </div>

        <!-- НДС -->
        <div class="calc-panel card" id="panel-vat">
          <div class="card-header">
            <div class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1a3c6e" stroke-width="2" width="16" height="16"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              <?= __t('calc_vat_title') ?> — Налог на добавленную стоимость
            </div>
          </div>
          <div style="padding:20px">
            <div class="mrp-hint">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div>Ставка с 2026 года: <strong>16%</strong> (была 12%). Порог постановки на учёт: <strong>20 000 МРП/год (~86,5 млн ₸)</strong>.</div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Сумма оборота <span>(тенге)</span></label>
                <div class="form-input-prefix">
                  <span class="form-prefix-label">₸</span>
                  <input type="number" class="form-prefix-input" id="vat_amount" placeholder="0" min="0" oninput="calcVAT()">
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Тип расчёта</label>
                <select class="form-select" id="vat_mode" onchange="calcVAT()">
                  <option value="add">Начислить сверху (+16%)</option>
                  <option value="extract">Выделить из суммы (в т.ч. 16%)</option>
                </select>
              </div>
            </div>

            <button class="btn-calc" onclick="calcVAT()"><?= __t('calc_btn_calculate') ?></button>

            <div class="calc-result" id="result-vat" style="display:none">
              <div class="calc-result-title"><?= __t('calc_result_title') ?> НДС (16%)</div>
              <div class="calc-result-grid">
                <div class="calc-result-item">
                  <div class="calc-result-item-label">Сумма без НДС</div>
                  <div class="calc-result-item-value" id="r-vat-base">—</div>
                </div>
                <div class="calc-result-item accent">
                  <div class="calc-result-item-label">Сумма НДС (16%)</div>
                  <div class="calc-result-item-value" id="r-vat-tax">—</div>
                </div>
                <div class="calc-result-item full" style="background:rgba(255,255,255,.1)">
                  <div class="calc-result-item-label">Сумма с НДС</div>
                  <div class="calc-result-item-value" id="r-vat-total">—</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ЗАРПЛАТА -->
        <div class="calc-panel card" id="panel-salary">
          <div class="card-header">
            <div class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1a3c6e" stroke-width="2" width="16" height="16"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              <?= __t('calc_salary_title') ?> 2026
            </div>
          </div>
          <div style="padding:20px">
            <div class="mrp-hint">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div>МЗП: <strong>85 000 тг</strong>, МРП: <strong>4 325 тг</strong>. Удержания: ОПВ 10%, ВОСМС 2%, ИПН 10%/15% (вычет 30 МРП = 129 750 тг/мес). Нагрузка работодателя сверх оклада: СО 5%, ОСМС 3%, ОПВР 3,5%.</div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Оклад брутто <span>(тенге)</span></label>
                <div class="form-input-prefix">
                  <span class="form-prefix-label">₸</span>
                  <input type="number" class="form-prefix-input" id="sal_gross" placeholder="0" min="0" oninput="calcSalary()">
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Иждивенцы <span>(дети и др.)</span></label>
                <input type="number" class="form-input" id="sal_depend" placeholder="0" min="0" value="0" oninput="calcSalary()">
              </div>
            </div>

            <button class="btn-calc" onclick="calcSalary()"><?= __t('calc_btn_calculate') ?></button>

            <div class="calc-result" id="result-salary" style="display:none">
              <div class="calc-result-title"><?= __t('calc_result_title') ?> 2026</div>
              <div class="calc-result-grid">
                <div class="calc-result-item full">
                  <div class="calc-result-item-label">Начислено (брутто)</div>
                  <div class="calc-result-item-value" id="r-sal-gross">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">ОПВ (10%) — удержание</div>
                  <div class="calc-result-item-value" id="r-sal-opv">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">ВОСМС (2%) — удержание</div>
                  <div class="calc-result-item-value" id="r-sal-vosms">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">Налогооблагаемая база ИПН</div>
                  <div class="calc-result-item-value" id="r-sal-iit-base">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">ИПН (10% / 15%)</div>
                  <div class="calc-result-item-value" id="r-sal-iit">—</div>
                </div>
                <div class="calc-result-item accent full">
                  <div class="calc-result-item-label">К выдаче (нетто)</div>
                  <div class="calc-result-item-value" id="r-sal-net">—</div>
                </div>
              </div>

              <!-- Нагрузка работодателя -->
              <div class="employer-box">
                <div class="employer-box-title">Нагрузка на работодателя (сверх оклада)</div>
                <div class="employer-box-grid">
                  <div>
                    <div class="employer-box-item-label">СО (5%)</div>
                    <div class="employer-box-item-value" id="r-sal-so">—</div>
                  </div>
                  <div>
                    <div class="employer-box-item-label">ОСМС (3%)</div>
                    <div class="employer-box-item-value" id="r-sal-employer-osms">—</div>
                  </div>
                  <div>
                    <div class="employer-box-item-label">ОПВР (3,5%)</div>
                    <div class="employer-box-item-value" id="r-sal-opvr">—</div>
                  </div>
                </div>
              </div>

              <div class="calc-result-item full accent" style="margin-top:10px;border-radius:8px;padding:14px">
                <div class="calc-result-item-label">Итого расходы работодателя (оклад + взносы)</div>
                <div class="calc-result-item-value" id="r-sal-employer-total">—</div>
              </div>

              <hr class="calc-result-divider">
              <div class="calc-result-note">* ИПН: 10% до 8 500 МРП/год (36 762 500 тг), 15% с суммы превышения. Вычет: 30 МРП/мес + ОПВ + ВОСМС + вычет на иждивенцев (882 МРП/год).</div>
            </div>
          </div>
        </div>

        <!-- ПЛАТЕЖИ ИП ЗА СЕБЯ -->
        <div class="calc-panel card" id="panel-ip">
          <div class="card-header">
            <div class="card-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1a3c6e" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
              <?= __t('calc_ip_title') ?> (ежемесячно) 2026
            </div>
          </div>
          <div style="padding:20px">
            <div class="mrp-hint">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div>Минимальные платежи при доходе 1 МЗП: ОПВ 10%, ОПВР 3,5%, СО 5%, ВОСМС 5% × 1,4 × МЗП. Укажите фактический доход для точного расчёта.</div>
            </div>

            <div class="form-group">
              <label class="form-label">Доход ИП за месяц <span>(тенге)</span></label>
              <div class="form-input-prefix">
                <span class="form-prefix-label">₸</span>
                <input type="number" class="form-prefix-input" id="ip_income" placeholder="85000" min="0" oninput="calcIP()">
              </div>
            </div>

            <div style="background:#f8fafc;border:1px solid #e2e6ea;border-radius:8px;padding:12px 16px;font-size:12px;color:#6b7280;margin-bottom:14px">
              Базы для расчёта: ОПВ — от фактического дохода (не менее 1 МЗП). ОПВР, СО, ВОСМС — от фактического дохода, но не менее 1 МЗП.
            </div>

            <button class="btn-calc" onclick="calcIP()"><?= __t('calc_btn_calculate') ?></button>

            <div class="calc-result" id="result-ip" style="display:none">
              <div class="calc-result-title"><?= __t('calc_result_title') ?> — 2026</div>
              <div class="calc-result-grid">
                <div class="calc-result-item full">
                  <div class="calc-result-item-label">Доход за месяц</div>
                  <div class="calc-result-item-value" id="r-ip-income">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">ОПВ (10%)</div>
                  <div class="calc-result-item-value" id="r-ip-opv">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">ОПВР (3,5%)</div>
                  <div class="calc-result-item-value" id="r-ip-opvr">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">СО (5%)</div>
                  <div class="calc-result-item-value" id="r-ip-so">—</div>
                </div>
                <div class="calc-result-item">
                  <div class="calc-result-item-label">ВОСМС (5% × 1,4 × МЗП)</div>
                  <div class="calc-result-item-value" id="r-ip-vosms">—</div>
                </div>
                <div class="calc-result-item accent full">
                  <div class="calc-result-item-label">Итого в месяц</div>
                  <div class="calc-result-item-value" id="r-ip-total">—</div>
                </div>
                <div class="calc-result-item full" style="background:rgba(255,255,255,.06)">
                  <div class="calc-result-item-label">Итого в год</div>
                  <div class="calc-result-item-value" id="r-ip-year">—</div>
                </div>
              </div>
              <hr class="calc-result-divider">
              <div class="calc-result-note">* ВОСМС фиксируется от базы 1,4 × МЗП = 119 000 тг. При минимальном доходе (1 МЗП) итого: 21 675 тг/мес.</div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
const MRP  = 4325;
const MZP  = 85000;
const IPN_THRESHOLD_YEAR  = 8500 * MRP;     // 36 762 500 тг/год
const IPN_THRESHOLD_MONTH = IPN_THRESHOLD_YEAR / 12; // ~3 063 542 тг/мес

function fmt(n) {
    return Math.round(n).toLocaleString('ru-RU') + ' ₸';
}

function switchMode(mode) {
    document.querySelectorAll('.calc-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.calc-mode-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('panel-' + mode).classList.add('active');
    document.getElementById('btn-'   + mode).classList.add('active');
}

/* ===== ИПН (годовой) ===== */
function calcIIT() {
    const income   = parseFloat(document.getElementById('iit_income').value)   || 0;
    const expenses = parseFloat(document.getElementById('iit_expenses').value) || 0;
    const type     = document.getElementById('iit_type').value;

    let deduct, taxable, tax;

    if (type === 'nonresident') {
        deduct  = expenses;
        taxable = Math.max(0, income - deduct);
        tax     = taxable * 0.20;
        document.getElementById('r-iit-tax').textContent = fmt(tax) + ' (20%)';
    } else {
        const stdDeduct = 30 * MRP * 12; // 1 557 000 тг/год
        deduct  = Math.min(stdDeduct + expenses, income);
        taxable = Math.max(0, income - deduct);
        if (taxable <= IPN_THRESHOLD_YEAR) {
            tax = taxable * 0.10;
        } else {
            tax = IPN_THRESHOLD_YEAR * 0.10 + (taxable - IPN_THRESHOLD_YEAR) * 0.15;
        }
        document.getElementById('r-iit-tax').textContent = fmt(tax);
    }

    document.getElementById('r-iit-income').textContent  = fmt(income);
    document.getElementById('r-iit-deduct').textContent  = fmt(deduct);
    document.getElementById('r-iit-taxable').textContent = fmt(taxable);
    document.getElementById('result-iit').style.display  = 'block';
}

/* ===== СНР 910 (единый %) ===== */
function calcSN() {
    const income = parseFloat(document.getElementById('sn_income').value) || 0;
    const rate   = parseFloat(document.getElementById('sn_rate').value)   || 4;
    const total  = income * (rate / 100);

    document.getElementById('r-sn-income').textContent = fmt(income);
    document.getElementById('r-sn-rate').textContent   = rate + '%';
    document.getElementById('r-sn-total').textContent  = fmt(total);
    document.getElementById('result-sn').style.display = 'block';
}

/* ===== ПАТЕНТ ===== */
function calcPAT() {
    const income = parseFloat(document.getElementById('pat_income').value) || 0;
    const months = parseInt(document.getElementById('pat_months').value)   || 6;

    const ipn   = income * 0.01;              // 1% ИПН
    const opv   = Math.max(MZP, income) * 0.10 * months / 12;   // 10% от базы за период
    const so    = Math.max(MZP, income) * 0.05 * months / 12;   // 5%
    const vosms = (1.4 * MZP) * 0.05 * months / 12;             // 5% × 1.4 × МЗП

    const total = ipn + opv + so + vosms;

    document.getElementById('r-pat-income').textContent = fmt(income);
    document.getElementById('r-pat-ipn').textContent    = fmt(ipn);
    document.getElementById('r-pat-opv').textContent    = fmt(opv);
    document.getElementById('r-pat-so').textContent     = fmt(so);
    document.getElementById('r-pat-vosms').textContent  = fmt(vosms);
    document.getElementById('r-pat-total').textContent  = fmt(total);
    document.getElementById('r-pat-note').textContent   =
        '* Расчёт за ' + months + ' мес. Социальные платежи от базы max(доход, МЗП). Лимит дохода по патенту: 15 258 600 ₸/год.';
    document.getElementById('result-pat').style.display = 'block';
}

/* ===== НДС (16%) ===== */
function calcVAT() {
    const amount = parseFloat(document.getElementById('vat_amount').value) || 0;
    const mode   = document.getElementById('vat_mode').value;
    let base, vatSum, total;

    if (mode === 'add') {
        base   = amount;
        vatSum = amount * 0.16;
        total  = amount + vatSum;
    } else {
        total  = amount;
        base   = amount / 1.16;
        vatSum = total - base;
    }

    document.getElementById('r-vat-base').textContent   = fmt(base);
    document.getElementById('r-vat-tax').textContent    = fmt(vatSum);
    document.getElementById('r-vat-total').textContent  = fmt(total);
    document.getElementById('result-vat').style.display = 'block';
}

/* ===== ЗАРПЛАТА ===== */
function calcSalary() {
    const gross  = parseFloat(document.getElementById('sal_gross').value)  || 0;
    const depend = parseInt(document.getElementById('sal_depend').value)   || 0;

    const opv   = gross * 0.10;
    const vosms = gross * 0.02;

    // Стандартный вычет 30 МРП + ОПВ + ВОСМС + иждивенцы
    const stdDeduct    = 30 * MRP;
    const dependDeduct = depend * (882 * MRP / 12);
    const iitBase      = Math.max(0, gross - opv - vosms - stdDeduct - dependDeduct);

    let iit;
    if (iitBase <= IPN_THRESHOLD_MONTH) {
        iit = iitBase * 0.10;
    } else {
        iit = IPN_THRESHOLD_MONTH * 0.10 + (iitBase - IPN_THRESHOLD_MONTH) * 0.15;
    }

    const net = gross - opv - vosms - iit;

    // Нагрузка работодателя
    const so           = gross * 0.05;
    const employerOsms = gross * 0.03;
    const opvr         = gross * 0.035;
    const employerTotal = gross + so + employerOsms + opvr;

    document.getElementById('r-sal-gross').textContent          = fmt(gross);
    document.getElementById('r-sal-opv').textContent            = fmt(opv);
    document.getElementById('r-sal-vosms').textContent          = fmt(vosms);
    document.getElementById('r-sal-iit-base').textContent       = fmt(iitBase);
    document.getElementById('r-sal-iit').textContent            = fmt(iit);
    document.getElementById('r-sal-net').textContent            = fmt(net);
    document.getElementById('r-sal-so').textContent             = fmt(so);
    document.getElementById('r-sal-employer-osms').textContent  = fmt(employerOsms);
    document.getElementById('r-sal-opvr').textContent           = fmt(opvr);
    document.getElementById('r-sal-employer-total').textContent = fmt(employerTotal);
    document.getElementById('result-salary').style.display      = 'block';
}

/* ===== ПЛАТЕЖИ ИП ЗА СЕБЯ ===== */
function calcIP() {
    const income = parseFloat(document.getElementById('ip_income').value) || MZP;
    const base   = Math.max(MZP, income); // база не менее МЗП

    const opv   = base * 0.10;
    const opvr  = base * 0.035;
    const so    = base * 0.05;
    const vosms = (1.4 * MZP) * 0.05;   // фиксированная база 1.4 × МЗП

    const total = opv + opvr + so + vosms;

    document.getElementById('r-ip-income').textContent = fmt(income);
    document.getElementById('r-ip-opv').textContent    = fmt(opv);
    document.getElementById('r-ip-opvr').textContent   = fmt(opvr);
    document.getElementById('r-ip-so').textContent     = fmt(so);
    document.getElementById('r-ip-vosms').textContent  = fmt(vosms);
    document.getElementById('r-ip-total').textContent  = fmt(total);
    document.getElementById('r-ip-year').textContent   = fmt(total * 12);
    document.getElementById('result-ip').style.display = 'block';
}
</script>