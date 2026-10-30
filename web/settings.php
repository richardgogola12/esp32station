<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();
$u = require_login();
$uid = (int)$u['id'];
$ut = t('users');
ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'widget') {
        $min = (int)($_POST['widget_refresh_min'] ?? 15);
        $min = max(1, min(1440, $min));
        db()->prepare("UPDATE $ut SET widget_refresh_min = ? WHERE id = ?")->execute([$min, $uid]);
        flash('success', 'Interval obnovy widgetov je ' . $min . ' min. Widgety si ho prevezmú pri najbližšej aktualizácii.');
    } elseif ($action === 'widget_token') {
        $token = bin2hex(random_bytes(20));
        db()->prepare("UPDATE $ut SET widget_token_hash = ?, widget_token_hint = ?, widget_token_enc = ? WHERE id = ?")
            ->execute([hash('sha256', $token), substr($token, -4), encrypt_secret($token), $uid]);
        flash('success', 'Nový token pre widgety je vygenerovaný. Starý token prestal platiť.');
    } elseif ($action === 'widget_revoke') {
        db()->prepare("UPDATE $ut SET widget_token_hash = NULL, widget_token_hint = NULL, widget_token_enc = NULL WHERE id = ?")->execute([$uid]);
        flash('success', 'Prístup widgetov bol zrušený.');
    } elseif ($action === 'password') {
        $st = db()->prepare("SELECT password_hash FROM $ut WHERE id = ?");
        $st->execute([$uid]);
        $hash = (string)$st->fetchColumn();
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify($cur, $hash)) {
            flash('danger', 'Súčasné heslo nie je správne.');
        } elseif (mb_strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            flash('danger', 'Nové heslo musí mať aspoň 8 znakov a obsahovať písmeno aj číslicu.');
        } elseif ($new !== (string)($_POST['new2'] ?? '')) {
            flash('danger', 'Nové heslá sa nezhodujú.');
        } else {
            db()->prepare("UPDATE $ut SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            flash('success', 'Heslo bolo zmenené.');
        }
    } elseif ($action === 'email') {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Neplatný e-mail.');
        } else {
            $st = db()->prepare("SELECT id FROM $ut WHERE email = ? AND id <> ?");
            $st->execute([$email, $uid]);
            if ($st->fetch()) {
                flash('danger', 'Tento e-mail už používa iný účet.');
            } else {
                db()->prepare("UPDATE $ut SET email = ? WHERE id = ?")->execute([$email, $uid]);
                flash('success', 'E-mail bol uložený.');
            }
        }
    }
    redirect('settings.php' . ($action === 'widget_token' || $action === 'widget' ? '#widgets' : ''));
}

$st = db()->prepare('SELECT id, name, created_at, last_used_at FROM ' . t('webauthn') . ' WHERE user_id = ? ORDER BY created_at DESC');
$st->execute([$uid]);
$passkeys = $st->fetchAll();

$tk = db()->prepare("SELECT widget_token_enc FROM $ut WHERE id = ?");
$tk->execute([$uid]);
$newToken = decrypt_secret($tk->fetchColumn() ?: null);
$server = absolute_url('');
$intentUrl = $newToken
    ? 'intent://setup?server=' . rawurlencode($server) . '&token=' . $newToken . '#Intent;scheme=esp32dth;package=sk.esp32dth.widget;end'
    : null;

$refreshOptions = [1 => '1 minúta', 2 => '2 minúty', 5 => '5 minút', 10 => '10 minút', 15 => '15 minút', 30 => '30 minút', 60 => '1 hodina', 180 => '3 hodiny', 360 => '6 hodín'];
$curRefresh = (int)$u['widget_refresh_min'];
if (!isset($refreshOptions[$curRefresh])) {
    $refreshOptions[$curRefresh] = $curRefresh . ' min';
    ksort($refreshOptions);
}

$pageTitle = 'Nastavenia';
$activeNav = 'settings';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="text-secondary mb-1">Účet, bezpečnosť a widgety</p>
    <h1 class="page-title">Nastavenia</h1>
  </div>
</div>

<?php if (isset($_GET['welcome'])): ?>
<div class="welcome-card glass mb-4">
  <div class="icon-bubble lg"><i class="bi bi-stars"></i></div>
  <div>
    <h2 class="h5 fw-bold mb-1">Vitaj v <?= e(cfg('name')) ?>!</h2>
    <p class="text-secondary mb-2">Odporúčame dva rýchle kroky: zapni si prihlásenie odtlačkom prsta a pridaj prvé ESP32.</p>
    <a class="btn btn-sm btn-primary" href="<?= e(url('devices.php')) ?>"><i class="bi bi-cpu me-1"></i> Pridať zariadenie</a>
  </div>
</div>
<?php endif; ?>

<a class="glass panel d-flex align-items-center gap-3 mb-4 text-reset d-lg-none" href="<?= e(url('shares.php')) ?>">
  <div class="icon-bubble sm"><i class="bi bi-share"></i></div>
  <div class="flex-grow-1"><div class="fw-semibold">Zdieľanie</div><div class="small text-secondary">Verejné odkazy na merania pre rodinu</div></div>
  <i class="bi bi-chevron-right text-secondary"></i>
</a>

<div class="row g-4">
  <div class="col-12 col-xl-6">
    <section class="glass panel h-100" id="passkeys">
      <div class="panel-head">
        <h2 class="panel-title"><i class="bi bi-fingerprint"></i> Prihlásenie odtlačkom prsta</h2>
      </div>
      <p class="small text-secondary">Použije sa odtlačok prsta, tvár alebo PIN tvojho telefónu či počítača (passkey). Heslo pritom zostáva ako záloha.</p>

      <div class="device-detect mb-3" id="bioDetect">
        <i class="bi bi-hourglass-split"></i> <span>Zisťujem možnosti tohto zariadenia…</span>
      </div>

      <div class="input-group mb-3">
        <input class="form-control" id="passkeyName" maxlength="80" placeholder="Názov zariadenia">
        <button class="btn btn-primary" type="button" id="passkeyAdd" disabled><i class="bi bi-plus-lg me-1"></i> Pridať tento telefón / PC</button>
      </div>
      <div class="alert d-none" id="passkeyMsg"></div>

      <ul class="list-modern" id="passkeyList">
        <?php if (!$passkeys): ?>
          <li class="text-secondary small justify-content-center">Zatiaľ nemáš uložený žiadny odtlačok.</li>
        <?php endif; ?>
        <?php foreach ($passkeys as $p): ?>
          <li data-id="<?= (int)$p['id'] ?>">
            <div class="icon-bubble sm"><i class="bi bi-phone"></i></div>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold text-truncate"><?= e($p['name']) ?></div>
              <div class="small text-secondary">pridané <?= e(date('j. n. Y', strtotime($p['created_at']))) ?> · použité <?= e(time_ago($p['last_used_at'])) ?></div>
            </div>
            <button class="btn btn-icon text-danger" type="button" data-passkey-del="<?= (int)$p['id'] ?>" title="Odstrániť"><i class="bi bi-trash"></i></button>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <div class="col-12 col-xl-6">
    <section class="glass panel h-100" id="widgets">
      <div class="panel-head">
        <h2 class="panel-title"><i class="bi bi-phone-vibrate"></i> Android widgety</h2>
      </div>
      <form method="post" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="widget">
        <label class="form-label" for="wr">Ako často sa majú widgety aktualizovať</label>
        <div class="input-group">
          <select class="form-select" id="wr" name="widget_refresh_min">
            <?php foreach ($refreshOptions as $m => $label): ?>
              <option value="<?= $m ?>" <?= $m === $curRefresh ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary" type="submit">Uložiť</button>
        </div>
        <div class="form-text">Android môže pri šetrení batérie aktualizácie mierne oneskoriť.</div>
      </form>

      <?php if ($newToken): ?>
        <div class="token-box mb-3">
          <div class="small text-secondary mb-2"><i class="bi bi-shield-lock me-1"></i> Token neposielaj nikomu – kto ho má, vidí tvoje merania.</div>
          <a class="btn btn-success w-100 mb-2 d-android" href="<?= e($intentUrl) ?>"><i class="bi bi-android2 me-1"></i> Nastaviť aplikáciu v tomto telefóne</a>
          <label class="form-label small text-secondary mb-1">Adresa servera</label>
          <div class="copy-field mb-2"><code id="srvUrl"><?= e($server) ?></code><button class="btn btn-sm btn-copy" type="button" data-copy="#srvUrl"><i class="bi bi-clipboard"></i></button></div>
          <label class="form-label small text-secondary mb-1">Token</label>
          <div class="copy-field"><code id="wToken"><?= e($newToken) ?></code><button class="btn btn-sm btn-copy" type="button" data-copy="#wToken"><i class="bi bi-clipboard"></i></button></div>
        </div>
      <?php else: ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="icon-bubble sm"><i class="bi bi-key"></i></div>
          <div class="flex-grow-1">
            <?php if ($u['widget_token_hint']): ?>
              <div class="fw-semibold">Token je aktívny</div>
              <div class="small text-secondary">končí na …<?= e($u['widget_token_hint']) ?> · bol vytvorený v staršej verzii a nedá sa zobraziť – vygeneruj nový</div>
            <?php else: ?>
              <div class="fw-semibold">Widgety zatiaľ nemajú prístup</div>
              <div class="small text-secondary">Vygeneruj token a zadaj ho v aplikácii.</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="d-flex gap-2 flex-wrap">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="widget_token">
          <button class="btn btn-ghost" type="submit"><i class="bi bi-arrow-repeat me-1"></i> <?= $u['widget_token_hint'] ? 'Vygenerovať nový token' : 'Vygenerovať token' ?></button>
        </form>
        <?php if ($u['widget_token_hint']): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="widget_revoke">
          <button class="btn btn-ghost text-danger" type="submit"><i class="bi bi-x-circle me-1"></i> Zrušiť prístup</button>
        </form>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="col-12 col-xl-6">
    <section class="glass panel h-100">
      <div class="panel-head"><h2 class="panel-title"><i class="bi bi-person"></i> Profil</h2></div>
      <dl class="kv mb-3">
        <dt>Používateľ</dt><dd><?= e($u['username']) ?></dd>
        <dt>Účet od</dt><dd><?= e(date('j. n. Y', strtotime($u['created_at']))) ?></dd>
        <dt>Posledné prihlásenie</dt><dd><?= e($u['last_login_at'] ? date('j. n. Y H:i', strtotime($u['last_login_at'])) : '–') ?></dd>
      </dl>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="email">
        <label class="form-label" for="em">E-mail</label>
        <div class="input-group">
          <input class="form-control" type="email" id="em" name="email" value="<?= e($u['email']) ?>" required>
          <button class="btn btn-ghost" type="submit">Uložiť</button>
        </div>
      </form>
    </section>
  </div>

  <div class="col-12 col-xl-6">
    <section class="glass panel h-100">
      <div class="panel-head"><h2 class="panel-title"><i class="bi bi-shield-lock"></i> Zmena hesla</h2></div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <input type="text" class="d-none" name="username" value="<?= e($u['username']) ?>" autocomplete="username" readonly>
        <div class="mb-2"><input class="form-control" type="password" name="current" placeholder="Súčasné heslo" autocomplete="current-password" required></div>
        <div class="mb-2"><input class="form-control" type="password" name="new" placeholder="Nové heslo" autocomplete="new-password" minlength="8" required></div>
        <div class="mb-3"><input class="form-control" type="password" name="new2" placeholder="Nové heslo znova" autocomplete="new-password" required></div>
        <button class="btn btn-primary" type="submit">Zmeniť heslo</button>
      </form>
    </section>
  </div>
</div>
<?php
$pageScripts = ['webauthn.js', 'settings.js'];
require __DIR__ . '/includes/footer.php';
