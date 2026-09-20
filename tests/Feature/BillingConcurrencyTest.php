<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BillingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function runConcurrentPayments(bool $sameKey): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concorrência validada em MySQL.');
        }
        $this->seed();
        $owner = User::factory()->create(['role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
        DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $owner->id, 'display_name' => 'Concorrência', 'contact_phone' => '11999990000']);
        $appointment = Appointment::factory()->create(['created_by' => $owner->id, 'updated_by' => $owner->id]);
        $charge = Charge::create(['appointment_id' => $appointment->id, 'amount' => '150.00', 'due_on' => now()->toDateString(), 'created_by' => $owner->id, 'status' => 'open', 'lock_version' => 1]);
        $config = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_SOCKET' => $config['unix_socket'] ?? '', 'DB_URL' => '', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        $key = (string) Str::uuid();
        $processes = [];
        DB::beginTransaction();
        DB::table('practice')->where('id', 1)->lockForUpdate()->first();
        try {
            foreach ([$key, $sameKey ? $key : (string) Str::uuid()] as $token) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/billing-worker.php'), (string) $owner->id, (string) $charge->id, $token], base_path(), $env);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 8;
            do {
                $ready = count(array_filter($processes, fn ($p) => str_contains($p->getOutput(), 'ready')));
                if ($ready === 2) {
                    break;
                }usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $ready, 'Os dois processos devem aguardar a trava simultaneamente.');
            DB::commit();
            $outcomes = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $outcomes[] = trim(str_replace('ready', '', $process->getOutput()));
            }
            sort($outcomes);
            $this->assertSame($sameKey ? ['ok', 'ok'] : ['ok', 'stale'], $outcomes);
            $this->assertDatabaseCount('payments', 1);
            $this->assertSame(5000, $charge->fresh()->balanceCents());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_simultaneous_distinct_receipts_cannot_overpay(): void
    {
        $this->runConcurrentPayments(false);
    }

    public function test_simultaneous_retries_record_exactly_one_receipt(): void
    {
        $this->runConcurrentPayments(true);
    }
}
