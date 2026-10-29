<?php
declare(strict_types=1);

/**
 * Vzor konfigurácie – skopíruj ako config.php a doplň údaje k databáze.
 *
 * Konfigurácia aplikácie ESP32 DHT Station.
 *
 * Tento súbor leží v aplikačnom adresári, ale von nepatrí. Chráni ho pravidlo
 * v .htaccess. Aj keby ho server náhodou vydal, vráti prázdnu odpoveď, lebo
 * obsahuje len `return` – no na to sa nespoliehaj a .htaccess nechaj na mieste.
 */

return [

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'nazov_databazy',
        'user'    => 'pouzivatel',
        'pass'    => 'SEM_DAJ_HESLO_K_DATABAZE',
        'charset' => 'utf8mb4',
    ],

    // Prefix všetkých tabuliek v databáze
    'table_prefix' => 'esp32dth_',

    'name'       => 'ESP32 DHT Station',
    'short_name' => 'DHT Station',

    // URL cesta k aplikácii. null = odvodí sa automaticky z adresy skriptu,
    // takže funguje v koreni domény aj v podadresári bez zásahu.
    'base_path' => null,

    'timezone' => 'Europe/Bratislava',

    // Povoliť registráciu nových používateľov (po vytvorení svojho účtu môžeš vypnúť)
    'allow_register' => true,

    // Vlastná session – nekoliduje s inými aplikáciami na serveri
    'session' => [
        'name'         => 'ESP32DTH_SID',
        'lifetime'     => 60 * 60 * 24 * 30,  // platnosť cookie (30 dní – pohodlné pre PWA)
        'idle_timeout' => 60 * 60 * 24 * 7,   // odhlásenie po 7 dňoch nečinnosti
    ],

    // Ochrana proti hádaniu hesla
    'login' => [
        'max_attempts'   => 5,
        'window_minutes' => 15,
    ],

    // Prihlásenie odtlačkom (WebAuthn). null = zistí sa automaticky z domény.
    'webauthn_rp_id' => null,

    // Koľko dní uchovávať merania (0 = navždy)
    'retention_days' => 365,

    'debug' => false,
];
