<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CpfLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CpfLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::preventStrayRequests();
        config(['integrations.cpf.url' => 'https://cpf.example.test', 'integrations.cpf.token' => 'fake-secret']);
    }

    private function login(): void
    {
        $user = User::factory()->create()->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->session_version]);
    }

    public function test_secretary_can_consult_only_the_name_without_exposing_certificate_data(): void
    {
        $this->login();
        Http::fake(['cpf.example.test/*' => Http::response(['cpf' => '529.982.247-25', 'encontrado' => true, 'nome_certidao' => 'PACIENTE TESTE', 'tem_registro' => true, 'numero_certidao' => 'private'])]);
        $this->postJson('/pacientes/consulta-cpf', ['cpf' => '529.982.247-25'])->assertOk()->assertExactJson(['status' => 'found', 'name' => 'PACIENTE TESTE'])->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($r) => $r->url() === 'https://cpf.example.test/consulta/cpf' && $r->hasHeader('Authorization', 'Bearer fake-secret') && $r['cpf'] === '52998224725');
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_http_is_allowed_only_on_loopback_in_local_environments(): void
    {
        Http::fake(['127.0.0.1:8082/*' => Http::response(['cpf' => '52998224725', 'encontrado' => true, 'nome_certidao' => 'TESTE'])]);
        config(['integrations.cpf.url' => 'http://127.0.0.1:8082']);
        $this->app->instance('env', 'production');
        $this->assertSame(['status' => 'unavailable'], CpfLookup::consult('52998224725'));
        Http::assertNothingSent();
        $this->app->instance('env', 'homologacao');
        $this->assertSame('found', CpfLookup::consult('52998224725')['status']);
        config(['integrations.cpf.url' => 'http://external.example.test']);
        $this->assertSame(['status' => 'unavailable'], CpfLookup::consult('52998224725'));
        Http::assertSentCount(1);
    }

    public function test_invalid_cpf_never_reaches_provider(): void
    {
        $this->login();
        foreach (['11111111111', '52998224724', 'abc52998224725'] as $cpf) {
            $this->postJson('/pacientes/consulta-cpf', ['cpf' => $cpf])->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_provider_failures_and_mismatches_do_not_return_names(): void
    {
        Http::fakeSequence()->push(['cpf' => '52998224725', 'encontrado' => false])
            ->push(['cpf' => '52998224725', 'encontrado' => null, 'erro' => 'secret'])
            ->push(['cpf' => '11111111111', 'encontrado' => true, 'nome_certidao' => 'WRONG'])
            ->push('invalid json')->push([], 500);
        $this->assertSame(['status' => 'not_found'], CpfLookup::consult('52998224725'));
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(['status' => 'unavailable'], CpfLookup::consult('52998224725'));
        }
    }

    public function test_timeout_and_missing_config_keep_manual_registration_available(): void
    {
        Http::fake(fn () => throw new ConnectionException('secret'));
        $this->assertSame(['status' => 'unavailable'], CpfLookup::consult('52998224725'));
        config(['integrations.cpf.token' => null]);
        $this->assertSame(['status' => 'unavailable'], CpfLookup::consult('52998224725'));
        $this->login();
        $this->post('/pacientes', ['full_name' => 'Paciente manual', 'active' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('patients', ['full_name' => 'Paciente manual']);
    }

    public function test_guest_cannot_query_and_calls_are_rate_limited(): void
    {
        $this->postJson('/pacientes/consulta-cpf', ['cpf' => '52998224725'])->assertUnauthorized();
        $this->login();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/pacientes/consulta-cpf', ['cpf' => '11111111111'])->assertUnprocessable();
        }
        $this->postJson('/pacientes/consulta-cpf', ['cpf' => '52998224725'])->assertTooManyRequests();
        Http::assertNothingSent();
    }
}
