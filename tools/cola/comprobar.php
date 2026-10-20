<?php

// Solo verifica configuracion y conectividad; no reserva ni ejecuta trabajos.
try {
    $backend = realpath($argv[1] ?? '');
    if ($backend === false || ! is_file($backend.'/artisan')) {
        exit(2);
    }
    require $backend.'/vendor/autoload.php';
    $app = require $backend.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $url = (string) config('services.intelligence.url');
    $partes = parse_url($url);
    if (config('app.env') === 'testing'
        || config('queue.default') !== 'database'
        || config('database.default') !== 'pgsql'
        || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true)
        || config('database.connections.pgsql.database') === 'jurissim_pruebas'
        || (int) config('queue.connections.database.retry_after') <= 600
        || ($partes['scheme'] ?? null) !== 'http'
        || ! in_array($partes['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
        || isset($partes['user']) || isset($partes['pass'])
        || isset($partes['query']) || isset($partes['fragment'])) {
        exit(2);
    }
    Illuminate\Support\Facades\DB::select('select 1');
    echo json_encode(['health_url' => rtrim($url, '/').'/api/v1/health'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // No imprimir excepciones que puedan contener conexion o credenciales.
    exit(2);
}

