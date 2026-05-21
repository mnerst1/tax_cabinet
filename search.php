<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/search.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = Database::get();

$query    = trim($_GET['q'] ?? '');
$category = $_GET['cat'] ?? 'all';
$categories = searchCategories();
if (!isset($categories[$category])) {
    $category = 'all';
}

$unreadCount = 0;
try {
    $s = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $s->execute([$userId]);
    $unreadCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

$searchData = ['results' => [], 'total' => 0, 'by_type' => [], 'query' => $query];
$fullSearch = ['results' => [], 'total' => 0, 'by_type' => [], 'query' => $query];
if (mb_strlen($query) >= searchMinQueryLength()) {
    $fullSearch = performGlobalSearch($db, $userId, $query, 'all', 20);
    $searchData = $category === 'all'
        ? $fullSearch
        : performGlobalSearch($db, $userId, $query, $category, 20);
}

$pageTitle = 'Поиск — ' . SITE_NAME;
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
.global-search-page { max-width: 920px; }
.search-hero {
    background: var(--bg-card, #fff);
    border: 1px solid var(--border, #e2e6ea);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.search-hero h1 { font-size: 22px; font-weight: 700; margin-bottom: 6px; color: var(--text, #1a1a2e); }
.search-hero p { font-size: 13px; color: #6b7280; margin-bottom: 16px; }
.search-form-large { display: flex; gap: 10px; flex-wrap: wrap; }
.search-form-large .search-input-wrap { flex: 1; min-width: 260px; }
.search-cat-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
}
.search-cat-tab {
    padding: 8px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid #e2e6ea;
    background: #fff;
    color: #6b7280;
    text-decoration: none;
    transition: all .15s;
}
.search-cat-tab:hover { border-color: #1a3c6e; color: #1a3c6e; }
.search-cat-tab.active { background: #1a3c6e; border-color: #1a3c6e; color: #fff; }
.search-cat-tab .cnt {
    display: inline-block;
    margin-left: 4px;
    padding: 1px 6px;
    border-radius: 10px;
    font-size: 10px;
    background: rgba(0,0,0,.08);
}
.search-cat-tab.active .cnt { background: rgba(255,255,255,.25); }
.search-summary {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 16px;
}
.search-results-list { display: flex; flex-direction: column; gap: 10px; }
.search-result-item {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 16px 18px;
    background: var(--bg-card, #fff);
    border: 1px solid var(--border, #e2e6ea);
    border-radius: 10px;
    text-decoration: none;
    color: inherit;
    transition: border-color .15s, box-shadow .15s, background .15s;
}
.search-result-item:hover {
    border-color: #c7d7f5;
    box-shadow: 0 4px 14px rgba(26,60,110,.1);
    background: #f8faff;
}
.search-result-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #eef3fb;
    color: #1a3c6e;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.search-result-icon svg { width: 20px; height: 20px; }
.search-result-body { flex: 1; min-width: 0; }
.search-result-type {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: #9ca3af;
    margin-bottom: 4px;
}
.search-result-title {
    font-size: 15px;
    font-weight: 600;
    color: #1a1a2e;
    margin-bottom: 4px;
    line-height: 1.35;
}
.search-result-desc { font-size: 13px; color: #6b7280; line-height: 1.45; }
.search-result-meta {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 6px;
}
.search-mark {
    background: #fef08a;
    color: inherit;
    padding: 0 2px;
    border-radius: 2px;
}
.search-hint {
    padding: 40px 24px;
    text-align: center;
    color: #6b7280;
    font-size: 14px;
    background: var(--bg-card, #fff);
    border: 1px dashed #e2e6ea;
    border-radius: 10px;
}
.search-hint strong { color: #1a3c6e; }
.search-tips {
    margin-top: 24px;
    padding: 16px 20px;
    background: #f8fafc;
    border-radius: 10px;
    font-size: 12px;
    color: #6b7280;
    line-height: 1.6;
}
.search-tips ul { margin: 8px 0 0 18px; }
</style>

<div class="page-wrapper">
  <div class="container global-search-page">

    <div class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard.php">Главная</a>
      <span>—</span>
      <span>Поиск</span>
    </div>

    <div class="search-hero">
      <h1>Поиск по кабинету</h1>
      <p>Ищите по разделам, документам, формам, уведомлениям, лицевому счёту и справочным материалам</p>
      <form method="GET" action="<?= SITE_URL ?>/search.php" class="search-form-large" role="search">
        <?php if ($category !== 'all'): ?>
          <input type="hidden" name="cat" value="<?= htmlspecialchars($category) ?>">
        <?php endif; ?>
        <div class="search-input-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" name="q" class="search-input" id="globalSearchInput"
                 placeholder="Введите запрос: форма 240, задолженность, уведомление…"
                 value="<?= htmlspecialchars($query) ?>"
                 autocomplete="off" autofocus>
        </div>
        <button type="submit" class="btn-find">Найти</button>
      </form>
    </div>

    <?php if ($query !== '' && mb_strlen($query) < searchMinQueryLength()): ?>
      <div class="search-hint">
        Введите минимум <strong><?= searchMinQueryLength() ?></strong> символа для поиска.
      </div>
    <?php elseif ($query !== ''): ?>

      <div class="search-cat-tabs">
        <?php
        $allByType = $fullSearch['by_type'] ?? [];
        ?>
        <a href="?q=<?= urlencode($query) ?>&cat=all"
           class="search-cat-tab <?= $category === 'all' ? 'active' : '' ?>">
          Все<?php if ($fullSearch['total'] > 0): ?><span class="cnt"><?= $fullSearch['total'] ?></span><?php endif; ?>
        </a>
        <?php foreach ($categories as $key => $label):
            if ($key === 'all') continue;
            $cnt = $allByType[$key] ?? 0;
            if ($cnt === 0 && $category !== $key) continue;
        ?>
        <a href="?q=<?= urlencode($query) ?>&cat=<?= urlencode($key) ?>"
           class="search-cat-tab <?= $category === $key ? 'active' : '' ?>">
          <?= htmlspecialchars($label) ?><?php if ($cnt > 0): ?><span class="cnt"><?= $cnt ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>

      <p class="search-summary">
        <?php if ($searchData['total'] > 0): ?>
          Найдено: <strong><?= $searchData['total'] ?></strong>
          <?= $searchData['total'] === 1 ? 'результат' : ($searchData['total'] < 5 ? 'результата' : 'результатов') ?>
          по запросу «<strong><?= htmlspecialchars($query) ?></strong>»
        <?php else: ?>
          По запросу «<strong><?= htmlspecialchars($query) ?></strong>» ничего не найдено.
          Попробуйте другие слова или выберите категорию «Все».
        <?php endif; ?>
      </p>

      <?php if (!empty($searchData['results'])): ?>
      <div class="search-results-list">
        <?php foreach ($searchData['results'] as $item): ?>
        <a href="<?= htmlspecialchars($item['url']) ?>" class="search-result-item">
          <div class="search-result-icon"><?= searchTypeIcon($item['type']) ?></div>
          <div class="search-result-body">
            <div class="search-result-type"><?= htmlspecialchars(searchTypeLabel($item['type'])) ?></div>
            <div class="search-result-title"><?= highlightSearchTerm($item['title'], $query) ?></div>
            <?php if (!empty($item['description'])): ?>
              <div class="search-result-desc"><?= highlightSearchTerm($item['description'], $query) ?></div>
            <?php endif; ?>
            <?php if (!empty($item['meta'])): ?>
              <div class="search-result-meta"><?= highlightSearchTerm($item['meta'], $query) ?></div>
            <?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    <?php else: ?>

      <div class="search-hint">
        <p style="margin-bottom:8px">Начните вводить запрос в поле выше</p>
        <p style="font-size:12px">Например: <strong>240</strong>, <strong>задолженность</strong>, <strong>ЭЦП</strong>, <strong>представитель</strong></p>
      </div>

      <div class="search-tips">
        <strong>Что можно найти:</strong>
        <ul>
          <li>Разделы меню — лицевой счёт, подача отчётности, профиль</li>
          <li>Ваши документы и входящие письма от ОГД</li>
          <li>Типы налоговых форм (200, 240, 910 и др.)</li>
          <li>Уведомления, начисления и обращения в поддержку</li>
          <li>Статьи справки и ответы на частые вопросы</li>
        </ul>
      </div>

    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
