<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();
$u = require_login();

$devices = user_devices((int)$u['id']);
$sum = devices_summary($devices);

$online = count(array_filter($sum, fn($s) => $s['online']));
$temps = array_values(array_filter(array_column($sum, 'temperature'), fn($v) => $v !== null));
$hums  = array_values(array_filter(array_column($sum, 'humidity'), fn($v) => $v !== null));
$avgT = $temps ? array_sum($temps) / count($temps) : null;
$avgH = $hums ? array_sum($hums) / count($hums) : null;

$pk = db()->prepare('SELECT COUNT(*) FROM ' . t('webauthn') . ' WHERE user_id = ?');
$pk->execute([$u['id']]);
$passkeys = (int)$pk->fetchColumn();

$colors = [];
foreach ($devices as $d) {
    $colors[(int)$d['id']] = $d['color'];
}

$hour = (int)date('G');
$greet = $hour < 10 ? 'Dobré ráno' : ($hour < 18 ? 'Dobrý deň' : 'Dobrý večer');

$pageTitle = 'Prehľad';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="text-secondary mb-1"><?= e($greet) ?>, <?= e($u['username']) ?> 👋</p>
    <h1 class="page-title">Prehľad</h1>
  </div>
  <div class="d-flex align-items-center gap-2">
    <span class="live-dot" id="liveDot" title="Automatická obnova"></span>
    <small class="text-secondary" id="lastRefresh">aktualizované teraz</small>
  </div>
</div>

<div class="passkey-banner glass d-none" id="passkeyBanner" data-count="<?= $passkeys ?>">
  <div class="d-flex align-items-center gap-3">
    <div class="icon-bubble"><i class="bi bi-fingerprint"></i></div>
    <div class="flex-grow-1">
      <div class="fw-semibold">Zapni si prihlásenie odtlačkom prsta</div>
      <div class="small text-secondary">Tvoje zariadenie ho podporuje – nabudúce sa prihlásiš jedným dotykom.</div>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('settings.php#passkeys')) ?>">Zapnúť</a>
    <button class="btn-close" type="button" id="passkeyBannerClose" aria-label="Zavrieť"></button>
  </div>
</div>

<?php if (!$devices): ?>
  <div class="empty-state glass">
    <div class="empty-icon"><i class="bi bi-cpu"></i></div>
    <h2 class="h4 fw-bold">Zatiaľ tu nie sú žiadne zariadenia</h2>
    <p class="text-secondary">Pridaj svoje prvé ESP32, dostaneš API kľúč a hotový kód na nahratie.</p>
    <a class="btn btn-primary btn-lg" href="<?= e(url('devices.php?new=1')) ?>"><i class="bi bi-plus-lg me-1"></i> Pridať zariadenie</a>
  </div>
<?php else: ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-tile glass">
      <div class="stat-icon bg-temp"><i class="bi bi-thermometer-half"></i></div>
      <div class="stat-label">Priemerná teplota</div>
      <div class="stat-value"><span id="avgT"><?= fmt_num($avgT) ?></span><small>°C</small></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-tile glass">
      <div class="stat-icon bg-hum"><i class="bi bi-droplet-half"></i></div>
      <div class="stat-label">Priemerná vlhkosť</div>
      <div class="stat-value"><span id="avgH"><?= fmt_num($avgH, 0) ?></span><small>%</small></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-tile glass">
      <div class="stat-icon bg-ok"><i class="bi bi-wifi"></i></div>
      <div class="stat-label">Online</div>
      <div class="stat-value"><span id="onlineCount"><?= $online ?></span><small>/ <?= count($sum) ?></small></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-tile glass">
      <div class="stat-icon bg-accent"><i class="bi bi-activity"></i></div>
      <div class="stat-label">Meraní dnes</div>
      <div class="stat-value"><span id="todayCount"><?= array_sum(array_map(fn($s) => $s['today']['count'] ?? 0, $sum)) ?></span></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4" id="deviceGrid">
  <?php foreach ($sum as $s): ?>
  <div class="col-12 col-md-6 col-xl-4">
    <article class="device-card glass" data-device="<?= $s['id'] ?>" style="--dev-color: <?= e($colors[$s['id']] ?? '#6366f1') ?>">
      <header class="d-flex justify-content-between align-items-start mb-3">
        <div class="min-w-0">
          <h2 class="device-name text-truncate"><?= e($s['name']) ?></h2>
          <div class="small text-secondary text-truncate"><i class="bi bi-geo-alt"></i> <?= e($s['location'] ?: $s['slug']) ?></div>
        </div>
        <span class="status-badge <?= $s['online'] ? 'online' : 'offline' ?>" data-f="status"><?= $s['online'] ? 'Online' : 'Offline' ?></span>
      </header>
      <div class="readings">
        <div class="reading temp">
          <div class="reading-label"><i class="bi bi-thermometer-half"></i> Teplota</div>
          <div class="reading-value"><span data-f="t"><?= fmt_num($s['temperature']) ?></span><small>°C</small></div>
          <div class="reading-trend" data-f="tt"></div>
        </div>
        <div class="reading hum">
          <div class="reading-label"><i class="bi bi-droplet-half"></i> Vlhkosť</div>
          <div class="reading-value"><span data-f="h"><?= fmt_num($s['humidity'], 0) ?></span><small>%</small></div>
          <div class="reading-trend" data-f="ht"></div>
        </div>
      </div>
      <canvas class="spark" height="54" data-f="spark"></canvas>
      <div class="device-meta">
        <div><span class="text-secondary">Dnes</span> <span data-f="range"><?= $s['today'] ? fmt_num($s['today']['tmin']) . ' – ' . fmt_num($s['today']['tmax']) . ' °C' : '–' ?></span></div>
        <div><span class="chip" data-f="comfort"><?= e($s['comfort'] ?? '–') ?></span></div>
      </div>
      <footer class="device-foot">
        <span><i class="bi bi-clock"></i> <span data-f="seen"><?= e($s['last_seen_h']) ?></span></span>
        <span><i class="bi bi-moisture"></i> rosný bod <span data-f="dew"><?= fmt_num($s['dew_point']) ?></span> °C</span>
        <a href="<?= e(url('history.php?d[]=' . $s['id'])) ?>" class="ms-auto link-arrow">História <i class="bi bi-arrow-right"></i></a>
      </footer>
    </article>
  </div>
  <?php endforeach; ?>
</div>

<section class="glass panel mb-4">
  <div class="panel-head">
    <h2 class="panel-title"><i class="bi bi-graph-up-arrow"></i> Posledných 24 hodín</h2>
    <div class="seg" id="metricSeg">
      <button class="active" data-metric="t" type="button">Teplota</button>
      <button data-metric="h" type="button">Vlhkosť</button>
    </div>
  </div>
  <div class="chart-box"><canvas id="chart24"></canvas></div>
</section>
<?php endif; ?>

<?php
$useCharts = true;
$pageScripts = ['charts.js', 'dashboard.js'];
require __DIR__ . '/includes/footer.php';
