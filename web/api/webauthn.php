<?php
declare(strict_types=1);

/**
 * WebAuthn endpointy (prihlásenie odtlačkom prsta / tvárou / PIN zariadenia).
 *   POST ?action=register_options | register_verify   (prihlásený používateľ)
 *   POST ?action=login_options    | login_verify      (verejné)
 *   POST ?action=delete           (prihlásený, {id})
 */

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/webauthn.php';

start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Metóda nie je povolená'], 405);
}
csrf_verify(true);

$action = (string)($_GET['action'] ?? '');
$in = read_json_body();
$tbl = t('webauthn');

try {
    switch ($action) {
        case 'register_options': {
            $u = require_login(true);
            $st = db()->prepare("SELECT credential_id FROM $tbl WHERE user_id = ?");
            $st->execute([$u['id']]);
            $ids = array_column($st->fetchAll(), 'credential_id');
            json_out(['ok' => true, 'publicKey' => WebAuthn::registrationOptions($u, $ids)]);
        }

        case 'register_verify': {
            $u = require_login(true);
            $res = WebAuthn::verifyRegistration(
                b64url_decode((string)($in['clientDataJSON'] ?? '')),
                b64url_decode((string)($in['attestationObject'] ?? ''))
            );
            $credId = b64url_encode($res['credentialId']);
            if (strlen($credId) > 255) {
                throw new RuntimeException('Identifikátor kľúča je príliš dlhý');
            }
            $name = trim((string)($in['name'] ?? ''));
            $name = mb_substr($name !== '' ? $name : 'Moje zariadenie', 0, 80);

            $cnt = db()->prepare("SELECT COUNT(*) FROM $tbl WHERE user_id = ?");
            $cnt->execute([$u['id']]);
            if ((int)$cnt->fetchColumn() >= 20) {
                throw new RuntimeException('Dosiahnutý limit 20 kľúčov');
            }

            db()->prepare("INSERT INTO $tbl (user_id, credential_id, public_key_pem, sign_count, name, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$u['id'], $credId, $res['pem'], $res['signCount'], $name, now()]);
            json_out(['ok' => true, 'message' => 'Prihlásenie odtlačkom je aktivované.']);
        }

        case 'login_options': {
            if (login_rate_limited(client_ip())) {
                json_out(['ok' => false, 'error' => 'Príliš veľa pokusov. Skús to o pár minút.'], 429);
            }
            json_out(['ok' => true, 'publicKey' => WebAuthn::loginOptions()]);
        }

        case 'login_verify': {
            $ip = client_ip();
            if (login_rate_limited($ip)) {
                json_out(['ok' => false, 'error' => 'Príliš veľa pokusov. Skús to o pár minút.'], 429);
            }
            $credId = (string)($in['rawId'] ?? $in['id'] ?? '');
            $credId = b64url_encode(b64url_decode($credId));
            $st = db()->prepare("SELECT * FROM $tbl WHERE credential_id = ? LIMIT 1");
            $st->execute([$credId]);
            $cred = $st->fetch();
            if (!$cred) {
                login_record($ip, 'passkey');
                throw new RuntimeException('Tento kľúč nie je zaregistrovaný. Prihlás sa heslom a aktivuj odtlačok v Nastaveniach.');
            }
            $userHandle = b64url_decode((string)($in['userHandle'] ?? ''));
            if ($userHandle !== '' && !hash_equals(WebAuthn::userHandle((int)$cred['user_id']), $userHandle)) {
                login_record($ip, 'passkey');
                throw new RuntimeException('Kľúč nepatrí tomuto účtu');
            }
            try {
                $count = WebAuthn::verifyLogin(
                    b64url_decode((string)($in['clientDataJSON'] ?? '')),
                    b64url_decode((string)($in['authenticatorData'] ?? '')),
                    b64url_decode((string)($in['signature'] ?? '')),
                    (string)$cred['public_key_pem'],
                    (int)$cred['sign_count']
                );
            } catch (Throwable $ex) {
                login_record($ip, 'passkey');
                throw $ex;
            }
            db()->prepare("UPDATE $tbl SET sign_count = ?, last_used_at = ? WHERE id = ?")
                ->execute([$count, now(), $cred['id']]);
            login_clear($ip);
            login_user((int)$cred['user_id']);
            json_out(['ok' => true, 'redirect' => url(safe_next())]);
        }

        case 'delete': {
            $u = require_login(true);
            db()->prepare("DELETE FROM $tbl WHERE id = ? AND user_id = ?")->execute([(int)($in['id'] ?? 0), $u['id']]);
            json_out(['ok' => true]);
        }
    }
    json_out(['ok' => false, 'error' => 'Neznáma akcia'], 400);
} catch (Throwable $ex) {
    $msg = $ex instanceof PDOException ? 'Chyba databázy' : $ex->getMessage();
    json_out(['ok' => false, 'error' => $msg], 400);
}
