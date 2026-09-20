<?php

use App\Support\Reminders;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql') {
    exit(2);
}
config(['integrations.evolution' => ['url' => 'https://evolution.example.test', 'token' => 'test', 'instance' => 'test']]);
Http::preventStrayRequests();
Http::fake(function () {
    echo "sent\n";

    return Http::response(['key' => ['id' => 'fake-message']], 201);
});
echo "ready\n";
Reminders::dispatch((int) $argv[1]);
