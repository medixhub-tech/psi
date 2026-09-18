<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_is_created_interactively_without_default_password(): void
    {
        $this->artisan('psi:create-owner')
            ->expectsQuestion('Nome do psicólogo', 'Profissional Teste')
            ->expectsQuestion('E-mail', 'OWNER@example.com')
            ->expectsQuestion('Telefone de contato', '11999990000')
            ->expectsQuestion('Senha (mínimo 12 caracteres, letras e números)', 'SenhaDeTeste1234')
            ->expectsQuestion('Confirme a senha', 'SenhaDeTeste1234')
            ->assertSuccessful();
        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($user->isOwner());
        $this->assertTrue(Hash::check('SenhaDeTeste1234', $user->password));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('practice', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'practice.initialized', 'actor_id' => $user->id]);
    }

    public function test_csrf_rejects_post_without_token(): void
    {
        $this->app['env'] = 'local';
        $this->post('/entrar', ['email' => 'test@example.com', 'password' => 'password'])->assertStatus(419);
    }
}
