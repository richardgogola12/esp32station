<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();

if (current_user()) {
    redirect('index.php');
}

$errors = [];
$data = ['username' => '', 'email' => ''];
$allowed = (bool)cfg('allow_register');

if ($allowed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ip = client_ip();
    $data['username'] = trim((string)($_POST['username'] ?? ''));
    $data['email']    = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (login_rate_limited($ip)) {
        $errors[] = 'Príliš veľa pokusov z tejto adresy. Skús to neskôr.';
    } else {
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $data['username'])) {
            $errors[] = 'Meno musí mať 3–32 znakov (písmená bez diakritiky, čísla, bodka, pomlčka, podčiarkovník).';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 190) {
            $errors[] = 'Zadaj platný e-mail.';
        }
        if (mb_strlen($pass) < 8) {
            $errors[] = 'Heslo musí mať aspoň 8 znakov.';
        } elseif (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
            $errors[] = 'Heslo musí obsahovať písmeno aj číslicu.';
        }
        if ($pass !== $pass2) {
            $errors[] = 'Heslá sa nezhodujú.';
        }
        if (!$errors) {
            $st = db()->prepare('SELECT username = ? AS same_name FROM ' . t('users') . ' WHERE username = ? OR email = ? LIMIT 1');
            $st->execute([$data['username'], $data['username'], $data['email']]);
            $dup = $st->fetch();
            if ($dup) {
                $errors[] = $dup['same_name'] ? 'Toto meno je už obsadené.' : 'Účet s týmto e-mailom už existuje.';
                login_record($ip, 'register');
            }
        }
        if (!$errors) {
            db()->prepare('INSERT INTO ' . t('users') . ' (username, email, password_hash, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$data['username'], $data['email'], password_hash($pass, PASSWORD_DEFAULT), now()]);
            $uid = (int)db()->lastInsertId();
            login_user($uid);
            flash('success', 'Účet je vytvorený. Vitaj!');
            redirect('settings.php?welcome=1');
        }
    }
}

$pageTitle = 'Registrácia';
$authLayout = true;
require __DIR__ . '/includes/header.php';
?>
<main class="auth-wrap">
  <div class="auth-card glass">
    <div class="text-center mb-4">
      <div class="brand-logo brand-logo-lg mx-auto mb-3"><i class="bi bi-person-plus"></i></div>
      <h1 class="h3 fw-bold mb-1">Vytvor si účet</h1>
      <p class="text-secondary mb-0">Sleduj teplotu a vlhkosť zo všetkých ESP32</p>
    </div>

    <?php if (!$allowed): ?>
      <div class="alert alert-warning">Registrácia je vypnutá. Kontaktuj správcu.</div>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="form-floating mb-3">
          <input type="text" class="form-control" id="username" name="username" placeholder="meno" value="<?= e($data['username']) ?>"
                 autocomplete="username" autocapitalize="none" pattern="[A-Za-z0-9._\-]{3,32}" required autofocus>
          <label for="username">Používateľské meno</label>
        </div>
        <div class="form-floating mb-3">
          <input type="email" class="form-control" id="email" name="email" placeholder="email" value="<?= e($data['email']) ?>" autocomplete="email" required>
          <label for="email">E-mail</label>
        </div>
        <div class="form-floating mb-2 position-relative">
          <input type="password" class="form-control" id="password" name="password" placeholder="heslo" autocomplete="new-password" minlength="8" required>
          <label for="password">Heslo</label>
          <button class="btn btn-reveal" type="button" data-reveal="#password" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
        <div class="pw-meter mb-3" id="pwMeter"><span></span></div>
        <div class="form-floating mb-4">
          <input type="password" class="form-control" id="password2" name="password2" placeholder="heslo" autocomplete="new-password" required>
          <label for="password2">Heslo znova</label>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Zaregistrovať sa</button>
        <p class="small text-secondary text-center mt-3 mb-0"><i class="bi bi-fingerprint"></i> Po registrácii si môžeš zapnúť prihlásenie odtlačkom prsta.</p>
      </form>
    <?php endif; ?>
    <p class="text-center text-secondary mt-4 mb-0">Už máš účet? <a href="<?= e(url('login.php')) ?>" class="fw-semibold">Prihlás sa</a></p>
  </div>
</main>
<?php
require __DIR__ . '/includes/footer.php';
