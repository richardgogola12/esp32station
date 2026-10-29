<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    logout_user();
    flash('success', 'Bol si odhlásený.');
    redirect('login.php');
}
redirect('index.php');
