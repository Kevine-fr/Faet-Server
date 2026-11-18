<?php

use App\Services\MetricsService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Metriques Prometheus
|--------------------------------------------------------------------------
| Lue par le Prometheus du VPS via le reseau Docker interne (faet-api:8000).
| nginx renvoie 404 sur /metrics cote public : l'endpoint n'est pas expose.
*/
Route::get('/metrics', function () {
    return response(
        MetricsService::export(),
        200,
        ['Content-Type' => 'text/plain; version=0.0.4']
    );
});

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sitemap.xml', function () {
    $file = storage_path('app/public/sitemap.xml');

    if (!file_exists($file)) {
        abort(404);
    }

    return response()->file($file, [
        'Content-Type' => 'application/xml',
    ]);
});
