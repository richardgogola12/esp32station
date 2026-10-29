<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** @var string $pageTitle  @var string $activeNav  @var bool $authLayout */
$pageTitle  = $pageTitle ?? '';
$activeNav  = $activeNav ?? '';
$authLayout = $authLayout ?? false;
$user       = $authLayout ? null : current_user();
send_security_headers();

$nav = [
    'dashboard' => ['index.php',    'bi-speedometer2', 'Prehľad'],
    'history'   => ['history.php',  'bi-graph-up',     'História'],
    'devices'   => ['devices.php',  'bi-cpu',          'Zariadenia'],
    'settings'  => ['settings.php', 'bi-gear',         'Nastavenia'],
];
?>
<!doctype html>
<html lang="sk" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(($pageTitle ? $pageTitle . ' · ' : '') . cfg('name')) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="app-base" content="<?= e(base_path()) ?>">
<meta name="theme-color" content="#0b1020" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f4f6fb" media="(prefers-color-scheme: light)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(cfg('short_name')) ?>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(url('assets/icons/favicon-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('assets/icons/apple-touch-icon.png')) ?>">
<script src="<?= e(url('assets/js/theme.js')) ?>"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/app.css')) ?>?v=<?= APP_VERSION ?>" rel="stylesheet">
</head>
<body class="<?= $authLayout ? 'auth-body' : 'app-body' ?>">
<div class="bg-orbs" aria-hidden="true"><span></span><span></span><span></span></div>

<?php if (!$authLayout && $user): ?>
<nav class="navbar navbar-expand-lg app-navbar sticky-top">
  <div class="container-xl">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('index.php')) ?>">
      <span class="brand-logo"><i class="bi bi-thermometer-half"></i></span>
      <span class="fw-bold"><?= e(cfg('short_name')) ?></span>
    </a>
    <div class="d-none d-lg-flex ms-4 gap-1">
      <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
        <a class="nav-pill <?= $activeNav === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>">
          <i class="bi <?= $icon ?>"></i> <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="ms-auto d-flex align-items-center gap-2">
      <button class="btn btn-icon d-none" id="installBtn" type="button" title="Nainštalovať aplikáciu"><i class="bi bi-download"></i></button>
      <button class="btn btn-icon" id="themeToggle" type="button" title="Prepnúť tému"><i class="bi bi-moon-stars"></i></button>
      <div class="dropdown">
        <button class="btn btn-avatar" data-bs-toggle="dropdown" aria-expanded="false" type="button">
          <?= e(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-lg">
          <li><h6 class="dropdown-header"><?= e($user['username']) ?><br><small class="text-secondary fw-normal"><?= e($user['email']) ?></small></h6></li>
          <li><a class="dropdown-item" href="<?= e(url('settings.php')) ?>"><i class="bi bi-gear me-2"></i>Nastavenia</a></li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <form method="post" action="<?= e(url('logout.php')) ?>" class="m-0">
              <?= csrf_field() ?>
              <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Odhlásiť sa</button>
            </form>
          </li>
        </ul>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>

<?php if (!$authLayout): ?>
<main class="container-xl app-main">
  <div class="toast-stack" id="toastStack">
    <?php foreach (take_flash() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show glass-alert" role="alert">
        <?= e($f['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zavrieť"></button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
