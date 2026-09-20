<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Reminders;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReminderConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_simultaneous_workers_only_send_once(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concorrencia validada em MySQL.');
        }
        $this->seed();
        $owner = User::factory()->create(['role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
        DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $owner->id, 'display_name' => 'Teste', 'contact_phone' => '11999990000']);
        $patient = Patient::factory()->create(['whatsapp_reminders' => true, 'phone' => '+5511999999999']);
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id, 'starts_at' => now()->addDay()->addMinute(), 'ends_at' => now()->addDay()->addHour()]);
        Reminders::plan($appointment->fresh());
        $id = DB::table('reminders')->where('channel', 'whatsapp')->value('id');
        DB::table('reminders')->where('id', $id)->update(['scheduled_at' => now()->subSecond()]);
        DB::table('integration_settings')->where('id', 1)->update(['whatsapp_enabled' => true]);
        $config = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_SOCKET' => $config['unix_socket'] ?? '', 'DB_URL' => '', 'CACHE_STORE' => 'array'];
        $processes = [];
        DB::beginTransaction();
        DB::table('practice')->where('id', 1)->lockForUpdate()->first();
        try {
            for ($i = 0; $i < 2; $i++) {
                $p = new Process([PHP_BINARY, base_path('tests/Fixtures/reminder-worker.php'), (string) $id], base_path(), $env);
                $p->setTimeout(15);
                $p->start();
                $processes[] = $p;
            }
            $deadline = microtime(true) + 8;
            do {
                $ready = count(array_filter($processes, fn ($p) => str_contains($p->getOutput(), 'ready')));
                if ($ready === 2) {
                    break;
                }usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $ready);
            DB::commit();
            $sent = 0;
            foreach ($processes as $p) {
                $this->assertSame(0, $p->wait(), $p->getErrorOutput());
                $sent += substr_count($p->getOutput(), 'sent');
            }$this->assertSame(1, $sent);
            $this->assertDatabaseHas('reminders', ['id' => $id, 'status' => 'accepted']);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
    }
}
