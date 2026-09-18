<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateOwner extends Command
{
    protected $signature = 'psi:create-owner';

    protected $description = 'Cria o proprietário uma única vez, sem senha padrão';

    public function handle(): int
    {
        if (DB::table('practice')->exists()) {
            $this->error('O proprietário já foi configurado.');

            return self::FAILURE;
        }
        $data = ['name' => $this->ask('Nome do psicólogo'), 'email' => Str::lower(trim((string) $this->ask('E-mail'))), 'phone' => $this->ask('Telefone de contato'), 'password' => $this->secret('Senha (mínimo 12 caracteres, letras e números)')];
        $data['password_confirmation'] = $this->secret('Confirme a senha');
        $v = Validator::make($data, ['name' => 'required|string|max:160', 'email' => 'required|email|max:254|unique:users,email', 'phone' => 'required|string|max:25', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), 'max:72']]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            (new DatabaseSeeder)->run();
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
            DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $user->id, 'display_name' => $data['name'], 'contact_phone' => $data['phone'], 'timezone' => 'America/Sao_Paulo', 'currency' => 'BRL']);
            Audit::record('practice.initialized', 'user', $user->id, $user->id);
        });
        $this->info('Proprietário criado. Acesse o sistema com o e-mail e a senha informados.');

        return self::SUCCESS;
    }
}
