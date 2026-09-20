<?php

use App\Models\User;
use App\Support\Billing;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql') {
    exit(2);
}
auth()->setUser(User::findOrFail((int) $argv[1]));
echo "ready\n";
try {
    Billing::receive((int) $argv[2], ['amount' => '100.00', 'method' => 'pix', 'idempotency_key' => $argv[3], 'lock_version' => 1]);
    echo "ok\n";
} catch (ValidationException $e) {
    echo "stale\n";
}
