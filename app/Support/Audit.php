<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Audit
{
    public static function record(string $action, string $entity, ?int $id, ?int $actor = null): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor ?? auth()->id(), 'action' => $action, 'entity_type' => $entity, 'entity_id' => $id, 'request_id' => (string) Str::uuid(), 'created_at' => now()]);
    }
}
