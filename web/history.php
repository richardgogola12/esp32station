<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();
$u = require_login();

$devices = user_devices((int)$u['id']);
$f = parse_filter($devices, $_GET);
$stats = $devices ? stats($f) : [];
$byId = [];
foreach ($devices as $d) {
    $byId[(int)$d['id']] = $d;
}

// Tabuľka meraní so stránkovaním
$perPage = 50;
$page = max(1, (int)($_GET['p'] ?? 1));
$total = 0;
$rows = [];
if ($f['devices']) {
    $in = implode(',', array_fill(0, count($f['devices']), '?'));
    $params = [...$f['devices'], $f['from_s'], $f['to_s']];
    $st = db()->prepare('SELECT COUNT(*) FROM ' . t('readings') . " WHERE device_id IN ($in) AND created_at BETWEEN ? AND ?");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $st = db()->prepare('SELECT device_id, temperature, humidity, rssi, created_at FROM ' . t('readings')
        . " WHERE device_id IN ($in) AND created_at BETWEEN ? AND ? ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $st->execute($params);
    $rows = $st->fetchAll();
}
$pages = max(1, (int)ceil($total / $perPage));

function qs(array $over = []): string
{
    $q = array_merge($_GET, $over);
    return htmlspecialchars('?' . http_build_query($q), ENT_QUOTES);
}

$fromVal = date('Y-m-d\TH:i', $f['from']);
$toVal   = date('Y-m-d\TH:i', $f['to']);
$aggNames = ['auto' => 'Automaticky', 'raw' => 'Každé meranie', '15m' => '15 minút', 'hour' => 'Hodinové priemery', 'day' => 'Denné priemery'];

$pageTitle = 'História';
$activeNav = 'history';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="text-secondary mb-1">Grafy, štatistiky a všetky merania</p>
    <h1 class="page-title">História</h1>
  </div>
  <?php if ($devices): ?>
  <a class="btn btn-ghost" href="<?= e(url('export.php')) ?><?= qs() ?>"><i class="bi bi-filetype-csv me-1"></i> Export CSV</a>
  <?php endif; ?>
</div>

<?php if (!$devices): ?>
  <div class="empty-state glass">
    <div class="empty-icon"><i class="bi bi-graph-up"></i></div>
    <h2 class="h4 fw-bold">Zatiaľ nie je čo zobraziť</h2>
    <p class="text-secondary">Najprv pridaj zariadenie a nechaj ho chvíľu merať.</p>
    <a class="btn btn-primary" href="<?= e(url('devices.php')) ?>">Pridať zariadenie</a>
  </div>
<?php else: ?>

<form class="filter-bar glass mb-4" method="get" id="filterForm">
  <div class="quick-ranges mb-3" id="quickRanges">
    <button type="button" data-range="1h">1 h</button>
    <button type="button" data-range="24h">24 h</button>
    <button type="button" data-range="7d">7 dní</button>
    <button type="button" data-range="30d">30 dní</button>
    <button type="button" data-range="month">Tento mesiac</button>
    <button type="button" data-range="year">Tento rok</button>
  </div>
  <div class="row g-3 align-items-end">
    <div class="col-12 col-lg-5">
      <label class="form-label small text-secondary">Zariadenia</label>
      <div class="device-chips">
        <?php foreach ($devices as $d): $sel = in_array((int)$d['id'], $f['devices'], true); ?>
          <label class="dchip" style="--dev-color: <?= e($d['color']) ?>">
            <input type="checkbox" name="d[]" value="<?= (int)$d['id'] ?>" <?= $sel ? 'checked' : '' ?>>
            <span><i class="dot"></i><?= e($d['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-6 col-lg-2">
      <label class="form-label small text-secondary" for="from">Od</label>
      <input class="form-control" type="datetime-local" id="from" name="from" value="<?= e($fromVal) ?>">
    </div>
    <div class="col-6 col-lg-2">
      <label class="form-label small text-secondary" for="to">Do</label>
      <input class="form-control" type="datetime-local" id="to" name="to" value="<?= e($toVal) ?>">
    </div>
    <div class="col-8 col-lg-2">
      <label class="form-label small text-secondary" for="agg">Zoskupenie</label>
      <select class="form-select" name="agg" id="agg">
        <?php foreach ($aggNames as $k => $n): ?>
          <option value="<?= $k ?>" <?= $f['agg'] === $k ? 'selected' : '' ?>><?= e($n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-4 col-lg-1">
      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i></button>
    </div>
  </div>
</form>

<div class="row g-3 mb-4">
  <?php foreach ($f['devices'] as $id): $s = $stats[$id] ?? null; $d = $byId[$id]; ?>
  <div class="col-12 col-md-6 col-xl-4">
    <div class="stats-card glass" style="--dev-color: <?= e($d['color']) ?>">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="h6 fw-bold mb-0"><i class="dot"></i> <?= e($d['name']) ?></h3>
        <span class="small text-secondary"><?= $s ? number_format($s['count'], 0, ',', ' ') . ' meraní' : 'bez dát' ?></span>
      </div>
      <?php if ($s): ?>
      <div class="stats-grid">
        <div class="sg temp"><span>Min</span><b><?= fmt_num($s['tmin']) ?>°</b></div>
        <div class="sg temp"><span>Priemer</span><b><?= fmt_num($s['tavg']) ?>°</b></div>
        <div class="sg temp"><span>Max</span><b><?= fmt_num($s['tmax']) ?>°</b></div>
        <div class="sg hum"><span>Min</span><b><?= fmt_num($s['hmin'], 0) ?>%</b></div>
        <div class="sg hum"><span>Priemer</span><b><?= fmt_num($s['havg'], 0) ?>%</b></div>
        <div class="sg hum"><span>Max</span><b><?= fmt_num($s['hmax'], 0) ?>%</b></div>
      </div>
      <?php else: ?>
        <p class="small text-secondary mb-0">V zvolenom období nie sú žiadne merania.</p>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<section class="glass panel mb-4">
  <div class="panel-head">
    <h2 class="panel-title"><i class="bi bi-thermometer-half text-temp"></i> Teplota</h2>
    <div class="d-flex align-items-center gap-2">
      <span class="small text-secondary" id="aggLabel"></span>
    </div>
  </div>
  <div class="chart-box chart-lg"><canvas id="tChart"></canvas></div>
</section>

<section class="glass panel mb-4">
  <div class="panel-head">
    <h2 class="panel-title"><i class="bi bi-droplet-half text-hum"></i> Vlhkosť</h2>
  </div>
  <div class="chart-box chart-lg"><canvas id="hChart"></canvas></div>
</section>

<section class="glass panel mb-4" id="table">
  <div class="panel-head">
    <h2 class="panel-title"><i class="bi bi-table"></i> Merania</h2>
    <span class="small text-secondary"><?= number_format($total, 0, ',', ' ') ?> záznamov</span>
  </div>
  <?php if (!$rows): ?>
    <p class="text-secondary text-center py-4 mb-0">Žiadne merania v zvolenom období.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-modern align-middle mb-0">
      <thead><tr><th>Čas</th><th>Zariadenie</th><th class="text-end">Teplota</th><th class="text-end">Vlhkosť</th><th class="text-end d-none d-md-table-cell">Signál</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $d = $byId[(int)$r['device_id']]; ?>
        <tr>
          <td class="text-nowrap"><?= e(date('j. n. Y', strtotime($r['created_at']))) ?> <span class="text-secondary"><?= e(date('H:i:s', strtotime($r['created_at']))) ?></span></td>
          <td><span class="dchip-static" style="--dev-color: <?= e($d['color']) ?>"><i class="dot"></i><?= e($d['name']) ?></span></td>
          <td class="text-end fw-semibold text-temp"><?= fmt_num($r['temperature']) ?> °C</td>
          <td class="text-end fw-semibold text-hum"><?= fmt_num($r['humidity'], 0) ?> %</td>
          <td class="text-end text-secondary d-none d-md-table-cell"><?= $r['rssi'] !== null ? (int)$r['rssi'] . ' dBm' : '–' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="d-flex justify-content-center mt-3">
    <ul class="pagination pagination-modern mb-0">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= qs(['p' => $page - 1]) ?>#table"><i class="bi bi-chevron-left"></i></a></li>
      <?php
      $start = max(1, $page - 2); $end = min($pages, $page + 2);
      if ($start > 1): ?><li class="page-item"><a class="page-link" href="<?= qs(['p' => 1]) ?>#table">1</a></li><?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; endif;
      for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= qs(['p' => $i]) ?>#table"><?= $i ?></a></li>
      <?php endfor;
      if ($end < $pages): if ($end < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?= qs(['p' => $pages]) ?>#table"><?= $pages ?></a></li><?php endif; ?>
      <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= qs(['p' => $page + 1]) ?>#table"><i class="bi bi-chevron-right"></i></a></li>
    </ul>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>

<div id="historyConfig" data-query="<?= e(http_build_query(['d' => implode(',', $f['devices']), 'from' => $f['from_s'], 'to' => $f['to_s'], 'agg' => $f['agg']])) ?>"></div>
<?php endif; ?>

<?php
$useCharts = true;
$pageScripts = ['charts.js', 'history.js'];
require __DIR__ . '/includes/footer.php';
