<?php
declare(strict_types=1);

/**
 * Verejné API pre zdieľaný odkaz (len na čítanie, bez prihlásenia).
 *   GET ?t=<token>&action=latest
 *   GET ?t=<token>&action=series&d=<id>&days=<1..max_days>
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

try {
    $sh = load_share((string)($_GET['t'] ?? ''));
    if (!$sh) {
        usleep(200000);
        json_out(['ok' => false, 'error' => 'Odkaz neexistuje alebo už neplatí'], 404);
    }
    $devices = share_devices($sh);
    $action = (string)($_GET['action'] ?? 'latest');

    if ($action === 'latest') {
        $sum = devices_summary($devices);
        $spark = sparklines(array_column($sum, 'id'));
        $out = [];
        foreach ($sum as $s) {
            $out[] = [
                'id' => $s['id'], 'name' => $s['name'],
                'location' => (int)$sh['show_location'] ? (string)$s['location'] : '',
                'temperature' => $s['temperature'], 'humidity' => $s['humidity'],
                'trend_t' => $s['trend_t'], 'trend_h' => $s['trend_h'],
                'dew_point' => $s['dew_point'], 'feels_like' => $s['feels_like'], 'abs_humidity' => $s['abs_humidity'],
                'comfort' => $s['comfort'], 'tip' => $s['tip'], 'vs_yesterday_t' => $s['vs_yesterday_t'],
                'online' => $s['online'], 'last_seen_h' => $s['last_seen_h'], 'today' => $s['today'],
                'spark' => $spark[$s['id']] ?? null,
            ];
        }
        json_out(['ok' => true, 'devices' => $out, 'max_days' => (int)$sh['max_days'], 'server_time' => now()]);
    }

    if ($action === 'series') {
        $days = max(1, min((int)$sh['max_days'], (int)($_GET['days'] ?? 1)));
        $f = parse_filter($devices, ['d' => (string)($_GET['d'] ?? ''), 'from' => date('Y-m-d H:i:s', time() - $days * 86400), 'to' => date('Y-m-d H:i:s'), 'agg' => 'auto']);
        $names = array_column($devices, null, 'id');
        $out = [];
        foreach (series($f, 3000) as $id => $pts) {
            $out[] = ['id' => $id, 'name' => $names[$id]['name'] ?? '', 'color' => $names[$id]['color'] ?? '#6366f1', 'points' => $pts];
        }
        json_out(['ok' => true, 'agg' => $f['eff_agg'], 'series' => $out]);
    }
    json_out(['ok' => false, 'error' => 'Neznáma akcia'], 400);
} catch (PDOException $ex) {
    error_log('[esp32dth] public: ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => 'Chyba servera'], 500);
}
