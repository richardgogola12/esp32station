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
    // hodnota spred 24 h (v okne ±40 min, aby sa neporovnávalo s veľmi starým meraním)
    $yest = db()->prepare('SELECT temperature, humidity FROM ' . t('readings') . ' WHERE device_id = ? AND created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 1');
    $yFrom = date('Y-m-d H:i:s', time() - 86400 - 2400);
    $yTo = date('Y-m-d H:i:s', time() - 86400 + 2400);

    $out = [];
    foreach ($devices as $d) {
        $id = (int)$d['id'];
        $prev->execute([$id, $hourAgo]);
        $p = $prev->fetch() ?: null;
        $yest->execute([$id, $yFrom, $yTo]);
        $y = $yest->fetch() ?: null;
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
            'yesterday_t' => $y ? round((float)$y['temperature'], 1) : null,
            'yesterday_h' => $y ? round((float)$y['humidity'], 1) : null,
            'vs_yesterday_t' => ($y && $t !== null) ? round($t - (float)$y['temperature'], 1) : null,
            'vs_yesterday_h' => ($y && $h !== null) ? round($h - (float)$y['humidity'], 1) : null,
            'feels_like'  => ($t !== null && $h !== null) ? feels_like($t, $h) : null,
            'abs_humidity' => ($t !== null && $h !== null) ? abs_humidity($t, $h) : null,
            'tip'         => ($t !== null && $h !== null) ? air_tip($t, $h) : null,
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

/** Pocitová teplota (Apparent Temperature, bez vetra – vhodná do interiéru). */
function feels_like(float $t, float $h): float
{
    $e = $h / 100 * 6.105 * exp(17.27 * $t / (237.7 + $t));
    return round($t + 0.33 * $e - 4.0, 1);
}

/** Absolútna vlhkosť v g/m³. */
function abs_humidity(float $t, float $h): float
{
    return round(6.112 * exp(17.67 * $t / ($t + 243.5)) * $h * 2.1674 / (273.15 + $t), 1);
}

/** Krátky praktický tip podľa teploty a vlhkosti. */
function air_tip(float $t, float $h): array
{
    $dew = dew_point($t, $h);
    if ($h >= 70) return ['icon' => '🪟', 'text' => 'Vyvetraj – vysoká vlhkosť, riziko plesne'];
    if ($dew !== null && $t - $dew < 4) return ['icon' => '💧', 'text' => 'Riziko kondenzácie na oknách'];
    if ($h >= 60) return ['icon' => '🌬️', 'text' => 'Vlhkosť stúpa – oplatí sa vyvetrať'];
    if ($h < 30) return ['icon' => '🏜️', 'text' => 'Suchý vzduch – zvlhči miestnosť'];
    if ($t > 26) return ['icon' => '🌡️', 'text' => 'Teplo – vyvetraj alebo zatieň'];
    if ($t < 18) return ['icon' => '🧣', 'text' => 'Chladno – prikúr'];
    return ['icon' => '👌', 'text' => 'Ideálne podmienky'];
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

/**
 * Dve obdobia na porovnanie: A (zvolené) a B (predchádzajúce rovnako dlhé).
 * mode: day | week | month | custom
 */
function compare_periods(string $mode, string $date, array $q): array
{
    $ts = strtotime($date) ?: time();
    switch ($mode) {
        case 'week':
            $a0 = strtotime('monday this week', $ts);
            $a1 = strtotime('+1 week', $a0) - 1;
            $b0 = strtotime('-1 week', $a0);
            $b1 = $a0 - 1;
            $agg = 'hour';
            $la = 'Týždeň od ' . date('j. n.', $a0);
            $lb = 'Týždeň od ' . date('j. n.', $b0);
            break;
        case 'month':
            $a0 = strtotime(date('Y-m-01 00:00:00', $ts));
            $a1 = strtotime('+1 month', $a0) - 1;
            $b0 = strtotime('-1 month', $a0);
            $b1 = $a0 - 1;
            $agg = 'hour';
            $months = ['', 'január', 'február', 'marec', 'apríl', 'máj', 'jún', 'júl', 'august', 'september', 'október', 'november', 'december'];
            $la = ucfirst($months[(int)date('n', $a0)]) . ' ' . date('Y', $a0);
            $lb = ucfirst($months[(int)date('n', $b0)]) . ' ' . date('Y', $b0);
            break;
        case 'custom':
            $p = fn($v, $d) => (is_string($v) && $v !== '' && ($x = strtotime($v)) !== false) ? $x : $d;
            $a0 = $p($q['a_from'] ?? '', strtotime('today'));
            $a1 = $p($q['a_to'] ?? '', time());
            $b0 = $p($q['b_from'] ?? '', $a0 - 86400);
            $b1 = $p($q['b_to'] ?? '', $a1 - 86400);
            if ($a1 <= $a0) $a1 = $a0 + 86400;
            if ($b1 <= $b0) $b1 = $b0 + ($a1 - $a0);
            $span = max($a1 - $a0, $b1 - $b0);
            $agg = $span <= 2 * 86400 ? '15m' : ($span <= 62 * 86400 ? 'hour' : 'day');
            $la = date('j. n. H:i', $a0) . ' – ' . date('j. n. H:i', $a1);
            $lb = date('j. n. H:i', $b0) . ' – ' . date('j. n. H:i', $b1);
            break;
        default:
            $mode = 'day';
            $a0 = strtotime(date('Y-m-d 00:00:00', $ts));
            $a1 = $a0 + 86399;
            $b0 = $a0 - 86400;
            $b1 = $a0 - 1;
            $agg = '15m';
            $la = date('j. n. Y', $a0) === date('j. n. Y') ? 'Dnes' : date('j. n. Y', $a0);
            $lb = date('j. n. Y', $b0) === date('j. n. Y', strtotime('yesterday')) ? 'Včera' : date('j. n. Y', $b0);
    }
    return [
        'mode' => $mode, 'agg' => $agg,
        'a' => ['from' => $a0, 'to' => $a1, 'label' => $la],
        'b' => ['from' => $b0, 'to' => $b1, 'label' => $lb],
    ];
}

function compare_data(int $deviceId, array $cp): array
{
    $out = [];
    foreach (['a', 'b'] as $k) {
        $f = [
            'devices' => [$deviceId], 'eff_agg' => $cp['agg'],
            'from_s' => date('Y-m-d H:i:s', $cp[$k]['from']), 'to_s' => date('Y-m-d H:i:s', $cp[$k]['to']),
        ];
        $pts = series($f)[$deviceId] ?? [];
        $start = $cp[$k]['from'];
        $out[$k] = [
            'label'  => $cp[$k]['label'],
            'from'   => $f['from_s'],
            'to'     => $f['to_s'],
            'points' => array_map(fn($p) => ['o' => strtotime($p['x']) - $start, 't' => $p['t'], 'h' => $p['h']], $pts),
            'stats'  => stats($f)[$deviceId] ?? null,
        ];
    }
    return $out;
}
