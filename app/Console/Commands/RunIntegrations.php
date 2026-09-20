<?php

namespace App\Console\Commands;

use App\Support\GoogleCalendar;
use App\Support\Reminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RunIntegrations extends Command
{
    protected $signature = 'psi:integrations';

    protected $description = 'Processa lembretes e sincronizacao Google em lotes limitados';

    public function handle(): int
    {
        $lock = Cache::lock('psi-integrations', 120);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            DB::table('integration_settings')->where('id', 1)->update(['last_run_at' => now()]);
            $deadline = microtime(true) + 40;
            Reminders::run($deadline);
            if (microtime(true) < $deadline) {
                GoogleCalendar::run($deadline);
            }
            $this->info('Processamento concluido. Consulte os resultados no painel de integracoes.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
