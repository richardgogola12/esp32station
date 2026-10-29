<?php
declare(strict_types=1);

/**
 * Jednorazová inštalácia databázových tabuliek.
 * Po úspešnej inštalácii tento súbor ZMAŽ zo servera.
 */

require __DIR__ . '/includes/bootstrap.php';
send_security_headers();

$messages = [];
$ok = false;

try {
    $pdo = db();
    $users = t('users');
    $exists = (bool)$pdo->query('SHOW TABLES LIKE ' . $pdo->quote($users))->fetchColumn();
    $hasUsers = $exists && (int)$pdo->query("SELECT COUNT(*) FROM `$users`")->fetchColumn() > 0;

    if ($hasUsers) {
        $messages[] = ['warning', 'Aplikácia je už nainštalovaná a obsahuje používateľov. Inštalácia sa preskočila. Zmaž súbor install.php.'];
        $ok = true;
    } else {
        $sql = (string)file_get_contents(__DIR__ . '/install.sql');
        $prefix = (string)cfg('table_prefix', 'esp32dth_');
        if ($prefix !== 'esp32dth_') {
            $sql = str_replace('esp32dth_', $prefix, $sql);
        }
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
        $messages[] = ['success', 'Tabuľky s prefixom „' . $prefix . '“ boli vytvorené.'];
        $messages[] = ['warning', 'Z bezpečnostných dôvodov teraz zmaž súbor install.php zo servera.'];
        $ok = true;
    }
} catch (Throwable $ex) {
    $messages[] = ['danger', 'Chyba: ' . $ex->getMessage()];
}
?>
<!doctype html>
<html lang="sk" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inštalácia – <?= e(cfg('name')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="auth-body">
<main class="auth-wrap">
  <div class="auth-card glass">
    <h1 class="h4 mb-3">Inštalácia databázy</h1>
    <?php foreach ($messages as [$type, $msg]): ?>
      <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
    <?php endforeach; ?>
    <?php if ($ok): ?>
      <a class="btn btn-primary w-100" href="<?= e(url('register.php')) ?>">Pokračovať na registráciu</a>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
