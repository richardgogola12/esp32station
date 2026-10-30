<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** @var string $pageTitle  @var string $activeNav  @var bool $authLayout  @var string $pageSubtitle */
$pageTitle    = $pageTitle ?? '';
$pageSubtitle = $pageSubtitle ?? '';
$activeNav    = $activeNav ?? '';
$authLayout   = $authLayout ?? false;
$user         = $authLayout ? null : current_user();
send_security_headers();

$nav = [
    'dashboard' => ['index.php',    'bi-grid-1x2',     'Prehľad'],
    'history'   => ['history.php',  'bi-graph-up',     'História'],
    'compare'   => ['compare.php',  'bi-arrow-left-right', 'Porovnanie'],
    'devices'   => ['devices.php',  'bi-cpu',          'Zariadenia'],
    'shares'    => ['shares.php',   'bi-share',        'Zdieľanie'],
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
<meta name="theme-color" content="#0f1420" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f5f7fb" media="(prefers-color-scheme: light)">
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

<?php if (!$authLayout && $user): ?>
<!-- Bočné menu (počítač) -->
<aside class="sidebar d-none d-lg-flex">
  <a class="brand" href="<?= e(url('index.php')) ?>">
    <span class="brand-logo"><i class="bi bi-thermometer-half"></i></span>
    <span><?= e(cfg('short_name')) ?></span>
  </a>
  <nav class="side-nav">
    <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
      <a class="<?= $activeNav === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>">
        <i class="bi <?= $icon ?>"></i><span><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <button class="side-btn d-none" id="installBtn" type="button"><i class="bi bi-download"></i><span>Nainštalovať aplikáciu</span></button>
    <button class="side-btn" id="themeToggle" type="button"><i class="bi bi-moon-stars"></i><span>Prepnúť tému</span></button>
    <div class="side-user">
      <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></span>
      <div class="min-w-0 flex-grow-1">
        <div class="fw-semibold text-truncate"><?= e($user['username']) ?></div>
        <div class="small text-secondary text-truncate"><?= e($user['email']) ?></div>
      </div>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="m-0">
        <?= csrf_field() ?>
        <button class="btn-icon" type="submit" title="Odhlásiť sa"><i class="bi bi-box-arrow-right"></i></button>
      </form>
    </div>
  </div>
</aside>

<!-- Horná lišta (mobil) -->
<header class="topbar d-lg-none">
  <a class="brand" href="<?= e(url('index.php')) ?>">
    <span class="brand-logo"><i class="bi bi-thermometer-half"></i></span>
    <span><?= e(cfg('short_name')) ?></span>
  </a>
  <div class="d-flex align-items-center gap-1">
    <button class="btn-icon d-none" id="installBtnM" type="button" title="Nainštalovať"><i class="bi bi-download"></i></button>
    <button class="btn-icon" id="themeToggleM" type="button" title="Téma"><i class="bi bi-moon-stars"></i></button>
    <form method="post" action="<?= e(url('logout.php')) ?>" class="m-0">
      <?= csrf_field() ?>
      <button class="btn-icon" type="submit" title="Odhlásiť sa"><i class="bi bi-box-arrow-right"></i></button>
    </form>
  </div>
</header>
<?php endif; ?>

<?php if (!$authLayout): ?>
<main class="app-main">
  <div class="toast-stack" id="toastStack">
    <?php foreach (take_flash() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($f['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zavrieť"></button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
