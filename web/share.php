<?php
declare(strict_types=1);

/** Verejná stránka zdieľaného odkazu (len na čítanie, bez prihlásenia a bez cookies). */

require __DIR__ . '/includes/bootstrap.php';
send_security_headers();
header('X-Robots-Tag: noindex, nofollow');

$token = (string)($_GET['t'] ?? '');
$sh = null;
try {
    $sh = load_share($token);
    if ($sh) {
        db()->prepare('UPDATE ' . t('shares') . ' SET views = views + 1, last_view_at = ? WHERE id = ?')->execute([now(), $sh['id']]);
    }
} catch (PDOException $e) {
    $sh = null;
}
if (!$sh) {
    http_response_code(404);
}
$ranges = array_filter([1 => '24 h', 7 => '7 dní', 30 => '30 dní', 90 => '90 dní'], fn($d) => $sh && $d <= (int)$sh['max_days'], ARRAY_FILTER_USE_KEY);
?>
<!doctype html>
<html lang="sk" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="app-base" content="<?= e(base_path()) ?>">
<title><?= e($sh ? $sh['name'] . ' · ' : '') ?>Teplota a vlhkosť</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(url('assets/icons/favicon-32.png')) ?>">
<script src="<?= e(url('assets/js/theme.js')) ?>"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/app.css')) ?>?v=<?= APP_VERSION ?>" rel="stylesheet">
</head>
<body class="share-body">
<main class="share-main">
  <header class="share-head">
    <div class="d-flex align-items-center gap-2">
      <span class="brand-logo"><i class="bi bi-thermometer-half"></i></span>
      <div>
        <div class="fw-bold"><?= e($sh['name'] ?? 'Zdieľané merania') ?></div>
        <div class="small text-secondary"><span class="live-dot d-inline-block me-1" id="liveDot"></span> <span id="lastRefresh">načítavam…</span></div>
      </div>
    </div>
    <div class="share-clock text-end">
      <div class="t" id="clockT">--:--</div>
      <div class="small text-secondary" id="clockD"></div>
    </div>
  </header>

  <?php if (!$sh): ?>
    <div class="empty-state glass mt-4">
      <div class="empty-icon"><i class="bi bi-link-45deg"></i></div>
      <h1 class="h5 fw-bold">Odkaz neexistuje alebo už neplatí</h1>
      <p class="text-secondary mb-0">Požiadaj toho, kto ti ho poslal, o nový odkaz.</p>
    </div>
  <?php else: ?>
    <div class="row g-3" id="shareGrid" data-token="<?= e($token) ?>"></div>

    <section class="glass panel mt-3">
      <div class="panel-head">
        <h2 class="panel-title"><i class="bi bi-graph-up"></i> Priebeh</h2>
        <div class="d-flex gap-2 flex-wrap">
          <div class="seg" id="shMetric">
            <button class="active" type="button" data-metric="t"><i class="bi bi-thermometer-half"></i> Teplota</button>
            <button type="button" data-metric="h"><i class="bi bi-droplet-half"></i> Vlhkosť</button>
          </div>
          <div class="seg" id="shRange">
            <?php $first = true; foreach ($ranges as $d => $l): ?>
              <button class="<?= $first ? 'active' : '' ?>" type="button" data-days="<?= $d ?>"><?= $l ?></button>
            <?php $first = false; endforeach; ?>
          </div>
        </div>
      </div>
      <div class="chart-box chart-lg"><canvas id="shChart"></canvas></div>
    </section>
  <?php endif; ?>

  <footer class="text-center small text-secondary mt-4 mb-2">
    <i class="bi bi-eye"></i> Len na čítanie · <?= e(cfg('name')) ?>
  </footer>
</main>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="<?= e(url('assets/js/charts.js')) ?>?v=<?= APP_VERSION ?>"></script>
<script src="<?= e(url('assets/js/share.js')) ?>?v=<?= APP_VERSION ?>"></script>
</body>
</html>
