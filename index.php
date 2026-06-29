<?php
require_once __DIR__ . '/auth_lib.php';
if (!auth_validate_session($_COOKIE['ft_session'] ?? '')) {
    include __DIR__ . '/login.php';
    exit;
}

$ged_dir   = __DIR__ . '/gedcom/';
$available = glob($ged_dir . '*.ged') ?: [];
$available = array_map('basename', $available);

$selected = '';
$error    = '';

if (isset($_GET['file'])) {
    $f = basename($_GET['file']);
    if (pathinfo($f, PATHINFO_EXTENSION) === 'ged' && is_file($ged_dir . $f)) {
        $selected = $f;
    } else {
        $error = 'File not found.';
    }
} elseif (count($available) === 1) {
    $selected = $available[0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Family Tree</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__.'/style.css') ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20,400,0,0&icon_names=calendar_add_on">
</head>
<body>

<header>
  <div class="header-inner">
    <h1>Family Tree</h1>
    <?php if (count($available) > 1): ?>
    <div class="file-picker">
      <form method="get" action="">
        <label for="file-select">GEDCOM file:</label>
        <select id="file-select" name="file" onchange="this.form.submit()">
          <option value="">— choose —</option>
          <?php foreach ($available as $f): ?>
            <option value="<?= htmlspecialchars($f) ?>"
              <?= ($selected === $f) ? 'selected' : '' ?>>
              <?= htmlspecialchars($f) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php endif; ?>
    <?php if ($selected): ?>
    <button id="mobile-search-btn" aria-label="Search people">&#x2315;</button>
    <?php endif; ?>
  </div>
</header>

<main>
<?php if ($error): ?>
  <div class="notice error"><?= htmlspecialchars($error) ?></div>
<?php elseif (!$selected): ?>
  <div class="splash">
    <p>Select a GEDCOM file above to get started.</p>
  </div>
<?php else: ?>
  <div id="app">
    <aside id="people-panel">
      <div id="people-header">People <button id="people-close" aria-label="Close">&times;</button></div>
      <div id="people-search-wrap">
        <input type="text" id="people-search" placeholder="Filter names…" autocomplete="off">
      </div>
      <ul id="people-list"></ul>
    </aside>
    <div id="tree-container">
      <div id="tree-viewport">
        <div id="tree-canvas"></div>
      </div>
      <button id="full-tree-close" aria-label="Exit full tree">&#x2190; Back to tree</button>
    </div>
    <aside id="detail-panel" hidden>
      <button id="panel-close" aria-label="Close">&times;</button>
      <div id="detail-content"></div>
    </aside>
  </div>
  <div id="upcoming-panel">
    <h2>Upcoming Events <button id="cal-download" aria-label="Download calendar" title="Download .ics file"><span class="material-symbols-outlined">calendar_add_on</span></button><button id="upcoming-toggle" aria-label="Collapse upcoming">&#x25BE;</button></h2>
    <div id="upcoming-list"></div>
  </div>
<?php endif; ?>
</main>

<?php if ($selected): ?>
<script>window.GEDCOM_FILE = <?= json_encode($selected) ?>; window.GEDCOM_COUNT = <?= count($available) ?>;</script>
<script src="app.js?v=<?= filemtime(__DIR__.'/app.js') ?>"></script>
<?php endif; ?>
</body>
</html>
