<?php

/**
 * Diagnostik infrastruktur MURNI - TANPA Laravel sama sekali.
 * Function terpisah (api/diag.php) supaya tidak terpengaruh bootstrap Laravel.
 * Hapus file ini + rewrite-nya di vercel.json setelah debug selesai.
 */

declare(strict_types=1);

// CATATAN: signature header() adalah (string $header, bool $replace, ...).
// Di bawah strict_types, mengirim string di argumen kedua melempar TypeError
// fatal sebelum output apa pun (inilah penyebab /diag 500 body kosong).
header('Content-Type: text/plain; charset=utf-8');

$out = [];
$out[] = 'PHP: ' . PHP_VERSION;
$out[] = 'SAPI: ' . php_sapi_name();
$out[] = 'OpenSSL: ' . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : '-');
$out[] = 'Ekstensi mongodb: ' . (extension_loaded('mongodb') ? 'ADA' : 'TIDAK ADA');
$out[] = 'Versi ekstensi mongodb: ' . (extension_loaded('mongodb') ? (phpversion('mongodb') ?: '?') : '-');
$out[] = 'Class MongoDB\Driver\Manager: ' . (class_exists('MongoDB\Driver\Manager') ? 'ADA' : 'TIDAK ADA');
$out[] = 'LD_LIBRARY_PATH: ' . (getenv('LD_LIBRARY_PATH') ?: '(kosong)');
$out[] = 'extension_dir: ' . (ini_get('extension_dir') ?: '-');
$out[] = 'enable_dl: ' . (ini_get('enable_dl') ?: '-');
$out[] = 'ffi.enable: ' . (ini_get('ffi.enable') ?: '-');
$out[] = 'fungsi dl(): ' . (function_exists('dl') ? 'ADA' : 'TIDAK ADA');

// --- Loader runtime (FFI preload + dl) ---
require __DIR__ . '/bootstrap-mongo.php';

$out[] = '--- Loader bootstrap-mongo ---';
foreach ($GLOBALS['zaka_mongo']['langkah'] as $langkah) {
    $out[] = '  ' . $langkah;
}
if ($GLOBALS['zaka_mongo']['langkah'] === []) {
    $out[] = '  (tidak perlu - sudah termuat via php.ini)';
}
$out[] = 'Hasil loader: ' . ($GLOBALS['zaka_mongo']['hasil'] ?? '-');
$out[] = 'Ekstensi mongodb setelah loader: ' . (extension_loaded('mongodb') ? 'ADA' : 'TIDAK ADA');

// Deteksi DT_RUNPATH $ORIGIN pada mongodb.so (hasil patchelf dari buildCommand)
$soPath = __DIR__ . '/ext/mongodb.so';
if (is_file($soPath)) {
    $head = (string) file_get_contents($soPath, false, null, 0, 4096);
    $out[] = 'mongodb.so DT_RUNPATH $ORIGIN: ' . (str_contains($head, '$ORIGIN') ? 'ADA' : 'TIDAK (patchelf belum jalan)');
} else {
    $out[] = 'mongodb.so: FILE TIDAK ADA di ' . $soPath;
}
$out[] = 'Class MongoDB\Driver\Manager setelah loader: ' . (class_exists('MongoDB\Driver\Manager') ? 'ADA' : 'TIDAK ADA');

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

$ocf = getenv('OPENSSL_CONF') ?: '';
$out[] = 'OPENSSL_CONF: ' . ($ocf ?: '(kosong)');
if ($ocf && is_file($ocf)) {
    $isi = (string) file_get_contents($ocf);
    // Hapus baris kosong/komentar agar ringkas
    $isi = implode("\n", array_filter(
        explode("\n", $isi),
        fn ($l) => trim($l) !== '' && ! str_starts_with(trim($l), '#')
    ));
    $out[] = '--- isi OPENSSL_CONF (tanpa komentar) ---';
    foreach (explode("\n", $isi) as $l) {
        $out[] = '  ' . trim($l);
    }
} elseif ($ocf) {
    $out[] = 'OPENSSL_CONF: file tidak ditemukan';
}

// --- Probe TLS mentah ke Atlas (tanpa libmongoc) ---
$host = 'ac-grafhpw-shard-00-00.zqamugo.mongodb.net:27017';
$probe = function (array $sslOpts, string $label) use ($out, $host) {
    $ctx = stream_context_create(['ssl' => $sslOpts]);
    $errNo = 0; $errStr = '';
    $t0 = microtime(true);
    $fp = @stream_socket_client(
        'ssl://' . $host,
        $errNo,
        $errStr,
        10,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if ($fp) {
        $out[] = "PROBE {$label}: OK (" . round((microtime(true) - $t0) * 1000) . 'ms, peer=' . stream_socket_get_name($fp, true) . ')';
        fclose($fp);
    } else {
        $out[] = "PROBE {$label}: GAGAL ({$errNo}) {$errStr}";
    }
};
$probe([], 'default');
$probe(['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT], 'TLS1.2');

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
    $out[] = 'KONEKSI MONGODB: dilewati (ekstensi mongodb tidak ada setelah loader)';
}

echo implode("\n", $out) . "\n";
