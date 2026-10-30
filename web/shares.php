<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();
$u = require_login();
$uid = (int)$u['id'];
ensure_schema();
$tbl = t('shares');
$devices = user_devices($uid);
$owned = array_map(fn($d) => (int)$d['id'], $devices);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $ids = array_values(array_intersect(array_map('intval', (array)($_POST['devices'] ?? [])), $owned));
        $days = (int)($_POST['max_days'] ?? 7);
        $days = in_array($days, [1, 7, 30, 90], true) ? $days : 7;
        $exp = (int)($_POST['expires'] ?? 0);
        $expAt = in_array($exp, [1, 7, 30, 365], true) ? date('Y-m-d H:i:s', time() + $exp * 86400) : null;
        $cnt = db()->prepare("SELECT COUNT(*) FROM $tbl WHERE user_id = ?");
        $cnt->execute([$uid]);
        if ($name === '' || mb_strlen($name) > 80) {
            flash('danger', 'Zadaj názov odkazu (max. 80 znakov).');
        } elseif (!$ids) {
            flash('danger', 'Vyber aspoň jedno zariadenie.');
        } elseif ((int)$cnt->fetchColumn() >= 30) {
            flash('danger', 'Dosiahnutý limit 30 odkazov.');
        } else {
            db()->prepare("INSERT INTO $tbl (user_id, token, name, device_ids, max_days, show_location, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$uid, bin2hex(random_bytes(16)), $name, implode(',', $ids), $days, isset($_POST['show_location']) ? 1 : 0, $expAt, now()]);
            flash('success', 'Odkaz „' . $name . '“ je vytvorený. Skopíruj ho a pošli, komu chceš.');
        }
    } elseif ($action === 'toggle') {
        db()->prepare("UPDATE $tbl SET is_active = 1 - is_active WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
        flash('success', 'Stav odkazu bol zmenený.');
    } elseif ($action === 'delete') {
        db()->prepare("DELETE FROM $tbl WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
        flash('success', 'Odkaz bol zmazaný a prestal fungovať.');
    }
    redirect('shares.php');
}

$st = db()->prepare("SELECT * FROM $tbl WHERE user_id = ? ORDER BY created_at DESC");
$st->execute([$uid]);
$shares = $st->fetchAll();
$names = array_column($devices, 'name', 'id');

$pageTitle = 'Zdieľanie';
$activeNav = 'shares';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="text-secondary">Verejný odkaz len na čítanie – bez prihlásenia</p>
    <h1 class="page-title">Zdieľanie</h1>
  </div>
  <?php if ($devices): ?>
  <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#shareModal"><i class="bi bi-plus-lg me-1"></i> Nový odkaz</button>
  <?php endif; ?>
</div>

<?php if (!$shares): ?>
  <div class="empty-state glass">
    <div class="empty-icon"><i class="bi bi-share"></i></div>
    <h2 class="h5 fw-bold">Zdieľaj merania s rodinou</h2>
    <p class="text-secondary mb-3">Vytvor odkaz, ktorý ukáže aktuálne hodnoty a graf vybraných zariadení. Kto ho má, nič nemôže meniť a nevidí tvoj účet.</p>
    <?php if ($devices): ?>
      <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#shareModal"><i class="bi bi-plus-lg me-1"></i> Vytvoriť odkaz</button>
    <?php else: ?>
      <a class="btn btn-primary" href="<?= e(url('devices.php')) ?>">Najprv pridaj zariadenie</a>
    <?php endif; ?>
  </div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($shares as $sh):
      $link = absolute_url('share.php?t=' . $sh['token']);
      $expired = $sh['expires_at'] && strtotime($sh['expires_at']) < time();
      $ok = (int)$sh['is_active'] === 1 && !$expired;
      $devs = array_filter(array_map(fn($i) => $names[(int)$i] ?? null, explode(',', $sh['device_ids'])));
  ?>
  <div class="col-12 col-xl-6">
    <article class="glass panel h-100">
      <div class="d-flex align-items-start gap-3">
        <div class="icon-bubble sm"><i class="bi bi-link-45deg"></i></div>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <h2 class="h6 fw-bold mb-0 text-truncate"><?= e($sh['name']) ?></h2>
            <span class="status-badge <?= $ok ? 'online' : ($expired ? 'offline' : 'paused') ?>"><?= $ok ? 'Aktívny' : ($expired ? 'Vypršal' : 'Vypnutý') ?></span>
          </div>
          <div class="small text-secondary mt-1"><?= e(implode(', ', $devs) ?: '–') ?></div>
        </div>
      </div>
      <div class="copy-field my-3"><code id="sl<?= (int)$sh['id'] ?>"><?= e($link) ?></code><button class="btn btn-sm btn-copy" type="button" data-copy="#sl<?= (int)$sh['id'] ?>"><i class="bi bi-clipboard"></i></button></div>
      <div class="manage-stats">
        <div><span>História</span><b><?= (int)$sh['max_days'] ?> d</b></div>
        <div><span>Platnosť</span><b><?= $sh['expires_at'] ? e(date('j. n. Y', strtotime($sh['expires_at']))) : 'navždy' ?></b></div>
        <div><span>Zobrazení</span><b><?= (int)$sh['views'] ?></b></div>
        <div><span>Naposledy</span><b><?= e(time_ago($sh['last_view_at'])) ?></b></div>
      </div>
      <div class="manage-actions">
        <a class="btn btn-sm btn-primary" href="<?= e($link) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i> Otvoriť</a>
        <button class="btn btn-sm btn-ghost" type="button" data-share="<?= e($link) ?>" data-title="<?= e($sh['name']) ?>"><i class="bi bi-share me-1"></i> Poslať</button>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$sh['id'] ?>">
          <button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-<?= (int)$sh['is_active'] ? 'pause' : 'play' ?>-fill me-1"></i> <?= (int)$sh['is_active'] ? 'Vypnúť' : 'Zapnúť' ?></button></form>
        <form method="post" class="d-inline" data-confirm-submit="Zmazať odkaz? Kto ho má, už ho neotvorí."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$sh['id'] ?>">
          <button class="btn btn-sm btn-ghost text-danger" type="submit"><i class="bi bi-trash me-1"></i> Zmazať</button></form>
      </div>
    </article>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal fade" id="shareModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
    <form class="modal-content glass-modal" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold">Nový zdieľaný odkaz</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavrieť"></button>
      </div>
      <div class="modal-body pt-0">
        <div class="mb-3">
          <label class="form-label" for="shName">Názov</label>
          <input class="form-control" id="shName" name="name" maxlength="80" placeholder="napr. Pre rodičov" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Zariadenia</label>
          <div class="device-chips">
            <?php foreach ($devices as $d): ?>
              <label class="dchip" style="--dev-color: <?= e($d['color']) ?>"><input type="checkbox" name="devices[]" value="<?= (int)$d['id'] ?>" checked><span><i class="dot"></i><?= e($d['name']) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label" for="shDays">História v grafe</label>
            <select class="form-select" id="shDays" name="max_days">
              <option value="1">24 hodín</option><option value="7" selected>7 dní</option><option value="30">30 dní</option><option value="90">90 dní</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label" for="shExp">Platnosť</label>
            <select class="form-select" id="shExp" name="expires">
              <option value="0">Navždy</option><option value="1">1 deň</option><option value="7">7 dní</option><option value="30">30 dní</option><option value="365">1 rok</option>
            </select>
          </div>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="shLoc" name="show_location" checked>
          <label class="form-check-label" for="shLoc">Zobraziť adresu / umiestnenie</label>
        </div>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Zrušiť</button>
        <button type="submit" class="btn btn-primary px-4">Vytvoriť odkaz</button>
      </div>
    </form>
  </div>
</div>
<?php
$pageScripts = ['shares.js'];
require __DIR__ . '/includes/footer.php';
