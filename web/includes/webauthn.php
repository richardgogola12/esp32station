<?php
declare(strict_types=1);

/**
 * Minimálna, bezzávislostná implementácia WebAuthn (passkeys / odtlačok prsta).
 * Podporuje ES256 (-7) a RS256 (-257), attestation "none".
 */

defined('APP_ROOT') || exit;

final class Cbor
{
    private string $d;
    private int $p = 0;

    private function __construct(string $d)
    {
        $this->d = $d;
    }

    public static function decode(string $data)
    {
        $c = new self($data);
        return $c->item();
    }

    /** Dekóduje prvú CBOR položku a vráti aj počet spotrebovaných bajtov. */
    public static function decodePrefix(string $data, ?int &$consumed)
    {
        $c = new self($data);
        $v = $c->item();
        $consumed = $c->p;
        return $v;
    }

    private function byte(): int
    {
        if ($this->p >= strlen($this->d)) {
            throw new RuntimeException('CBOR: neočakávaný koniec dát');
        }
        return ord($this->d[$this->p++]);
    }

    private function take(int $n): string
    {
        if ($n < 0 || $this->p + $n > strlen($this->d)) {
            throw new RuntimeException('CBOR: neplatná dĺžka');
        }
        $s = substr($this->d, $this->p, $n);
        $this->p += $n;
        return $s;
    }

    private function length(int $ai): int
    {
        if ($ai < 24) return $ai;
        if ($ai === 24) return $this->byte();
        if ($ai === 25) return unpack('n', $this->take(2))[1];
        if ($ai === 26) return unpack('N', $this->take(4))[1];
        if ($ai === 27) return unpack('J', $this->take(8))[1];
        throw new RuntimeException('CBOR: nepodporovaná dĺžka');
    }

    private function item(int $depth = 0)
    {
        if ($depth > 16) {
            throw new RuntimeException('CBOR: príliš hlboké vnorenie');
        }
        $ib = $this->byte();
        $mt = $ib >> 5;
        $ai = $ib & 0x1f;

        if ($mt === 7) {
            switch ($ai) {
                case 20: return false;
                case 21: return true;
                case 22:
                case 23: return null;
                case 25: $this->take(2); return null;
                case 26: return unpack('G', $this->take(4))[1];
                case 27: return unpack('E', $this->take(8))[1];
                default: return null;
            }
        }

        $len = $this->length($ai);
        switch ($mt) {
            case 0: return $len;
            case 1: return -1 - $len;
            case 2:
            case 3: return $this->take($len);
            case 4:
                $a = [];
                for ($i = 0; $i < $len; $i++) $a[] = $this->item($depth + 1);
                return $a;
            case 5:
                $m = [];
                for ($i = 0; $i < $len; $i++) {
                    $k = $this->item($depth + 1);
                    $m[is_int($k) ? $k : (string)$k] = $this->item($depth + 1);
                }
                return $m;
            case 6:
                return $this->item($depth + 1);
        }
        throw new RuntimeException('CBOR: neznámy typ');
    }
}

final class WebAuthn
{
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    public static function rpId(): string
    {
        $c = cfg('webauthn_rp_id');
        if (is_string($c) && $c !== '') {
            return $c;
        }
        return preg_replace('/:\d+$/', '', host()) ?? host();
    }

    public static function userHandle(int $uid): string
    {
        return substr(hash('sha256', 'esp32dth-user:' . $uid, true), 0, 16);
    }

    public static function newChallenge(string $purpose): string
    {
        $c = random_bytes(32);
        sess_set('wa_' . $purpose, ['c' => b64url_encode($c), 't' => time()]);
        return $c;
    }

    private static function takeChallenge(string $purpose): string
    {
        $c = sess('wa_' . $purpose);
        sess_unset('wa_' . $purpose);
        if (!is_array($c) || time() - (int)$c['t'] > 300) {
            throw new RuntimeException('Výzva vypršala, skús to znova.');
        }
        return b64url_decode((string)$c['c']);
    }

    private static function checkClientData(string $clientDataJSON, string $type, string $purpose): void
    {
        $cd = json_decode($clientDataJSON, true);
        if (!is_array($cd)) {
            throw new RuntimeException('Neplatné clientDataJSON');
        }
        if (($cd['type'] ?? '') !== $type) {
            throw new RuntimeException('Neplatný typ operácie');
        }
        $expected = self::takeChallenge($purpose);
        if (!hash_equals($expected, b64url_decode((string)($cd['challenge'] ?? '')))) {
            throw new RuntimeException('Výzva nesúhlasí');
        }
        if (($cd['origin'] ?? '') !== origin()) {
            throw new RuntimeException('Pôvod (origin) nesúhlasí');
        }
    }

    private static function parseAuthData(string $ad): array
    {
        if (strlen($ad) < 37) {
            throw new RuntimeException('Neplatné authenticatorData');
        }
        $r = [
            'rpIdHash'  => substr($ad, 0, 32),
            'flags'     => ord($ad[32]),
            'signCount' => unpack('N', substr($ad, 33, 4))[1],
        ];
        if (!hash_equals(hash('sha256', self::rpId(), true), $r['rpIdHash'])) {
            throw new RuntimeException('RP ID nesúhlasí s doménou');
        }
        if (!($r['flags'] & 0x01)) {
            throw new RuntimeException('Chýba potvrdenie používateľa');
        }
        if (!($r['flags'] & 0x04)) {
            throw new RuntimeException('Overenie (odtlačok/PIN) nebolo vykonané');
        }
        if ($r['flags'] & 0x40) {
            $off = 37 + 16;
            $len = unpack('n', substr($ad, $off, 2))[1];
            $off += 2;
            $r['credentialId'] = substr($ad, $off, $len);
            $off += $len;
            $consumed = 0;
            $r['cose'] = Cbor::decodePrefix(substr($ad, $off), $consumed);
        }
        return $r;
    }

    /* ---------------- ASN.1 / PEM ---------------- */

    private static function derLen(int $n): string
    {
        if ($n < 0x80) return chr($n);
        $s = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($s)) . $s;
    }

    private static function der(int $tag, string $content): string
    {
        return chr($tag) . self::derLen(strlen($content)) . $content;
    }

    private static function derInt(string $bin): string
    {
        $bin = ltrim($bin, "\0");
        if ($bin === '' || (ord($bin[0]) & 0x80)) {
            $bin = "\0" . $bin;
        }
        return self::der(0x02, $bin);
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function coseToPem(array $k): string
    {
        $kty = $k[1] ?? null;
        $alg = $k[3] ?? null;
        if ($kty === 2 && $alg === self::ALG_ES256 && ($k[-1] ?? null) === 1) {
            $x = (string)$k[-2];
            $y = (string)$k[-3];
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new RuntimeException('Neplatný EC kľúč');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            return self::pem($der);
        }
        if ($kty === 3 && $alg === self::ALG_RS256) {
            $rsa = self::der(0x30, self::derInt((string)$k[-1]) . self::derInt((string)$k[-2]));
            $algId = self::der(0x30, self::der(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00");
            $der = self::der(0x30, $algId . self::der(0x03, "\0" . $rsa));
            return self::pem($der);
        }
        throw new RuntimeException('Nepodporovaný typ kľúča');
    }

    /* ---------------- Registrácia ---------------- */

    public static function registrationOptions(array $user, array $existingIds): array
    {
        $challenge = self::newChallenge('reg');
        return [
            'challenge' => b64url_encode($challenge),
            'rp'        => ['name' => (string)cfg('name'), 'id' => self::rpId()],
            'user'      => [
                'id'          => b64url_encode(self::userHandle((int)$user['id'])),
                'name'        => $user['username'],
                'displayName' => $user['username'],
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => self::ALG_ES256],
                ['type' => 'public-key', 'alg' => self::ALG_RS256],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey'             => 'preferred',
                'requireResidentKey'      => false,
                'userVerification'        => 'required',
            ],
            'excludeCredentials' => array_map(fn($id) => ['type' => 'public-key', 'id' => $id], $existingIds),
        ];
    }

    /** @return array{credentialId:string, pem:string, signCount:int} */
    public static function verifyRegistration(string $clientDataJSON, string $attestationObject): array
    {
        self::checkClientData($clientDataJSON, 'webauthn.create', 'reg');
        $att = Cbor::decode($attestationObject);
        if (!is_array($att) || !isset($att['authData'])) {
            throw new RuntimeException('Neplatný attestationObject');
        }
        $ad = self::parseAuthData((string)$att['authData']);
        if (empty($ad['credentialId']) || !is_array($ad['cose'] ?? null)) {
            throw new RuntimeException('Chýbajú údaje o kľúči');
        }
        return [
            'credentialId' => $ad['credentialId'],
            'pem'          => self::coseToPem($ad['cose']),
            'signCount'    => (int)$ad['signCount'],
        ];
    }

    /* ---------------- Prihlásenie ---------------- */

    public static function loginOptions(array $allowIds = []): array
    {
        $challenge = self::newChallenge('login');
        return [
            'challenge'        => b64url_encode($challenge),
            'rpId'             => self::rpId(),
            'timeout'          => 60000,
            'userVerification' => 'required',
            'allowCredentials' => array_map(fn($id) => ['type' => 'public-key', 'id' => $id], $allowIds),
        ];
    }

    /** Overí podpis. Vracia nové signCount. */
    public static function verifyLogin(string $clientDataJSON, string $authenticatorData, string $signature, string $pem, int $storedCount): int
    {
        self::checkClientData($clientDataJSON, 'webauthn.get', 'login');
        $ad = self::parseAuthData($authenticatorData);
        $data = $authenticatorData . hash('sha256', $clientDataJSON, true);
        $key = openssl_pkey_get_public($pem);
        if ($key === false || openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Podpis je neplatný');
        }
        $count = (int)$ad['signCount'];
        if ($storedCount > 0 && $count > 0 && $count <= $storedCount) {
            throw new RuntimeException('Podozrenie na klonovaný kľúč');
        }
        return $count;
    }
}
