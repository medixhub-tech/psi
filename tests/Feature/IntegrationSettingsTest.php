<?php

namespace Tests\Feature;

use App\ExternalApi\ReminderGateway;
use App\Models\Role;
use App\Models\User;
use App\Support\IntegrationCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): void
    {
        $this->seed();
        Http::preventStrayRequests();
        $u = User::factory()->create(['role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
        DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $u->id, 'display_name' => 'Teste', 'contact_phone' => '000000']);
        $this->actingAs($u)->withSession(['auth_version' => $u->session_version]);
    }

    public function test_saved_evolution_secret_is_encrypted_and_used_without_exposure(): void
    {
        $this->owner();
        $data = ['url' => 'https://evolution.example.test', 'instance' => 'consultorio', 'token' => 'secret-evolution-test', 'lock_version' => 1];
        $this->put('/integracoes/credenciais/evolution', $data)->assertSessionHasNoErrors();
        $stored = DB::table('integration_settings')->value('evolution_credentials');
        $this->assertStringNotContainsString('secret-evolution-test', $stored);
        $this->get('/integracoes')->assertOk()->assertDontSee('secret-evolution-test')->assertSee('Salvar Evolution API');
        Http::fake(['evolution.example.test/*' => Http::response(['key' => ['id' => 'sent-test']])]);
        $this->assertSame('accepted', ReminderGateway::send('evolution', '5511000000000', ['Teste', 'Profissional', '01/10/2026', '10:00', '000000'])['status']);
        Http::assertSent(fn ($r) => $r->hasHeader('apikey', 'secret-evolution-test'));
        $data['token'] = '';
        $data['lock_version'] = 2;
        $this->put('/integracoes/credenciais/evolution', $data)->assertSessionHasNoErrors();
        $this->assertSame('secret-evolution-test', IntegrationCredentials::get('evolution')['token']);
        $this->put('/integracoes/credenciais/evolution', $data)->assertSessionHasErrors('integration');
    }

    public function test_google_credentials_drive_oauth_and_configuration_change_invalidates_pending_state(): void
    {
        $this->owner();
        $data = ['client_id' => 'client-test', 'client_secret' => 'secret-google-test', 'calendar_id' => 'calendar@example.test', 'lock_version' => 1];
        $this->put('/integracoes/credenciais/google', $data)->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('secret-google-test', DB::table('integration_settings')->value('google_credentials'));
        $this->get('/integracoes')->assertDontSee('secret-google-test')->assertSee('/integracoes/google/retorno');
        $response = $this->post('/integracoes/google/conectar');
        $this->assertStringContainsString('client_id=client-test', $response->headers->get('Location'));
        $saved = session('google_oauth');
        $data['lock_version'] = 2;
        $data['calendar_id'] = 'other@example.test';
        $data['client_secret'] = '';
        $this->put('/integracoes/credenciais/google', $data)->assertSessionHasNoErrors();
        $this->withSession(['google_oauth' => $saved])->get('/integracoes/google/retorno?state='.$saved['state'].'&code=fake')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_google_change_requires_disconnect_and_secrets_are_not_flashed(): void
    {
        $this->owner();
        DB::table('google_connections')->insert(['namespace' => 'test', 'calendar_id' => 'old', 'refresh_token_ciphertext' => 'encrypted', 'active' => true, 'created_at' => now()]);
        $this->put('/integracoes/credenciais/google', ['client_id' => 'x', 'client_secret' => 'do-not-flash', 'calendar_id' => 'calendar', 'lock_version' => 1])->assertSessionHasErrors('integration');
        $this->assertArrayNotHasKey('client_secret', session('_old_input', []));
        $this->put('/integracoes/credenciais/evolution', ['url' => 'http://insecure.test', 'instance' => 'x', 'token' => 'do-not-flash', 'lock_version' => 1])->assertSessionHasErrors('url');
        $this->assertArrayNotHasKey('token', session('_old_input', []));
    }

    public function test_secretary_cannot_read_or_change_credentials(): void
    {
        $this->seed();
        $u = User::factory()->create()->refresh();
        $this->actingAs($u)->withSession(['auth_version' => $u->session_version]);
        $this->get('/integracoes')->assertForbidden();
        $this->put('/integracoes/credenciais/evolution', [])->assertForbidden();
        $this->put('/integracoes/credenciais/google',[])->assertForbidden();
    }
}
