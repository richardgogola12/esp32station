<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();

if (current_user()) {
    redirect('index.php');
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = client_ip();

    if (login_rate_limited($ip)) {
        $error = 'Príliš veľa neúspešných pokusov. Skús to znova o ' . (int)cfg('login.window_minutes', 15) . ' minút.';
    } elseif ($username === '' || $password === '') {
        $error = 'Vyplň meno aj heslo.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM ' . t('users') . ' WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$username, mb_strtolower($username)]);
        $u = $st->fetch();
        // Rovnaká časová náročnosť aj pre neexistujúceho používateľa
        $hash = $u['password_hash'] ?? '$2y$10$EyS2/dUiiKmITbxQ2Vnq1eysd.TlYXd6N9m.Zset/4rHAxZn6oWBG';
        $valid = password_verify($password, $hash) && $u;

        if ($valid) {
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE ' . t('users') . ' SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            }
            login_clear($ip);
            login_user((int)$u['id']);
            redirect(safe_next());
        }
        login_record($ip, $username);
        usleep(random_int(150000, 400000));
        $error = 'Nesprávne meno alebo heslo.';
    }
}

$pageTitle = 'Prihlásenie';
$authLayout = true;
require __DIR__ . '/includes/header.php';
?>
<main class="auth-wrap">
  <div class="auth-card glass" data-next="<?= e(safe_next()) ?>">
    <div class="text-center mb-4">
      <div class="brand-logo brand-logo-lg mx-auto mb-3"><i class="bi bi-thermometer-half"></i></div>
      <h1 class="h3 fw-bold mb-1">Vitaj späť</h1>
      <p class="text-secondary mb-0">Prihlás sa do <?= e(cfg('name')) ?></p>
    </div>

    <?php foreach (take_flash() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger d-flex gap-2 align-items-center"><i class="bi bi-exclamation-triangle"></i><?= e($error) ?></div>
    <?php endif; ?>
    <div class="alert alert-danger d-none" id="passkeyError"></div>

    <button type="button" class="btn btn-passkey w-100 mb-3 d-none" id="passkeyLogin">
      <i class="bi bi-fingerprint"></i>
      <span>Prihlásiť sa odtlačkom prsta</span>
    </button>
    <div class="divider-text d-none" id="passkeyDivider"><span>alebo heslom</span></div>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e(safe_next()) ?>">
      <div class="form-floating mb-3">
        <input type="text" class="form-control" id="username" name="username" placeholder="meno" value="<?= e($username) ?>"
               autocomplete="username webauthn" autocapitalize="none" required autofocus>
        <label for="username">Meno alebo e-mail</label>
      </div>
      <div class="form-floating mb-3 position-relative">
        <input type="password" class="form-control" id="password" name="password" placeholder="heslo" autocomplete="current-password" required>
        <label for="password">Heslo</label>
        <button class="btn btn-reveal" type="button" data-reveal="#password" tabindex="-1"><i class="bi bi-eye"></i></button>
      </div>
      <button class="btn btn-primary btn-lg w-100" type="submit">Prihlásiť sa</button>
    </form>

    <?php if (cfg('allow_register')): ?>
      <p class="text-center text-secondary mt-4 mb-0">Nemáš účet? <a href="<?= e(url('register.php')) ?>" class="fw-semibold">Zaregistruj sa</a></p>
    <?php endif; ?>
  </div>
</main>
<?php
$pageScripts = ['webauthn.js', 'login.js'];
require __DIR__ . '/includes/footer.php';
