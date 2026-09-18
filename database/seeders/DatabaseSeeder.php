<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permissions::DELEGABLE + Permissions::OWNER as $code => $name) {
            Permission::updateOrCreate(['code' => $code], ['name' => $name, 'owner_only' => isset(Permissions::OWNER[$code])]);
        }
        $owner = Role::firstOrCreate(['code' => 'psychologist'], ['name' => 'Psicólogo']);
        $secretary = Role::firstOrCreate(['code' => 'secretary'], ['name' => 'Secretária']);
        $owner->forceFill(['is_system' => true])->save();
        $secretary->forceFill(['is_system' => true])->save();
        if ($secretary->wasRecentlyCreated) {
            $secretary->permissions()->sync(Permission::where('owner_only', false)->pluck('id'));
        }
    }
}
