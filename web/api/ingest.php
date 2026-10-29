<?php
declare(strict_types=1);

/**
 * API pre ESP32 – zápis meraní.
 *
 * POST /api/ingest.php
 *   Hlavičky:  X-Device-Name: <identifikátor zariadenia>
 *              X-Api-Key:     <API kľúč zariadenia>
 *   Telo:      {"temperature": 21.5, "humidity": 45, "rssi": -60}
 *   Odpoveď:   {"ok": true, "interval": 60, ...}
 *
 * GET /api/ingest.php  (rovnaké hlavičky) – vráti len konfiguráciu (interval).
 * Session sa tu vôbec nespúšťa.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    json_out(['ok' => false, 'error' => 'Metóda nie je povolená'], 405);
}

$slug = strtolower(trim((string)($_SERVER['HTTP_X_DEVICE_NAME'] ?? $_GET['device'] ?? '')));
$key  = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));

if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $slug) || !preg_match('/^[a-f0-9]{48}$/', $key)) {
    usleep(300000);
    json_out(['ok' => false, 'error' => 'Chýba alebo je neplatné meno zariadenia / API kľúč'], 401);
}

try {
    $st = db()->prepare('SELECT * FROM ' . t('devices') . ' WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $dev = $st->fetch();

    if (!$dev || !hash_equals((string)$dev['api_key_hash'], hash('sha256', $key))) {
        usleep(300000);
        json_out(['ok' => false, 'error' => 'Neplatné meno zariadenia alebo API kľúč'], 401);
    }

    $interval = (int)$dev['interval_sec'];
    $base = ['interval' => $interval, 'server_time' => now(), 'device' => $dev['slug']];

    if (!(int)$dev['is_active']) {
        json_out(['ok' => false, 'error' => 'Zariadenie je deaktivované'] + $base, 403);
    }

    if ($method === 'GET') {
        json_out(['ok' => true] + $base);
    }

    $in = read_json_body(2048);
    $temp = $in['temperature'] ?? $in['t'] ?? null;
    $hum  = $in['humidity'] ?? $in['h'] ?? null;
    $rssi = isset($in['rssi']) && is_numeric($in['rssi']) ? max(-127, min(0, (int)$in['rssi'])) : null;

    if (!is_numeric($temp) || !is_numeric($hum)) {
        json_out(['ok' => false, 'error' => 'Chýba teplota alebo vlhkosť'] + $base, 422);
    }
    $temp = (float)$temp + (float)$dev['temp_offset'];
    $hum  = (float)$hum + (float)$dev['hum_offset'];
    if ($temp < -40 || $temp > 85 || $hum < 0 || $hum > 100) {
        json_out(['ok' => false, 'error' => 'Hodnoty mimo rozsahu senzora'] + $base, 422);
    }

    // Ochrana pred zahltením – minimálne polovica intervalu medzi zápismi
    $minGap = max(5, (int)floor($interval / 2));
    if ($dev['last_seen_at'] && time() - strtotime($dev['last_seen_at']) < $minGap) {
        json_out(['ok' => false, 'error' => 'Príliš časté odosielanie'] + $base, 429);
    }

    $ts = now();
    db()->prepare('INSERT INTO ' . t('readings') . ' (device_id, temperature, humidity, rssi, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$dev['id'], round($temp, 2), round($hum, 2), $rssi, $ts]);
    db()->prepare('UPDATE ' . t('devices') . ' SET last_temperature = ?, last_humidity = ?, last_rssi = ?, last_seen_at = ?, last_ip = ? WHERE id = ?')
        ->execute([round($temp, 2), round($hum, 2), $rssi, $ts, client_ip(), $dev['id']]);

    // Občasné premazanie starých dát
    $ret = (int)cfg('retention_days', 365);
    if ($ret > 0 && random_int(1, 200) === 1) {
        db()->prepare('DELETE FROM ' . t('readings') . ' WHERE created_at < ? LIMIT 5000')
            ->execute([date('Y-m-d H:i:s', time() - $ret * 86400)]);
    }

    json_out(['ok' => true, 'stored' => ['temperature' => round($temp, 2), 'humidity' => round($hum, 2), 'at' => $ts]] + $base, 201);
} catch (PDOException $ex) {
    error_log('[esp32dth] ingest: ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => 'Chyba servera'], 500);
}
