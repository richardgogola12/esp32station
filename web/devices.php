<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();
$u = require_login();
$uid = (int)$u['id'];
$tbl = t('devices');
ensure_schema();

const DEVICE_COLORS = ['#6366f1', '#06b6d4', '#22c55e', '#f59e0b', '#ef4444', '#ec4899', '#8b5cf6', '#14b8a6'];

function new_api_key(): array
{
    $key = bin2hex(random_bytes(24));
    return [$key, hash('sha256', $key), substr($key, -4), encrypt_secret($key)];
}

function device_input(): array
{
    $interval = (int)($_POST['interval_value'] ?? 60) * max(1, (int)($_POST['interval_unit'] ?? 1));
    $color = (string)($_POST['color'] ?? '#6366f1');
    return [
        'name'        => trim((string)($_POST['name'] ?? '')),
        'slug'        => slugify((string)($_POST['slug'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'location'    => trim((string)($_POST['location'] ?? '')),
        'interval'    => $interval,
        'temp_offset' => round((float)str_replace(',', '.', (string)($_POST['temp_offset'] ?? '0')), 1),
        'hum_offset'  => round((float)str_replace(',', '.', (string)($_POST['hum_offset'] ?? '0')), 1),
        'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        'color'       => in_array($color, DEVICE_COLORS, true) ? $color : '#6366f1',
    ];
}

function device_errors(array $d, int $uid, int $exceptId = 0): array
{
    $err = [];
    if ($d['name'] === '' || mb_strlen($d['name']) > 60) $err[] = 'Názov je povinný (max. 60 znakov).';
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $d['slug'])) $err[] = 'Identifikátor musí mať 2–40 znakov (a–z, 0–9, pomlčka).';
    if (mb_strlen($d['description']) > 500) $err[] = 'Popis môže mať najviac 500 znakov.';
    if (mb_strlen($d['location']) > 80) $err[] = 'Umiestnenie môže mať najviac 80 znakov.';
    if ($d['interval'] < 10 || $d['interval'] > 86400) $err[] = 'Interval musí byť medzi 10 sekundami a 24 hodinami.';
    if (abs($d['temp_offset']) > 20 || abs($d['hum_offset']) > 30) $err[] = 'Korekcia je mimo povoleného rozsahu.';
    if (!$err) {
        $st = db()->prepare('SELECT id FROM ' . t('devices') . ' WHERE slug = ? AND id <> ?');
        $st->execute([$d['slug'], $exceptId]);
        if ($st->fetch()) $err[] = 'Identifikátor „' . $d['slug'] . '“ už používa iné zariadenie. Zvoľ iný.';
    }
    return $err;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'create') {
        $d = device_input();
        $err = device_errors($d, $uid);
        $cnt = db()->prepare("SELECT COUNT(*) FROM $tbl WHERE user_id = ?");
        $cnt->execute([$uid]);
        if ((int)$cnt->fetchColumn() >= 50) $err[] = 'Dosiahnutý limit 50 zariadení.';
        if ($err) {
            foreach ($err as $er) flash('danger', $er);
        } else {
            [$key, $hash, $hint, $enc] = new_api_key();
            db()->prepare("INSERT INTO $tbl (user_id, slug, name, description, location, color, api_key_hash, api_key_hint, api_key_enc, interval_sec, temp_offset, hum_offset, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$uid, $d['slug'], $d['name'], $d['description'] ?: null, $d['location'] ?: null, $d['color'], $hash, $hint, $enc,
                    $d['interval'], $d['temp_offset'], $d['hum_offset'], $d['is_active'], now()]);
            sess_set('newkey', ['id' => (int)db()->lastInsertId(), 'key' => $key]);
            flash('success', 'Zariadenie „' . $d['name'] . '“ bolo pridané.');
        }
    } elseif ($action === 'update' && ($dev = user_device($uid, $id))) {
        $d = device_input();
        $err = device_errors($d, $uid, $id);
        if ($err) {
            foreach ($err as $er) flash('danger', $er);
        } else {
            db()->prepare("UPDATE $tbl SET slug = ?, name = ?, description = ?, location = ?, color = ?, interval_sec = ?, temp_offset = ?, hum_offset = ?, is_active = ? WHERE id = ? AND user_id = ?")
                ->execute([$d['slug'], $d['name'], $d['description'] ?: null, $d['location'] ?: null, $d['color'], $d['interval'],
                    $d['temp_offset'], $d['hum_offset'], $d['is_active'], $id, $uid]);
            flash('success', 'Zmeny boli uložené.' . ($d['slug'] !== $dev['slug'] ? ' Nezabudni zmeniť DEVICE_NAME aj v kóde ESP32.' : ''));
        }
    } elseif ($action === 'regen' && user_device($uid, $id)) {
        [$key, $hash, $hint, $enc] = new_api_key();
        db()->prepare("UPDATE $tbl SET api_key_hash = ?, api_key_hint = ?, api_key_enc = ? WHERE id = ? AND user_id = ?")->execute([$hash, $hint, $enc, $id, $uid]);
        sess_set('newkey', ['id' => $id, 'key' => $key]);
        flash('warning', 'Bol vygenerovaný nový API kľúč. Starý kľúč prestal platiť.');
    } elseif ($action === 'delete' && ($dev = user_device($uid, $id))) {
        db()->prepare("DELETE FROM $tbl WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
        flash('success', 'Zariadenie „' . $dev['name'] . '“ a jeho merania boli zmazané.');
    } elseif ($action === 'clear' && ($dev = user_device($uid, $id))) {
        db()->prepare('DELETE FROM ' . t('readings') . ' WHERE device_id = ?')->execute([$id]);
        db()->prepare("UPDATE $tbl SET last_temperature = NULL, last_humidity = NULL, last_seen_at = NULL WHERE id = ?")->execute([$id]);
        flash('success', 'Merania zariadenia „' . $dev['name'] . '“ boli vymazané.');
    } elseif ($action === 'reveal') {
        $dev = user_device($uid, $id);
        $key = $dev ? decrypt_secret($dev['api_key_enc'] ?? null) : null;
        json_out($key
            ? ['ok' => true, 'key' => $key, 'slug' => $dev['slug']]
            : ['ok' => false, 'slug' => $dev['slug'] ?? '', 'error' => 'Kľúč tohto zariadenia nie je uložený (bolo vytvorené v staršej verzii). Vygeneruj nový kľúč – odteraz sa bude dať kedykoľvek zobraziť.']);
    } elseif ($action === 'reorder') {
        $order = array_map('intval', explode(',', (string)($_POST['order'] ?? '')));
        $st = db()->prepare("UPDATE $tbl SET sort_order = ? WHERE id = ? AND user_id = ?");
        foreach ($order as $i => $did) $st->execute([$i, $did, $uid]);
        json_out(['ok' => true]);
    }
    redirect('devices.php');
}

$devices = user_devices($uid);
$counts = [];
if ($devices) {
    $st = db()->prepare('SELECT device_id, COUNT(*) c FROM ' . t('readings') . ' r JOIN ' . $tbl . ' d ON d.id = r.device_id WHERE d.user_id = ? GROUP BY device_id');
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) $counts[(int)$r['device_id']] = (int)$r['c'];
}
$newKey = sess('newkey');
sess_unset('newkey');
$ingestUrl = absolute_url('api/ingest.php');

$pageTitle = 'Zariadenia';
$activeNav = 'devices';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="text-secondary mb-1">Správa ESP32 senzorov</p>
    <h1 class="page-title">Zariadenia</h1>
  </div>
  <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#deviceModal" data-mode="create">
    <i class="bi bi-plus-lg me-1"></i> Pridať zariadenie
  </button>
</div>

<?php if (!$devices): ?>
  <div class="empty-state glass">
    <div class="empty-icon"><i class="bi bi-router"></i></div>
    <h2 class="h4 fw-bold">Pridaj svoje prvé ESP32</h2>
    <p class="text-secondary mb-4">Daj mu názov, nastav interval merania a dostaneš API kľúč s hotovým kódom.</p>
    <ol class="steps text-start mx-auto">
      <li><b>Pridaj zariadenie</b> – názov, umiestnenie, interval.</li>
      <li><b>Skopíruj nastavenia</b> do kódu ESP32 (identifikátor + kľúč).</li>
      <li><b>Nahraj kód</b> do ESP32 – dáta sa začnú zobrazovať v Prehľade.</li>
    </ol>
    <button class="btn btn-primary btn-lg mt-2" type="button" data-bs-toggle="modal" data-bs-target="#deviceModal" data-mode="create"><i class="bi bi-plus-lg me-1"></i> Pridať zariadenie</button>
  </div>
<?php else: ?>
<div class="row g-3" id="deviceList">
  <?php foreach ($devices as $d): $on = device_online($d); ?>
  <div class="col-12 col-lg-6" data-id="<?= (int)$d['id'] ?>">
    <article class="manage-card glass" style="--dev-color: <?= e($d['color']) ?>">
      <div class="d-flex align-items-start gap-3">
        <div class="dev-avatar"><i class="bi bi-cpu"></i></div>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <h2 class="h5 fw-bold mb-0 text-truncate"><?= e($d['name']) ?></h2>
            <?php if (!(int)$d['is_active']): ?>
              <span class="status-badge paused">Pozastavené</span>
            <?php else: ?>
              <span class="status-badge <?= $on ? 'online' : 'offline' ?>"><?= $on ? 'Online' : 'Offline' ?></span>
            <?php endif; ?>
          </div>
          <div class="small text-secondary mt-1">
            <code class="slug">@<?= e($d['slug']) ?></code>
            <?php if ($d['location']): ?> · <i class="bi bi-geo-alt"></i> <?= e($d['location']) ?><?php endif; ?>
          </div>
          <?php if ($d['description']): ?><p class="small mt-2 mb-0 text-body-secondary"><?= nl2br(e($d['description'])) ?></p><?php endif; ?>
        </div>
      </div>
      <div class="manage-stats">
        <div><span>Interval</span><b><?= e(format_interval((int)$d['interval_sec'])) ?></b></div>
        <div><span>Posledné</span><b><?= $d['last_temperature'] !== null ? fmt_num($d['last_temperature']) . '° · ' . fmt_num($d['last_humidity'], 0) . '%' : '–' ?></b></div>
        <div><span>Videné</span><b><?= e(time_ago($d['last_seen_at'])) ?></b></div>
        <div><span>Meraní</span><b><?= number_format($counts[(int)$d['id']] ?? 0, 0, ',', ' ') ?></b></div>
      </div>
      <div class="small text-secondary d-flex flex-wrap gap-3">
        <span><i class="bi bi-key"></i> kľúč …<?= e($d['api_key_hint']) ?></span>
        <?php if ($d['last_rssi'] !== null): ?><span><i class="bi bi-wifi"></i> <?= (int)$d['last_rssi'] ?> dBm</span><?php endif; ?>
        <?php if ((float)$d['temp_offset'] != 0 || (float)$d['hum_offset'] != 0): ?><span><i class="bi bi-sliders"></i> korekcia <?= fmt_num($d['temp_offset']) ?>° / <?= fmt_num($d['hum_offset']) ?>%</span><?php endif; ?>
      </div>
      <div class="manage-actions">
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#espModal" data-id="<?= (int)$d['id'] ?>" data-slug="<?= e($d['slug']) ?>" data-name="<?= e($d['name']) ?>">
          <i class="bi bi-key me-1"></i> API kľúč a kód
        </button>
        <button class="btn btn-sm btn-ghost" type="button" data-bs-toggle="modal" data-bs-target="#deviceModal" data-mode="edit" data-device='<?= e(json_encode([
            'id' => (int)$d['id'], 'name' => $d['name'], 'slug' => $d['slug'], 'description' => (string)$d['description'],
            'location' => (string)$d['location'], 'interval' => (int)$d['interval_sec'], 'temp_offset' => (float)$d['temp_offset'],
            'hum_offset' => (float)$d['hum_offset'], 'is_active' => (bool)$d['is_active'], 'color' => $d['color'],
        ], JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil me-1"></i> Upraviť</button>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('history.php?d[]=' . $d['id'])) ?>"><i class="bi bi-graph-up me-1"></i> História</a>
        <button class="btn btn-sm btn-ghost" type="button" data-confirm="clear" data-id="<?= (int)$d['id'] ?>" data-name="<?= e($d['name']) ?>"><i class="bi bi-eraser me-1"></i> Vymazať merania</button>
        <button class="btn btn-sm btn-ghost text-danger" type="button" data-confirm="delete" data-id="<?= (int)$d['id'] ?>" data-name="<?= e($d['name']) ?>"><i class="bi bi-trash me-1"></i> Zmazať</button>
      </div>
    </article>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Pridať / upraviť zariadenie -->
<div class="modal fade" id="deviceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
    <form class="modal-content glass-modal" method="post" id="deviceForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="id" value="0">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold" id="deviceModalTitle">Nové zariadenie</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavrieť"></button>
      </div>
      <div class="modal-body pt-0">
        <div class="mb-3">
          <label class="form-label" for="fName">Názov</label>
          <input class="form-control form-control-lg" id="fName" name="name" maxlength="60" placeholder="napr. Obývačka" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="fSlug">Identifikátor pre ESP32</label>
          <div class="input-group">
            <span class="input-group-text">@</span>
            <input class="form-control font-monospace" id="fSlug" name="slug" maxlength="40" pattern="[a-z0-9][a-z0-9_\-]{1,39}" placeholder="obyvacka" required>
          </div>
          <div class="form-text">Týmto menom sa ESP32 hlási na API (DEVICE_NAME). Vytvorí sa automaticky z názvu.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="fLocation">Umiestnenie</label>
          <input class="form-control" id="fLocation" name="location" maxlength="80" placeholder="napr. Prízemie, pri okne">
        </div>
        <div class="mb-3">
          <label class="form-label" for="fDesc">Popis</label>
          <textarea class="form-control" id="fDesc" name="description" rows="2" maxlength="500" placeholder="Voliteľná poznámka"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Interval merania</label>
          <div class="interval-presets mb-2" id="intervalPresets">
            <button type="button" data-sec="30">30 s</button>
            <button type="button" data-sec="60">1 min</button>
            <button type="button" data-sec="300">5 min</button>
            <button type="button" data-sec="600">10 min</button>
            <button type="button" data-sec="1800">30 min</button>
            <button type="button" data-sec="3600">1 h</button>
          </div>
          <div class="input-group">
            <input class="form-control" type="number" min="1" max="86400" name="interval_value" id="fIntervalValue" value="1" required>
            <select class="form-select" name="interval_unit" id="fIntervalUnit" style="max-width: 9rem">
              <option value="1">sekúnd</option>
              <option value="60" selected>minút</option>
              <option value="3600">hodín</option>
            </select>
          </div>
          <div class="form-text">ESP32 si nový interval prevezme pri najbližšom odoslaní.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Farba</label>
          <div class="color-pick">
            <?php foreach (DEVICE_COLORS as $i => $c): ?>
              <label style="--c: <?= $c ?>"><input type="radio" name="color" value="<?= $c ?>" <?= $i === 0 ? 'checked' : '' ?>><span></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <details class="mb-3">
          <summary class="fw-semibold">Pokročilé – korekcia senzora</summary>
          <div class="row g-2 mt-1">
            <div class="col-6">
              <label class="form-label small" for="fTOff">Teplota (°C)</label>
              <input class="form-control" type="number" step="0.1" min="-20" max="20" name="temp_offset" id="fTOff" value="0">
            </div>
            <div class="col-6">
              <label class="form-label small" for="fHOff">Vlhkosť (%)</label>
              <input class="form-control" type="number" step="0.1" min="-30" max="30" name="hum_offset" id="fHOff" value="0">
            </div>
          </div>
          <div class="form-text">DHT11 má presnosť ±2 °C / ±5 %. Ak ho porovnávaš s presným teplomerom, zadaj rozdiel.</div>
        </details>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="fActive" name="is_active" checked>
          <label class="form-check-label" for="fActive">Zariadenie je aktívne (prijíma merania)</label>
        </div>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Zrušiť</button>
        <button type="submit" class="btn btn-primary px-4">Uložiť</button>
      </div>
    </form>
  </div>
</div>

<!-- Nastavenie ESP32 -->
<div class="modal fade" id="espModal" tabindex="-1" aria-hidden="true" data-ingest="<?= e($ingestUrl) ?>"
     data-auto-slug="<?= e($newKey ? (string)(array_values(array_filter($devices, fn($d) => (int)$d['id'] === (int)$newKey['id']))[0]['slug'] ?? '') : '') ?>"
     data-auto-id="<?= (int)($newKey['id'] ?? 0) ?>" data-auto-key="<?= e($newKey['key'] ?? '') ?>">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">
    <div class="modal-content glass-modal">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-code-slash me-2"></i>Nastavenie ESP32</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavrieť"></button>
      </div>
      <div class="modal-body pt-0">
        <div class="alert alert-success d-none" id="keyNew">
          <i class="bi bi-check-circle me-1"></i> Nový API kľúč je pripravený. Skopíruj riadky nižšie do kódu ESP32.
        </div>
        <div class="alert alert-warning d-none" id="keyMissing"></div>
        <div class="key-row mb-3">
          <label class="form-label small text-secondary mb-1">API kľúč zariadenia <b id="espSlug"></b></label>
          <div class="copy-field"><code id="espKey">načítavam…</code><button class="btn btn-sm btn-copy" type="button" data-copy="#espKey"><i class="bi bi-clipboard"></i></button></div>
        </div>
        <p class="small text-secondary">Tieto riadky prepíš v súbore <code>esp32_dht_station.ino</code> a nahraj ho do ESP32:</p>
        <div class="code-block">
          <button class="btn btn-sm btn-copy" type="button" data-copy="#espCode"><i class="bi bi-clipboard"></i> Kopírovať</button>
          <pre id="espCode"></pre>
        </div>
      </div>
      <div class="modal-footer border-0">
        <form method="post" class="me-auto" id="regenForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="regen">
          <input type="hidden" name="id" value="">
          <button class="btn btn-ghost btn-sm" type="submit"><i class="bi bi-arrow-repeat me-1"></i> Vygenerovať nový kľúč</button>
        </form>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Hotovo</button>
      </div>
    </div>
  </div>
</div>

<!-- Potvrdenie -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content glass-modal" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="">
      <input type="hidden" name="id" value="">
      <div class="modal-body text-center p-4">
        <div class="confirm-icon mb-3"><i class="bi bi-exclamation-triangle"></i></div>
        <h5 class="fw-bold" id="confirmTitle">Si si istý?</h5>
        <p class="text-secondary mb-0" id="confirmText"></p>
      </div>
      <div class="modal-footer border-0 justify-content-center pb-4">
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Zrušiť</button>
        <button type="submit" class="btn btn-danger px-4" id="confirmBtn">Potvrdiť</button>
      </div>
    </form>
  </div>
</div>
<?php
$pageScripts = ['devices.js'];
require __DIR__ . '/includes/footer.php';
