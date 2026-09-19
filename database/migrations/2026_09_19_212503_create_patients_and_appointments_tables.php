<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 160)->index();
            $table->date('birth_date')->nullable();
            $table->string('phone', 25)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('guardian_name', 160)->nullable();
            $table->string('guardian_phone', 25)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->enum('status', ['scheduled', 'waiting', 'in_progress', 'completed', 'no_show', 'cancelled'])->default('scheduled');
            $table->enum('modality', ['in_person', 'online'])->default('in_person');
            $table->string('location', 255)->nullable();
            $table->unsignedInteger('schedule_version')->default(1);
            $table->unsignedInteger('lock_version')->default(1);
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('called_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'arrived_at']);
            $table->index(['patient_id', 'starts_at']);
        });
        Schema::create('agenda_blocks', function (Blueprint $table) {
            $table->id();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('label', 160);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['starts_at', 'ends_at']);
        });
        Schema::create('appointment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 50);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['appointment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['appointment_history', 'agenda_blocks', 'appointments', 'patients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
