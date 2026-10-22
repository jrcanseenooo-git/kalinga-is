<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

// Isolated browser-test router. Run only through foundation-server.mjs.
$public = realpath(__DIR__ . '/../../public');
$requested = realpath($public . '/' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($requested && str_starts_with($requested, $public . DIRECTORY_SEPARATOR) && is_file($requested)) {
    return false;
}

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless($app->environment('testing') && config('database.default') === 'sqlite', 503);
// Test built assets without changing or deleting the developer's public/hot file.
Vite::useHotFile(sys_get_temp_dir() . '/kalinga-foundation-no-hot');
$app->handleRequest(Request::capture());
