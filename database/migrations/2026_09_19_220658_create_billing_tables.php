<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->decimal('amount', 12, 2);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
        });
        Schema::table('appointments', function (Blueprint $t) {
            $t->foreignId('service_type_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('service_name', 120)->nullable();
        });
        Schema::create('charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('amount', 12, 2)->nullable();
            $t->date('due_on');
            $t->enum('status', ['open', 'waived', 'void'])->default('open');
            $t->unsignedInteger('lock_version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'due_on']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('charge_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('method', 30);
            $t->dateTime('received_at')->index();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->uuid('idempotency_key')->unique();
            $t->unique(['id', 'amount']);
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('payment_reversals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->foreign(['payment_id', 'amount'])->references(['id', 'amount'])->on('payments')->restrictOnDelete();
            $t->string('reason', 255);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->dateTime('created_at')->index();
        });
        Schema::create('charge_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('charge_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->json('before_snapshot')->nullable();
            $t->json('after_snapshot');
            $t->string('reason', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE service_types ADD CONSTRAINT service_amount_nonnegative CHECK (amount >= 0)');
            DB::statement('ALTER TABLE charges ADD CONSTRAINT charge_amount_nonnegative CHECK (amount IS NULL OR amount >= 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payment_amount_positive CHECK (amount > 0)');
        }
        $tz = DB::table('practice')->where('id', 1)->value('timezone') ?? 'America/Sao_Paulo';
        DB::table('appointments')->orderBy('id')->chunkById(200, function ($appointments) use ($tz) {
            foreach ($appointments as $a) {
                DB::table('charges')->insert(['appointment_id' => $a->id, 'amount' => null, 'due_on' => CarbonImmutable::parse($a->starts_at, 'UTC')->setTimezone($tz)->toDateString(), 'created_by' => $a->created_by, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        foreach (['charge_history', 'payment_reversals', 'payments', 'charges'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('appointments', function (Blueprint $t) {
            $t->dropForeign(['service_type_id']);
            $t->dropColumn(['service_type_id', 'service_name']);
        });
        Schema::dropIfExists('service_types');
    }
};
