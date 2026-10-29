<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
start_session();
$u = require_login();
session_write_close();

$devices = user_devices((int)$u['id']);
$f = parse_filter($devices, $_GET);
$names = [];
foreach ($devices as $d) {
    $names[(int)$d['id']] = $d['name'];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="merania_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Čas', 'Zariadenie', 'Teplota (°C)', 'Vlhkosť (%)', 'Signál (dBm)'], ';');

if ($f['devices']) {
    $in = implode(',', array_fill(0, count($f['devices']), '?'));
    $st = db()->prepare('SELECT device_id, temperature, humidity, rssi, created_at FROM ' . t('readings')
        . " WHERE device_id IN ($in) AND created_at BETWEEN ? AND ? ORDER BY created_at, id LIMIT 500000");
    $st->execute([...$f['devices'], $f['from_s'], $f['to_s']]);
    while ($r = $st->fetch()) {
        $name = $names[(int)$r['device_id']] ?? '';
        if (preg_match('/^[=+\-@]/', $name)) {
            $name = "'" . $name; // ochrana proti CSV injection
        }
        fputcsv($out, [
            $r['created_at'],
            $name,
            str_replace('.', ',', (string)(float)$r['temperature']),
            str_replace('.', ',', (string)(float)$r['humidity']),
            $r['rssi'] ?? '',
        ], ';');
    }
}
fclose($out);
