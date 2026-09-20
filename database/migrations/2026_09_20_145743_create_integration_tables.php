<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $t) {
            $t->boolean('whatsapp_reminders')->default(false);
            $t->boolean('email_reminders')->default(false);
            $t->string('communication_source', 160)->nullable();
        });
        Schema::create('integration_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->boolean('whatsapp_enabled')->default(false);
            $t->boolean('email_enabled')->default(false);
            $t->string('whatsapp_provider', 20)->default('evolution');
            $t->unsignedInteger('lock_version')->default(1);
            $t->dateTime('last_run_at')->nullable();
        });
        DB::table('integration_settings')->insert(['id' => 1]);
        Schema::create('reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('schedule_version');
            $t->string('channel', 20);
            $t->dateTime('scheduled_at');
            $t->dateTime('expires_at');
            $t->string('status', 20);
            $t->string('provider', 30)->nullable();
            $t->string('provider_message_id')->nullable()->index();
            $t->uuid('lease_token')->nullable();
            $t->dateTime('lease_until')->nullable();
            $t->dateTime('accepted_at')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->unique(['appointment_id', 'schedule_version', 'channel']);
            $t->index(['status', 'scheduled_at']);
        });
        Schema::create('google_connections', function (Blueprint $t) {
            $t->id();
            $t->uuid('namespace')->unique();
            $t->string('calendar_id');
            $t->longText('refresh_token_ciphertext');
            $t->boolean('active')->default(true);
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('calendar_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('google_connections')->restrictOnDelete();
            $t->foreignId('appointment_id')->constrained()->restrictOnDelete();
            $t->string('event_id', 64);
            $t->unsignedInteger('target_version');
            $t->unsignedInteger('synced_version')->nullable();
            $t->string('status', 20)->default('pending');
            $t->string('error_code', 80)->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('available_at');
            $t->unique(['connection_id', 'appointment_id']);
        });
    }

    public function down(): void
    {
        foreach (['calendar_events', 'google_connections', 'reminders', 'integration_settings'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('patients', function (Blueprint $t) {
            $t->dropColumn(['whatsapp_reminders', 'email_reminders', 'communication_source']);
        });
    }
};
