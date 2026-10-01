<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->text('evolution_credentials')->nullable();
            $table->text('google_credentials')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', fn (Blueprint $table) => $table->dropColumn(['evolution_credentials', 'google_credentials']));
    }
};
