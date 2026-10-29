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
    <p class="text-secondary"><?= e($greet) ?>, <?= e($u['username']) ?></p>
    <h1 class="page-title">Prehľad</h1>
  </div>
  <div class="d-flex align-items-center gap-2 small text-secondary">
    <span class="live-dot" id="liveDot" title="Automatická obnova každých 30 s"></span>
    <span id="lastRefresh">aktualizované teraz</span>
  </div>
</div>

<div class="passkey-banner glass d-none" id="passkeyBanner" data-count="<?= $passkeys ?>">
  <div class="d-flex align-items-center gap-3">
    <div class="icon-bubble"><i class="bi bi-fingerprint"></i></div>
    <div class="flex-grow-1">
      <div class="fw-semibold">Zapni si prihlásenie odtlačkom prsta</div>
      <div class="small text-secondary">Nabudúce sa prihlásiš jedným dotykom.</div>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('settings.php#passkeys')) ?>">Zapnúť</a>
    <button class="btn-close" type="button" id="passkeyBannerClose" aria-label="Zavrieť"></button>
  </div>
</div>

<?php if (!$devices): ?>
  <div class="empty-state glass">
    <div class="empty-icon"><i class="bi bi-cpu"></i></div>
    <h2 class="h5 fw-bold">Zatiaľ tu nie sú žiadne zariadenia</h2>
    <p class="text-secondary">Pridaj svoje prvé ESP32 – dostaneš API kľúč a hotový kód na nahratie.</p>
    <a class="btn btn-primary" href="<?= e(url('devices.php?new=1')) ?>"><i class="bi bi-plus-lg me-1"></i> Pridať zariadenie</a>
  </div>
<?php else: ?>

<div class="summary">
  <div><span class="s-icon ic-temp"><i class="bi bi-thermometer-half"></i></span><div class="min-w-0"><div class="s-label">Priemerná teplota</div><div class="s-value"><span id="avgT"><?= fmt_num($avgT) ?></span><small>°C</small></div></div></div>
  <div><span class="s-icon ic-hum"><i class="bi bi-droplet-half"></i></span><div class="min-w-0"><div class="s-label">Priemerná vlhkosť</div><div class="s-value"><span id="avgH"><?= fmt_num($avgH, 0) ?></span><small>%</small></div></div></div>
  <div><span class="s-icon ic-ok"><i class="bi bi-wifi"></i></span><div class="min-w-0"><div class="s-label">Online</div><div class="s-value"><span id="onlineCount"><?= $online ?></span><small>z <?= count($sum) ?></small></div></div></div>
  <div><span class="s-icon ic-accent"><i class="bi bi-activity"></i></span><div class="min-w-0"><div class="s-label">Meraní dnes</div><div class="s-value"><span id="todayCount"><?= array_sum(array_map(fn($s) => $s['today']['count'] ?? 0, $sum)) ?></span></div></div></div>
</div>

<div class="row g-3" id="deviceGrid">
  <?php foreach ($sum as $s): ?>
  <div class="col-12 col-md-6 col-xxl-4">
    <article class="device-card glass" data-device="<?= $s['id'] ?>" style="--dev-color: <?= e($colors[$s['id']] ?? '#6366f1') ?>">
      <header class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0">
          <h2 class="device-name text-truncate"><?= e($s['name']) ?></h2>
          <div class="dev-sub text-truncate"><i class="bi bi-geo-alt"></i> <?= e($s['location'] ?: '@' . $s['slug']) ?></div>
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
      <canvas class="spark" height="48" data-f="spark"></canvas>
      <div class="dev-facts">
        <div><span>Dnes</span><b data-f="range"><?= $s['today'] ? fmt_num($s['today']['tmin']) . '–' . fmt_num($s['today']['tmax']) . ' °C' : '–' ?></b></div>
        <div><span>Rosný bod</span><b><span data-f="dew"><?= fmt_num($s['dew_point']) ?></span> °C</b></div>
        <div><span>Aktualizované</span><b data-f="seen"><?= e($s['last_seen_h']) ?></b></div>
      </div>
      <div class="device-foot">
        <span class="chip" data-f="comfort"><?= e($s['comfort'] ?? '–') ?></span>
        <a href="<?= e(url('history.php?d[]=' . $s['id'])) ?>" class="link-arrow">História <i class="bi bi-arrow-right"></i></a>
      </div>
    </article>
  </div>
  <?php endforeach; ?>
</div>

<section class="glass panel mt-3">
  <div class="panel-head">
    <h2 class="panel-title"><i class="bi bi-graph-up-arrow"></i> Posledných 24 hodín</h2>
    <div class="seg" id="metricSeg">
      <button class="active" data-metric="t" type="button"><i class="bi bi-thermometer-half"></i> Teplota</button>
      <button data-metric="h" type="button"><i class="bi bi-droplet-half"></i> Vlhkosť</button>
    </div>
  </div>
  <div class="chart-box"><canvas id="chart24"></canvas></div>
</section>
<?php endif; ?>

<?php
$useCharts = true;
$pageScripts = ['charts.js', 'dashboard.js'];
require __DIR__ . '/includes/footer.php';
