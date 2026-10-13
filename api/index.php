<?php

/**
 * Entrypoint serverless Vercel (PHP runtime).
 *
 * Vercel menjalankan file ini untuk semua request dinamis.
 * Semua request yang tidak cocok dengan file statis di `public/`
 * akan diteruskan ke sini dan diserahkan ke front controller Laravel.
 */

declare(strict_types=1);

// Muat ekstensi mongodb saat runtime (preload FFI dependensi + dl), lihat
// api/bootstrap-mongo.php. Idempoten dan tidak pernah fatal: kegagalan hanya
// dicatat, Laravel tetap bootstrap dan menampilkan error yang jelas.
require __DIR__ . '/bootstrap-mongo.php';

// Serverless Vercel: filesystem hanya /tmp yang writable dan tidak persisten
// antar-invocation, jadi direktori cache/framework harus dibuat manual.
foreach (['/tmp/views', '/tmp/framework/cache/data', '/tmp/framework/sessions', '/tmp/framework/testing'] as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

// Vercel PHP runtime mengharapkan path relatif terhadap api/
require __DIR__.'/../public/index.php';
