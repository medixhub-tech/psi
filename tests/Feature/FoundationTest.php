<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $secretary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create(['role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
        DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $this->owner->id, 'display_name' => 'Consultório de teste', 'contact_phone' => '11999990000']);
        $this->secretary = User::factory()->create()->refresh();
        $this->owner->refresh();
    }

    private function signIn(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth_version' => $user->session_version]);
    }

    public function test_guest_cannot_access_private_pages(): void
    {
        foreach (['/painel', '/usuarios', '/perfis'] as $path) {
            $this->get($path)->assertRedirect('/entrar');
        }
        $this->get('/entrar')->assertOk()->assertSee('Bem-vindo')->assertHeader('X-Frame-Options', 'DENY');
        $this->get('/register')->assertNotFound();
    }

    public function test_login_and_logout(): void
    {
        $this->post('/entrar', ['email' => strtoupper($this->secretary->email), 'password' => 'password'])->assertRedirect('/painel');
        $this->assertAuthenticatedAs($this->secretary);
        $this->get('/painel')->assertOk()->assertDontSee('Gerenciar usuários');
        $this->post('/sair')->assertRedirect('/entrar');
        $this->assertGuest();
    }

    public function test_inactive_account_and_wrong_password_cannot_login(): void
    {
        $this->secretary->update(['active' => false]);
        $this->post('/entrar', ['email' => $this->secretary->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/entrar', ['email' => $this->owner->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/entrar', ['email' => $this->owner->email, 'password' => 'wrong']);
        }
        $this->post('/entrar', ['email' => $this->owner->email, 'password' => 'password'])->assertSessionHasErrors(['email' => 'Muitas tentativas. Aguarde um minuto e tente novamente.']);
        $this->assertGuest();
    }

    public function test_secretary_is_denied_admin_even_with_injected_permission(): void
    {
        $this->secretary->role->permissions()->attach(Permission::where('code', 'users.manage')->firstOrFail());
        $this->signIn($this->secretary);
        foreach (['/usuarios', '/usuarios/create', '/usuarios/'.$this->owner->id.'/edit', '/perfis', '/perfis/create'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/usuarios', [])->assertForbidden();
        $this->put('/perfis/'.$this->secretary->role_id, [])->assertForbidden();
        foreach (['finance.manage', 'clinical.manage', 'documents.manage', 'users.manage'] as $permission) {
            $this->assertFalse(Gate::forUser($this->secretary)->allows($permission));
        }
        $this->assertTrue(Gate::forUser($this->secretary)->allows('billing.today'));
    }

    public function test_owner_screens_render(): void
    {
        $this->signIn($this->owner);
        foreach (['/painel', '/usuarios', '/usuarios/create', '/usuarios/'.$this->secretary->id.'/edit', '/perfis', '/perfis/create', '/perfis/'.$this->secretary->role_id.'/edit'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_owner_creates_auxiliary_user_with_hashed_password(): void
    {
        $this->signIn($this->owner)->post('/usuarios', [
            'name' => 'Recepção', 'email' => 'RECEPCAO@example.com', 'role_id' => $this->secretary->role_id,
            'active' => '1', 'password' => 'SenhaTeste12345', 'password_confirmation' => 'SenhaTeste12345',
        ])->assertRedirect('/usuarios');
        $created = User::where('email', 'recepcao@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('SenhaTeste12345', $created->password));
        $this->assertDatabaseHas('audit_events', ['action' => 'users.created', 'actor_id' => $this->owner->id, 'entity_id' => $created->id]);
    }

    public function test_owner_cannot_be_disabled_or_have_role_changed_via_user_form(): void
    {
        $this->signIn($this->owner)->put('/usuarios/'.$this->owner->id, ['active' => 0])->assertForbidden();
        $this->assertTrue($this->owner->fresh()->active);
    }

    public function test_owner_role_cannot_be_assigned_to_auxiliary_account(): void
    {
        $this->signIn($this->owner)->post('/usuarios', [
            'name' => 'Outro', 'email' => 'outro@example.com', 'role_id' => $this->owner->role_id,
            'active' => 1, 'password' => 'SenhaTeste12345', 'password_confirmation' => 'SenhaTeste12345',
        ])->assertSessionHasErrors('role_id');
        $this->assertDatabaseMissing('users', ['email' => 'outro@example.com']);
    }

    public function test_editing_account_invalidates_old_session(): void
    {
        $version = $this->secretary->session_version;
        $this->signIn($this->owner)->put('/usuarios/'.$this->secretary->id, [
            'name' => $this->secretary->name, 'email' => $this->secretary->email,
            'role_id' => $this->secretary->role_id, 'active' => 1,
        ])->assertRedirect('/usuarios');
        $this->assertSame($version + 1, $this->secretary->fresh()->session_version);
        $this->actingAs($this->secretary->fresh())->withSession(['auth_version' => $version])->get('/painel')->assertRedirect('/entrar');
        $this->assertGuest();
    }

    public function test_disabled_user_cannot_keep_using_existing_session(): void
    {
        $this->secretary->update(['active' => false]);
        $this->signIn($this->secretary)->get('/painel')->assertRedirect('/entrar');
    }

    public function test_custom_role_and_permission_changes_revoke_sessions(): void
    {
        $permission = Permission::where('code', 'attendance.record')->firstOrFail();
        $this->signIn($this->owner)->post('/perfis', ['name' => 'Recepção', 'permissions' => [$permission->id]])->assertRedirect('/perfis');
        $role = Role::where('name', 'Recepção')->firstOrFail();
        $this->assertSame([$permission->id], $role->permissions->pluck('id')->all());
        $this->put('/perfis/'.$this->secretary->role_id, ['name' => 'Secretária', 'permissions' => []])->assertRedirect('/perfis');
        $this->assertSame(2, $this->secretary->fresh()->session_version);
        $this->assertFalse($this->secretary->fresh()->hasPermission('billing.today'));
    }

    public function test_cannot_delegate_owner_permission_or_edit_owner_role(): void
    {
        $permission = Permission::where('code', 'finance.manage')->firstOrFail();
        $this->signIn($this->owner)->post('/perfis', ['name' => 'Restrito', 'permissions' => [$permission->id]])->assertSessionHasErrors('permissions.0');
        $this->put('/perfis/'.$this->owner->role_id, ['name' => 'Alterado'])->assertForbidden();
    }

    public function test_reset_request_does_not_reveal_account_existence(): void
    {
        Notification::fake();
        $known = $this->post('/recuperar-senha', ['email' => $this->owner->email]);
        $message = session('status');
        $known->assertSessionHas('status');
        Notification::assertSentTo($this->owner, ResetPassword::class);
        $this->post('/recuperar-senha', ['email' => 'missing@example.com'])->assertSessionHas('status', $message);
        $this->secretary->update(['active' => false]);
        $this->post('/recuperar-senha', ['email' => $this->secretary->email])->assertSessionHas('status', $message);
        Notification::assertNotSentTo($this->secretary, ResetPassword::class);
    }

    public function test_password_reset_is_single_use_and_revokes_sessions(): void
    {
        $token = Password::createToken($this->owner);
        $payload = ['email' => $this->owner->email, 'token' => $token, 'password' => 'UmaNovaSenha123', 'password_confirmation' => 'UmaNovaSenha123'];
        $stored = DB::table('password_reset_tokens')->where('email', $this->owner->email)->value('token');
        $this->assertNotSame($token, $stored);
        $this->post('/redefinir-senha', $payload)->assertRedirect('/entrar');
        $this->assertTrue(Hash::check($payload['password'], $this->owner->fresh()->password));
        $this->assertSame(2, $this->owner->fresh()->session_version);
        $this->post('/redefinir-senha', $payload)->assertSessionHasErrors('email');
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $token = Password::createToken($this->owner);
        $this->travel(61)->minutes();
        $this->post('/redefinir-senha', ['email' => $this->owner->email, 'token' => $token, 'password' => 'UmaNovaSenha123', 'password_confirmation' => 'UmaNovaSenha123'])->assertSessionHasErrors('email');
    }

    public function test_owner_setup_refuses_second_owner(): void
    {
        $this->artisan('psi:create-owner')->assertExitCode(1);
        $this->assertDatabaseCount('practice', 1);
    }
}
