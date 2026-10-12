<?php

/**
 * Diagnostik infrastruktur MURNI - TANPA Laravel sama sekali.
 * Function terpisah (api/diag.php) supaya tidak terpengaruh bootstrap Laravel.
 * Hapus file ini + rewrite-nya di vercel.json setelah debug selesai.
 */

declare(strict_types=1);

header('Content-Type', 'text/plain; charset=utf-8');

$out = [];
$out[] = 'PHP: ' . PHP_VERSION;
$out[] = 'SAPI: ' . php_sapi_name();
$out[] = 'OpenSSL: ' . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : '-');
$out[] = 'Ekstensi mongodb: ' . (extension_loaded('mongodb') ? 'ADA' : 'TIDAK ADA');

$exts = get_loaded_extensions();
sort($exts);
$mongoLike = array_values(array_filter($exts, fn ($e) => stripos($e, 'mongo') !== false));
$out[] = 'Ekstensi mengandung "mongo": ' . (implode(', ', $mongoLike) ?: '(tidak ada)');

foreach (['/tmp', '/tmp/views', '/tmp/framework/cache/data'] as $d) {
    if (! is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    $out[] = $d . ' : ' . (is_dir($d) ? (is_writable($d) ? 'ada, writable' : 'ada, TIDAK writable') : 'gagal dibuat');
}

// Nama env var yang dicek TANPA menampilkan nilainya (kecuali URI hanya panjangnya)
foreach ([
    'MONGODB_URI', 'MONGODB_DATABASE', 'APP_KEY', 'APP_ENV', 'APP_DEBUG',
    'VIEW_COMPILED_PATH', 'APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE',
    'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE',
    'SESSION_DRIVER', 'CACHE_STORE', 'LOG_CHANNEL',
] as $key) {
    $val = getenv($key);
    if ($val === false || $val === '') {
        $out[] = "env {$key}: (kosong)";
    } elseif ($key === 'MONGODB_URI') {
        $out[] = "env {$key}: diset (panjang=" . strlen($val) . ', prefix=' . substr($val, 0, 10) . '...)';
    } else {
        $out[] = "env {$key}: diset";
    }
}

$uri = getenv('MONGODB_URI');
if ($uri && extension_loaded('mongodb')) {
    $t0 = microtime(true);
    try {
        $sep = str_contains($uri, '?') ? '&' : '?';
        $manager = new MongoDB\Driver\Manager($uri . $sep . 'serverSelectionTimeoutMS=8000');
        $manager->executeCommand(getenv('MONGODB_DATABASE') ?: 'admin', new MongoDB\Driver\Command(['ping' => 1]));
        $out[] = 'KONEKSI MONGODB: OK (' . round((microtime(true) - $t0) * 1000) . 'ms)';
    } catch (Throwable $e) {
        $out[] = 'KONEKSI MONGODB GAGAL: ' . get_class($e) . ' - ' . $e->getMessage();
    }
} elseif (! $uri) {
    $out[] = 'KONEKSI MONGODB: dilewati (MONGODB_URI kosong)';
} else {
    $out[] = 'KONEKSI MONGODB: dilewati (ekstensi mongodb tidak ada)';
}

echo implode("\n", $out) . "\n";
