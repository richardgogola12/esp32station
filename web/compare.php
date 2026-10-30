<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();
$u = require_login();

$devices = user_devices((int)$u['id']);
$sel = (int)($_GET['d'] ?? ($devices[0]['id'] ?? 0));
$mode = in_array($_GET['mode'] ?? '', ['day', 'week', 'month', 'custom'], true) ? $_GET['mode'] : 'day';
$date = (string)($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$cp = compare_periods($mode, $date, $_GET);
$v = fn($k, $def) => e($_GET[$k] ?? $def);

$pageTitle = 'Porovnanie';
$activeNav = 'compare';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="text-secondary">Ako sa mení teplota a vlhkosť v čase</p>
    <h1 class="page-title">Porovnanie</h1>
  </div>
</div>

<?php if (!$devices): ?>
  <div class="empty-state glass">
    <div class="empty-icon"><i class="bi bi-arrow-left-right"></i></div>
    <h2 class="h5 fw-bold">Najprv pridaj zariadenie</h2>
    <a class="btn btn-primary mt-2" href="<?= e(url('devices.php')) ?>">Pridať zariadenie</a>
  </div>
<?php else: ?>

<form class="filter-bar glass mb-3" method="get" id="cmpForm">
  <div class="row g-3 align-items-end">
    <div class="col-12 col-md-4">
      <label class="form-label small text-secondary" for="cd">Zariadenie</label>
      <select class="form-select" id="cd" name="d">
        <?php foreach ($devices as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $sel ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-8">
      <label class="form-label small text-secondary">Porovnať</label>
      <div class="seg w-100 d-flex" id="modeSeg">
        <?php foreach (['day' => 'Deň', 'week' => 'Týždeň', 'month' => 'Mesiac', 'custom' => 'Vlastné'] as $k => $l): ?>
          <button type="button" class="flex-fill <?= $mode === $k ? 'active' : '' ?>" data-mode="<?= $k ?>"><?= $l ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="mode" value="<?= e($mode) ?>">
    </div>
    <div class="col-12 col-md-4 <?= $mode === 'custom' ? 'd-none' : '' ?>" id="dateBox">
      <label class="form-label small text-secondary" for="cdate">Dátum v období</label>
      <div class="input-group">
        <button class="btn btn-ghost" type="button" data-shift="-1" title="Predchádzajúce"><i class="bi bi-chevron-left"></i></button>
        <input class="form-control text-center" type="date" id="cdate" name="date" value="<?= e($date) ?>">
        <button class="btn btn-ghost" type="button" data-shift="1" title="Nasledujúce"><i class="bi bi-chevron-right"></i></button>
      </div>
    </div>
    <div class="col-12 <?= $mode === 'custom' ? '' : 'd-none' ?>" id="customBox">
      <div class="row g-2">
        <div class="col-6 col-lg-3"><label class="form-label small text-secondary">Obdobie A od</label><input class="form-control" type="datetime-local" name="a_from" value="<?= $v('a_from', date('Y-m-d\T00:00')) ?>"></div>
        <div class="col-6 col-lg-3"><label class="form-label small text-secondary">A do</label><input class="form-control" type="datetime-local" name="a_to" value="<?= $v('a_to', date('Y-m-d\TH:i')) ?>"></div>
        <div class="col-6 col-lg-3"><label class="form-label small text-secondary">Obdobie B od</label><input class="form-control" type="datetime-local" name="b_from" value="<?= $v('b_from', date('Y-m-d\T00:00', strtotime('-1 day'))) ?>"></div>
        <div class="col-6 col-lg-3"><label class="form-label small text-secondary">B do</label><input class="form-control" type="datetime-local" name="b_to" value="<?= $v('b_to', date('Y-m-d\TH:i', strtotime('-1 day'))) ?>"></div>
      </div>
    </div>
    <div class="col-12 col-md-auto">
      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-arrow-left-right me-1"></i> Porovnať</button>
    </div>
  </div>
</form>

<div class="cmp-legend mb-3">
  <div class="cmp-period a"><span class="sw"></span><div><small>Obdobie A</small><b><?= e($cp['a']['label']) ?></b></div></div>
  <div class="cmp-vs">vs</div>
  <div class="cmp-period b"><span class="sw"></span><div><small>Obdobie B</small><b><?= e($cp['b']['label']) ?></b></div></div>
</div>

<div class="row g-3 mb-3" id="cmpStats">
  <div class="col-12 col-md-6"><div class="glass panel h-100" data-metric="t"><div class="cmp-title"><i class="bi bi-thermometer-half text-temp"></i> Teplota</div><div class="cmp-grid">…</div></div></div>
  <div class="col-12 col-md-6"><div class="glass panel h-100" data-metric="h"><div class="cmp-title"><i class="bi bi-droplet-half text-hum"></i> Vlhkosť</div><div class="cmp-grid">…</div></div></div>
</div>

<section class="glass panel mb-3">
  <div class="panel-head">
    <h2 class="panel-title"><i class="bi bi-graph-up"></i> Priebeh</h2>
    <div class="seg" id="cmpMetric">
      <button class="active" type="button" data-metric="t"><i class="bi bi-thermometer-half"></i> Teplota</button>
      <button type="button" data-metric="h"><i class="bi bi-droplet-half"></i> Vlhkosť</button>
    </div>
  </div>
  <div class="chart-box chart-lg"><canvas id="cmpChart"></canvas></div>
  <p class="small text-secondary mt-2 mb-0" id="cmpInsight"></p>
</section>

<div id="cmpConfig" data-query="<?= e(http_build_query(array_filter([
    'd' => $sel, 'mode' => $mode, 'date' => $date,
    'a_from' => $_GET['a_from'] ?? null, 'a_to' => $_GET['a_to'] ?? null, 'b_from' => $_GET['b_from'] ?? null, 'b_to' => $_GET['b_to'] ?? null,
]))) ?>"></div>
<?php endif; ?>

<?php
$useCharts = true;
$pageScripts = ['charts.js', 'compare.js'];
require __DIR__ . '/includes/footer.php';
