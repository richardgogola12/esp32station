<?php
declare(strict_types=1);

/**
 * API pre Android widgety.
 *   GET /api/widget.php
 *   Hlavička: Authorization: Bearer <widget token>   (alebo X-Widget-Token)
 *
 * Vracia aktuálne hodnoty všetkých zariadení používateľa, dnešné min/max,
 * hodinové priemery za 24 h a interval obnovy widgetov nastavený na webe.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = '';
if (preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', $auth, $m)) {
    $token = $m[1];
} elseif (!empty($_SERVER['HTTP_X_WIDGET_TOKEN'])) {
    $token = (string)$_SERVER['HTTP_X_WIDGET_TOKEN'];
}

if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
    usleep(300000);
    json_out(['ok' => false, 'error' => 'Chýba alebo je neplatný token'], 401);
}

try {
    $st = db()->prepare('SELECT id, username, widget_refresh_min FROM ' . t('users') . ' WHERE widget_token_hash = ? LIMIT 1');
    $st->execute([hash('sha256', $token)]);
    $u = $st->fetch();
    if (!$u) {
        usleep(300000);
        json_out(['ok' => false, 'error' => 'Neplatný token'], 401);
    }

    $devices = array_values(array_filter(user_devices((int)$u['id']), fn($d) => (int)$d['is_active'] === 1));
    $sum = devices_summary($devices);
    $spark = sparklines(array_column($sum, 'id'));

    $out = [];
    foreach ($sum as $s) {
        $out[] = [
            'slug'        => $s['slug'],
            'name'        => $s['name'],
            'location'    => (string)$s['location'],
            'temperature' => $s['temperature'],
            'humidity'    => $s['humidity'],
            'dew_point'   => $s['dew_point'],
            'comfort'     => $s['comfort'],
            'vs_yesterday_t' => $s['vs_yesterday_t'],
            'vs_yesterday_h' => $s['vs_yesterday_h'],
            'feels_like'  => $s['feels_like'],
            'abs_humidity' => $s['abs_humidity'],
            'tip'         => $s['tip'] ? $s['tip']['icon'] . ' ' . $s['tip']['text'] : null,
            'trend_t'     => $s['trend_t'],
            'trend_h'     => $s['trend_h'],
            'last_seen'   => $s['last_seen'],
            'age_sec'     => $s['last_seen'] ? max(0, time() - strtotime($s['last_seen'])) : null,
            'online'      => $s['online'],
            'interval'    => $s['interval'],
            'today'       => $s['today'],
            'spark'       => $spark[$s['id']] ?? ['t' => [], 'h' => []],
        ];
    }

    json_out([
        'ok'              => true,
        'user'            => $u['username'],
        'refresh_minutes' => max(1, (int)$u['widget_refresh_min']),
        'server_time'     => now(),
        'dashboard_url'   => absolute_url('index.php'),
        'devices'         => $out,
    ]);
} catch (PDOException $ex) {
    error_log('[esp32dth] widget: ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => 'Chyba servera'], 500);
}
