<?php
declare(strict_types=1);

/**
 * Dátové funkcie – zariadenia, merania, štatistiky, časové rady.
 */

function user_devices(int $uid): array
{
    $st = db()->prepare('SELECT * FROM ' . t('devices') . ' WHERE user_id = ? ORDER BY sort_order, name');
    $st->execute([$uid]);
    return $st->fetchAll();
}

function user_device(int $uid, int $id): ?array
{
    $st = db()->prepare('SELECT * FROM ' . t('devices') . ' WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    return $st->fetch() ?: null;
}

/** Dnešné min/max a hodnota spred hodiny (trend) pre zoznam zariadení. */
function devices_summary(array $devices): array
{
    if (!$devices) {
        return [];
    }
    $ids = array_map(fn($d) => (int)$d['id'], $devices);
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $st = db()->prepare('SELECT device_id, MIN(temperature) tmin, MAX(temperature) tmax, MIN(humidity) hmin, MAX(humidity) hmax, AVG(temperature) tavg, AVG(humidity) havg, COUNT(*) cnt
        FROM ' . t('readings') . " WHERE device_id IN ($in) AND created_at >= ? GROUP BY device_id");
    $st->execute([...$ids, date('Y-m-d 00:00:00')]);
    $today = [];
    foreach ($st->fetchAll() as $r) {
        $today[(int)$r['device_id']] = $r;
    }

    $hourAgo = date('Y-m-d H:i:s', time() - 3600);
    $prev = db()->prepare('SELECT temperature, humidity FROM ' . t('readings') . ' WHERE device_id = ? AND created_at <= ? ORDER BY created_at DESC LIMIT 1');

    $out = [];
    foreach ($devices as $d) {
        $id = (int)$d['id'];
        $prev->execute([$id, $hourAgo]);
        $p = $prev->fetch() ?: null;
        $t = $d['last_temperature'] !== null ? (float)$d['last_temperature'] : null;
        $h = $d['last_humidity'] !== null ? (float)$d['last_humidity'] : null;
        $out[] = [
            'id'          => $id,
            'slug'        => $d['slug'],
            'name'        => $d['name'],
            'location'    => $d['location'],
            'description' => $d['description'],
            'interval'    => (int)$d['interval_sec'],
            'active'      => (bool)$d['is_active'],
            'temperature' => $t,
            'humidity'    => $h,
            'last_seen'   => $d['last_seen_at'],
            'last_seen_h' => time_ago($d['last_seen_at']),
            'online'      => device_online($d),
            'rssi'        => $d['last_rssi'] !== null ? (int)$d['last_rssi'] : null,
            'trend_t'     => ($p && $t !== null) ? round($t - (float)$p['temperature'], 1) : null,
            'trend_h'     => ($p && $h !== null) ? round($h - (float)$p['humidity'], 1) : null,
            'dew_point'   => ($t !== null && $h !== null) ? dew_point($t, $h) : null,
            'comfort'     => ($t !== null && $h !== null) ? comfort_label($t, $h) : null,
            'today'       => isset($today[$id]) ? [
                'tmin' => round((float)$today[$id]['tmin'], 1),
                'tmax' => round((float)$today[$id]['tmax'], 1),
                'hmin' => round((float)$today[$id]['hmin'], 1),
                'hmax' => round((float)$today[$id]['hmax'], 1),
                'tavg' => round((float)$today[$id]['tavg'], 1),
                'havg' => round((float)$today[$id]['havg'], 1),
                'count' => (int)$today[$id]['cnt'],
            ] : null,
        ];
    }
    return $out;
}

function dew_point(float $t, float $h): ?float
{
    if ($h <= 0) {
        return null;
    }
    $a = 17.62; $b = 243.12;
    $g = ($a * $t) / ($b + $t) + log($h / 100.0);
    return round(($b * $g) / ($a - $g), 1);
}

function comfort_label(float $t, float $h): string
{
    if ($t < 18) return 'Chladno';
    if ($t > 26) return 'Teplo';
    if ($h < 30) return 'Suchý vzduch';
    if ($h > 65) return 'Vlhko';
    return 'Príjemne';
}

/** Hodinové priemery za posledných 24 h (pre minigrafy a widgety). */
function sparklines(array $deviceIds, int $hours = 24): array
{
    $out = [];
    if (!$deviceIds) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($deviceIds), '?'));
    $startTs = (int)(floor(time() / 3600) * 3600) - ($hours - 1) * 3600;
    $st = db()->prepare("SELECT device_id, DATE_FORMAT(created_at, '%Y-%m-%d %H') hb, AVG(temperature) t, AVG(humidity) h
        FROM " . t('readings') . " WHERE device_id IN ($in) AND created_at >= ? GROUP BY device_id, hb");
    $st->execute([...array_map('intval', $deviceIds), date('Y-m-d H:00:00', $startTs)]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[(int)$r['device_id']][$r['hb']] = $r;
    }
    foreach ($deviceIds as $id) {
        $tArr = []; $hArr = [];
        for ($i = 0; $i < $hours; $i++) {
            $key = date('Y-m-d H', $startTs + $i * 3600);
            $r = $rows[(int)$id][$key] ?? null;
            $tArr[] = $r ? round((float)$r['t'], 1) : null;
            $hArr[] = $r ? round((float)$r['h'], 1) : null;
        }
        $out[(int)$id] = ['t' => $tArr, 'h' => $hArr];
    }
    return $out;
}

/** Spracuje filter (zariadenia, od, do, agregácia) z GET parametrov. */
function parse_filter(array $devices, array $q): array
{
    $owned = array_map(fn($d) => (int)$d['id'], $devices);
    $sel = $q['d'] ?? [];
    if (is_string($sel)) {
        $sel = explode(',', $sel);
    }
    $sel = array_values(array_intersect(array_map('intval', (array)$sel), $owned));
    if (!$sel) {
        $sel = $owned;
    }

    $parse = function ($v, int $fallback): int {
        if (is_string($v) && $v !== '') {
            $ts = strtotime(str_replace('T', ' ', $v));
            if ($ts !== false) return $ts;
        }
        return $fallback;
    };
    $to   = $parse($q['to'] ?? '', time());
    $from = $parse($q['from'] ?? '', $to - 86400);
    if ($from >= $to) {
        $from = $to - 86400;
    }

    $agg = (string)($q['agg'] ?? 'auto');
    if (!in_array($agg, ['auto', 'raw', '15m', 'hour', 'day'], true)) {
        $agg = 'auto';
    }
    $span = $to - $from;
    $eff = $agg;
    if ($agg === 'auto') {
        $eff = $span <= 2 * 86400 ? 'raw' : ($span <= 8 * 86400 ? '15m' : ($span <= 62 * 86400 ? 'hour' : 'day'));
    }

    return [
        'devices' => $sel,
        'from'    => $from,
        'to'      => $to,
        'agg'     => $agg,
        'eff_agg' => $eff,
        'from_s'  => date('Y-m-d H:i:s', $from),
        'to_s'    => date('Y-m-d H:i:s', $to),
    ];
}

function series(array $f, int $rawLimit = 6000): array
{
    $out = [];
    foreach ($f['devices'] as $id) {
        $id = (int)$id;
        switch ($f['eff_agg']) {
            case 'raw':
                $sql = 'SELECT created_at x, temperature t, humidity h FROM ' . t('readings')
                    . ' WHERE device_id = ? AND created_at BETWEEN ? AND ? ORDER BY created_at LIMIT ' . $rawLimit;
                break;
            case '15m':
                $sql = "SELECT DATE_FORMAT(MIN(created_at), '%Y-%m-%d %H:%i:00') x, ROUND(AVG(temperature),2) t, ROUND(AVG(humidity),2) h,
                        MIN(temperature) tmin, MAX(temperature) tmax, MIN(humidity) hmin, MAX(humidity) hmax
                        FROM " . t('readings') . ' WHERE device_id = ? AND created_at BETWEEN ? AND ?
                        GROUP BY FLOOR(UNIX_TIMESTAMP(created_at) / 900) ORDER BY x';
                break;
            case 'hour':
                $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') x, ROUND(AVG(temperature),2) t, ROUND(AVG(humidity),2) h,
                        MIN(temperature) tmin, MAX(temperature) tmax, MIN(humidity) hmin, MAX(humidity) hmax
                        FROM " . t('readings') . ' WHERE device_id = ? AND created_at BETWEEN ? AND ? GROUP BY x ORDER BY x';
                break;
            default:
                $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d 12:00:00') x, ROUND(AVG(temperature),2) t, ROUND(AVG(humidity),2) h,
                        MIN(temperature) tmin, MAX(temperature) tmax, MIN(humidity) hmin, MAX(humidity) hmax
                        FROM " . t('readings') . ' WHERE device_id = ? AND created_at BETWEEN ? AND ? GROUP BY x ORDER BY x';
        }
        $st = db()->prepare($sql);
        $st->execute([$id, $f['from_s'], $f['to_s']]);
        $pts = [];
        foreach ($st->fetchAll() as $r) {
            $p = ['x' => $r['x'], 't' => (float)$r['t'], 'h' => (float)$r['h']];
            if (isset($r['tmin'])) {
                $p += ['tmin' => (float)$r['tmin'], 'tmax' => (float)$r['tmax'], 'hmin' => (float)$r['hmin'], 'hmax' => (float)$r['hmax']];
            }
            $pts[] = $p;
        }
        $out[$id] = $pts;
    }
    return $out;
}

function stats(array $f): array
{
    if (!$f['devices']) {
        return [];
    }
    $in = implode(',', array_fill(0, count($f['devices']), '?'));
    $st = db()->prepare('SELECT device_id, COUNT(*) cnt, MIN(temperature) tmin, MAX(temperature) tmax, AVG(temperature) tavg,
        MIN(humidity) hmin, MAX(humidity) hmax, AVG(humidity) havg, MIN(created_at) first_at, MAX(created_at) last_at
        FROM ' . t('readings') . " WHERE device_id IN ($in) AND created_at BETWEEN ? AND ? GROUP BY device_id");
    $st->execute([...$f['devices'], $f['from_s'], $f['to_s']]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['device_id']] = [
            'count' => (int)$r['cnt'],
            'tmin' => round((float)$r['tmin'], 1), 'tmax' => round((float)$r['tmax'], 1), 'tavg' => round((float)$r['tavg'], 1),
            'hmin' => round((float)$r['hmin'], 1), 'hmax' => round((float)$r['hmax'], 1), 'havg' => round((float)$r['havg'], 1),
            'first_at' => $r['first_at'], 'last_at' => $r['last_at'],
        ];
    }
    return $out;
}
