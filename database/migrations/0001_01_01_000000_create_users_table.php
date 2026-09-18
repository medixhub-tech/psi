<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('code', 60)->unique();
            $t->string('name', 100);
            $t->boolean('is_system')->default(false);
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 100)->unique();
            $t->string('name');
            $t->boolean('owner_only')->default(false);
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->foreignId('permission_id')->constrained()->restrictOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->string('name', 160);
            $t->string('email', 254)->unique();
            $t->string('password');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('session_version')->default(1);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('practice', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->foreignId('owner_user_id')->unique()->constrained('users')->restrictOnDelete();
            $t->string('display_name', 160);
            $t->string('contact_phone', 25);
            $t->string('timezone', 64)->default('America/Sao_Paulo');
            $t->char('currency', 3)->default('BRL');
        });
        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 100);
            $t->string('entity_type', 80);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->uuid('request_id');
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity_type', 'entity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'sessions', 'password_reset_tokens', 'practice', 'users', 'role_permissions', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
