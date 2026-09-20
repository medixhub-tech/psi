<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->unique(['id', 'patient_id'], 'appointments_patient_identity');
        });
        Schema::create('clinical_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('appointment_id')->nullable();
            $t->foreign(['appointment_id', 'patient_id'])->references(['id', 'patient_id'])->on('appointments')->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('current_version')->default(1);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['patient_id', 'created_at']);
        });
        Schema::create('clinical_entry_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->constrained('clinical_entries')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->longText('content_ciphertext');
            $t->text('reason_ciphertext')->nullable();
            $t->string('encryption_key_id', 64);
            $t->enum('status', ['draft', 'final']);
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->dateTime('finalized_at')->nullable();
            $t->unique(['entry_id', 'version']);
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('appointment_id')->nullable();
            $t->foreign(['appointment_id', 'patient_id'])->references(['id', 'patient_id'])->on('appointments')->restrictOnDelete();
            $t->string('category', 40);
            $t->string('storage_key')->unique();
            $t->text('original_name_ciphertext');
            $t->string('encryption_key_id', 64);
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->string('sha256', 64);
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->dateTime('archived_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['documents', 'clinical_entry_versions', 'clinical_entries'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('appointments', function (Blueprint $t) {
            $t->dropUnique('appointments_patient_identity');
        });
    }
};
