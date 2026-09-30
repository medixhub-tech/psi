<?php

namespace Tests\Feature;

use App\ExternalApi\ViaCep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_and_manual_address_persistence(): void
    {
        $this->seed();
        Http::preventStrayRequests();
        Http::fake(['viacep.com.br/*' => Http::response(['cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP'])]);
        $user = User::factory()->create()->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->session_version]);
        $this->postJson('/enderecos/consulta-cep', ['cep' => '01001-000'])->assertOk()->assertJsonPath('address.city', 'São Paulo');
        $this->postJson('/enderecos/consulta-cep', ['cep' => 'not-a-cep'])->assertUnprocessable();
        Http::assertSentCount(1);
        $this->post('/pacientes', ['full_name' => 'Teste', 'active' => 1, 'postal_code' => '01001-000', 'street' => 'Praça da Sé', 'address_number' => '12', 'city' => 'São Paulo', 'state' => 'SP'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('patients', ['full_name' => 'Teste', 'address_number' => '12', 'postal_code' => '01001-000']);
        $this->get('/pacientes/create')->assertOk()->assertSee('Buscar CEP');
    }

    public function test_unavailable_and_not_found_are_distinct(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['erro' => true])->push([], 500)->push(['cep' => '99999-999', 'localidade' => 'Outra cidade', 'uf' => 'SP']);
        $this->assertSame(['status' => 'not_found'], ViaCep::consult('01001000'));
        $this->assertSame(['status' => 'unavailable'], ViaCep::consult('01001000'));
        $this->assertSame(['status' => 'unavailable'], ViaCep::consult('01001000'));
    }

    public function test_guest_cannot_consult(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/enderecos/consulta-cep', ['cep' => '01001000'])->assertUnauthorized();
        Http::assertNothingSent();
    }
}
