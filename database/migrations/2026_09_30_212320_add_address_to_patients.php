<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            foreach (['postal_code', 'street', 'address_number', 'address_complement', 'district', 'city', 'state'] as $field) {
                $table->string($field, 160)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients', fn (Blueprint $table) => $table->dropColumn(['postal_code', 'street', 'address_number', 'address_complement', 'district', 'city', 'state']));
    }
};
