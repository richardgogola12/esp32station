<?php
declare(strict_types=1);

/**
 * Dáta pre webové rozhranie (vyžaduje prihlásenie).
 *   GET ?action=latest                         – aktuálne hodnoty všetkých zariadení
 *   GET ?action=series&d=1,2&from=&to=&agg=    – časové rady + štatistiky
 */

require dirname(__DIR__) . '/includes/bootstrap.php';
start_session();
$u = require_login(true);
session_write_close();

$devices = user_devices((int)$u['id']);
$action = (string)($_GET['action'] ?? 'latest');

try {
    if ($action === 'latest') {
        $sum = devices_summary($devices);
        $spark = sparklines(array_column($sum, 'id'));
        foreach ($sum as &$s) {
            $s['spark'] = $spark[$s['id']] ?? null;
        }
        unset($s);
        json_out(['ok' => true, 'devices' => $sum, 'server_time' => now()]);
    }

    if ($action === 'series') {
        $f = parse_filter($devices, $_GET);
        $names = [];
        foreach ($devices as $d) {
            $names[(int)$d['id']] = ['name' => $d['name'], 'color' => $d['color']];
        }
        $series = series($f);
        $out = [];
        foreach ($series as $id => $pts) {
            $out[] = ['id' => $id, 'name' => $names[$id]['name'] ?? '?', 'color' => $names[$id]['color'] ?? '#6366f1', 'points' => $pts];
        }
        json_out([
            'ok'     => true,
            'agg'    => $f['eff_agg'],
            'from'   => $f['from_s'],
            'to'     => $f['to_s'],
            'series' => $out,
            'stats'  => stats($f),
        ]);
    }

    json_out(['ok' => false, 'error' => 'Neznáma akcia'], 400);
} catch (PDOException $ex) {
    error_log('[esp32dth] data: ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => 'Chyba servera'], 500);
}
