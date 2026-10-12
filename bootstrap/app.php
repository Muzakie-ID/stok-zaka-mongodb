<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/auth.php'));

            // Diagnostik serverless: berjalan TANPA middleware web (tanpa session),
            // jadi tidak memicu koneksi MongoDB di lapisan Laravel. Hapus setelah debug selesai.
            Illuminate\Support\Facades\Route::get('/diag', function () {
                $out = [];
                $out[] = 'PHP: ' . PHP_VERSION;
                $out[] = 'Laravel: ' . app()->version();
                $out[] = 'OpenSSL: ' . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'tidak tersedia');
                $out[] = 'Ekstensi mongodb: ' . (extension_loaded('mongodb') ? 'ADA' : 'TIDAK ADA');
                $out[] = 'Class MongoDB\Driver\Manager: ' . (class_exists(\MongoDB\Driver\Manager::class) ? 'ADA' : 'TIDAK ADA');

                foreach (['/tmp/views', '/tmp/framework/cache/data', '/tmp/framework/sessions'] as $d) {
                    $out[] = $d . ' : ' . (is_dir($d) ? (is_writable($d) ? 'ada, writable' : 'ada, TIDAK writable') : 'TIDAK ADA');
                }

                $uri = env('MONGODB_URI');
                $out[] = 'MONGODB_URI: ' . ($uri ? 'diset (panjang=' . strlen($uri) . ')' : 'KOSONG');
                $out[] = 'MONGODB_DATABASE: ' . (env('MONGODB_DATABASE') ?: 'KOSONG');

                if ($uri && extension_loaded('mongodb')) {
                    try {
                        $sep = str_contains($uri, '?') ? '&' : '?';
                        $manager = new \MongoDB\Driver\Manager($uri . $sep . 'serverSelectionTimeoutMS=8000');
                        $manager->executeCommand(
                            env('MONGODB_DATABASE', 'admin'),
                            new \MongoDB\Driver\Command(['ping' => 1])
                        );
                        $out[] = 'KONEKSI MONGODB: OK';
                    } catch (\Throwable $e) {
                        $out[] = 'KONEKSI MONGODB GAGAL: ' . get_class($e) . ' - ' . $e->getMessage();
                    }
                }

                return response(implode("\n", $out), 200)
                    ->header('Content-Type', 'text/plain; charset=utf-8');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Wajib ada:
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
