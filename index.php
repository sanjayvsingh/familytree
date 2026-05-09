<?php
require_once 'gedcom_parser.php';

// Allowed GED files must live in the gedcom/ directory
$ged_dir  = __DIR__ . '/gedcom/';
$ged_file = '';
$error    = '';

if (isset($_GET['file'])) {
    $requested = basename($_GET['file']); // strip any path traversal
    $candidate = $ged_dir . $requested;
    if (pathinfo($candidate, PATHINFO_EXTENSION) === 'ged' && is_file($candidate)) {
        $ged_file = $candidate;
    } else {
        $error = 'File not found.';
    }
}

// List available GED files for the picker
$available = glob($ged_dir . '*.ged') ?: [];
$available = array_map('basename', $available);

$data = ['individuals' => [], 'families' => []];
if ($ged_file) {
    $data = parse_gedcom($ged_file);
}
$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
if ($json === false) {
    $error    = 'Failed to encode GEDCOM data: ' . json_last_error_msg();
    $ged_file = '';
    $json     = 'null';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Family Tree</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<header>
  <div class="header-inner">
    <h1>Family Tree</h1>
    <div class="file-picker">
      <form method="get" action="">
        <label for="file-select">GEDCOM file:</label>
        <select id="file-select" name="file" onchange="this.form.submit()">
          <option value="">— choose —</option>
          <?php foreach ($available as $f): ?>
            <option value="<?= htmlspecialchars($f) ?>"
              <?= ($ged_file && basename($ged_file) === $f) ? 'selected' : '' ?>>
              <?= htmlspecialchars($f) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="search-wrap" id="search-wrap" <?= $ged_file ? '' : 'style="display:none"' ?>>
      <input type="text" id="search-input" placeholder="Search people… (3+ chars)" autocomplete="off">
      <ul id="search-results" class="search-dropdown" hidden></ul>
    </div>
  </div>
</header>

<main>
<?php if ($error): ?>
  <div class="notice error"><?= htmlspecialchars($error) ?></div>
<?php elseif (!$ged_file): ?>
  <div class="splash">
    <p>Select a GEDCOM file above to get started.</p>
  </div>
<?php else: ?>
  <div id="app">
    <aside id="people-panel">
      <div id="people-header">People</div>
      <div id="people-search-wrap">
        <input type="text" id="people-search" placeholder="Filter names…" autocomplete="off">
      </div>
      <ul id="people-list"></ul>
    </aside>
    <div id="tree-container">
      <div id="tree-viewport">
        <div id="tree-canvas"></div>
      </div>
    </div>
    <aside id="detail-panel" hidden>
      <button id="panel-close" aria-label="Close">&times;</button>
      <div id="detail-content"></div>
    </aside>
  </div>
  <div id="upcoming-panel">
    <h2>Upcoming dates <span id="upcoming-year"></span></h2>
    <div id="upcoming-list"></div>
  </div>
<?php endif; ?>
</main>

<?php if ($ged_file): ?>
<script>
window.GEDCOM = <?= $json ?>;
</script>
<script src="js/app.js"></script>
<?php endif; ?>
</body>
</html>
