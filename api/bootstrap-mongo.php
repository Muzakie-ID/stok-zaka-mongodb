<?php

/**
 * Runtime loader untuk ekstensi mongodb di Vercel (vercel-php).
 *
 * Latar belakang:
 * - Runtime vercel-php tidak membundel ekstensi mongodb.
 * - mongodb.so buatan Remi yang kita kirim punya 4 dependensi native
 *   (libsasl2.so.3, libsnappy.so.1, libutf8proc.so.2, libzstd.so.1) yang
 *   tidak ada di image runtime.
 * - LD_LIBRARY_PATH adalah env var reserved AWS Lambda: nilai dari env var
 *   project Vercel TIDAK pernah sampai ke runtime (diverifikasi via /diag).
 *   Tanpa jalur pencarian library, dlopen("mongodb.so") gagal
 *   "libutf8proc.so.2: cannot open shared object file".
 *
 * Solusi: pre-load 4 dependensi sebagai RTLD_GLOBAL via FFI dlopen, lalu muat
 * mongodb.so via dl(). Dynamic linker me-resolve dependensi berdasarkan soname
 * yang SUDAH terdaftar di namespace global, sehingga tidak mencari file lagi
 * di LD_LIBRARY_PATH.
 *
 * Fungsi ini idempoten: aman dipanggil berkali-kali per proses.
 * Jika semua gagal, cukup return false - Laravel tidak boleh 500 karena ini.
 *
 * Dipakai dari api/index.php dan api/diag.php.
 * Detail langkah tersimpan di $GLOBALS['zaka_mongo'] untuk diagnostik.
 */

declare(strict_types=1);

$GLOBALS['zaka_mongo'] = ['hasil' => null, 'langkah' => []];

// Idempoten: jika sudah dimuat (mis. lewat php.ini + rpath), selesai.
if (extension_loaded('mongodb')) {
    $GLOBALS['zaka_mongo']['hasil'] = 'sudah termuat (php.ini)';
    return true;
}

/**
 * Pre-load satu .so dengan RTLD_GLOBAL via FFI.
 * Return error message dlopen atau null saat sukses.
 */
function zaka_dl_open_global(string $path): ?string
{
    if (! extension_loaded('FFI')) {
        return 'ekstensi FFI tidak tersedia';
    }

    try {
        $ffi = FFI::cdef(
            'void* dlopen(const char *filename, int flags);',
            null
        );
        // RTLD_NOW (2) | RTLD_GLOBAL (0x100) = 258; NULL libc via handle null
        $handle = $ffi->dlopen($path, 258);

        return $handle === null
            ? 'dlopen mengembalikan NULL'
            : null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

$extDir = __DIR__ . '/ext';

// Urutan penting: libutf8proc sebelum libsnappy (dependensi transitif),
// sisanya saling independen.
$libs = [
    'libutf8proc.so.2',
    'libsnappy.so.1',
    'libsasl2.so.3',
    'libzstd.so.1',
];

// 1) Pre-load dependensi dengan RTLD_GLOBAL
$errors = [];
foreach ($libs as $lib) {
    $path = $extDir . '/' . $lib;
    if (! is_file($path)) {
        $errors[] = "{$lib}: file tidak ada";
        $GLOBALS['zaka_mongo']['langkah'][] = "preload {$lib}: GAGAL (file tidak ada)";
        continue;
    }
    $err = zaka_dl_open_global($path);
    if ($err !== null) {
        $errors[] = "{$lib}: {$err}";
    }
    $GLOBALS['zaka_mongo']['langkah'][] = 'preload ' . $lib . ': ' . ($err === null ? 'OK' : "GAGAL ({$err})");
}

// 2) Muat mongodb.so via dl()
if (! function_exists('dl')) {
    $GLOBALS['zaka_mongo']['hasil'] = 'dl() tidak tersedia';
    return false;
}

$ok = @dl($extDir . '/mongodb.so');
$GLOBALS['zaka_mongo']['langkah'][] = 'dl(mongodb.so): ' . ($ok ? 'OK' : 'GAGAL');
$GLOBALS['zaka_mongo']['hasil'] = $ok ? 'OK (dl runtime)' : 'GAGAL';

if (! $ok && $errors !== []) {
    error_log('zaka: dl() mongodb.so gagal. Preload: ' . implode('; ', $errors));
}

return $ok;
