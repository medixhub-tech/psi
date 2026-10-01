<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('cnpj', 14)->unique();
            $t->string('legal_name', 160);
            foreach (['trade_name', 'phone', 'postal_code', 'street', 'address_number', 'address_complement', 'district', 'city', 'state'] as $field) {
                $t->string($field, 160)->nullable();
            }
            $t->string('email', 254)->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
        });
        DB::table('permissions')->insert(['code' => 'companies.manage', 'name' => 'Cadastro de empresas', 'owner_only' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
        $id = DB::table('permissions')->where('code', 'companies.manage')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
