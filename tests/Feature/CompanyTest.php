<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): void
    {
        $this->seed();
        $owner = User::factory()->create(['role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
        DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $owner->id, 'display_name' => 'Teste', 'contact_phone' => '000000000']);
        $this->actingAs($owner)->withSession(['auth_version' => $owner->session_version]);
    }

    public function test_owner_creates_edits_and_cannot_overwrite_stale_form(): void
    {
        $this->owner();
        $data = ['cnpj' => '11.222.333/0001-81', 'legal_name' => 'Empresa Teste', 'active' => 1, 'postal_code' => '01001-000', 'address_number' => '10'];
        $this->post('/empresas', $data)->assertSessionHasNoErrors()->assertRedirect('/empresas');
        $company = Company::firstOrFail();
        $this->assertSame('11222333000181', $company->cnpj);
        $this->get('/empresas')->assertOk()->assertSee('Empresa Teste');
        $this->get('/empresas/'.$company->id.'/edit')->assertOk()->assertSee('Buscar CEP');
        $this->put('/empresas/'.$company->id, $data + ['lock_version' => 1])->assertSessionHasNoErrors();
        $this->put('/empresas/'.$company->id, $data + ['lock_version' => 1])->assertSessionHasErrors('company');
        $this->post('/empresas', $data)->assertSessionHasErrors('cnpj');
    }

    public function test_cnpj_validation_supports_letters_and_rejects_bad_digits(): void
    {
        $this->owner();
        $this->post('/empresas', ['cnpj' => '00.000.000/E08G-12', 'legal_name' => 'Empresa ficticia alfa', 'active' => 1])->assertSessionHasNoErrors();
        foreach (['00000000000000', '11222333000180', '!!!!!!!!!!!!!!'] as $cnpj) {
            $this->post('/empresas', ['cnpj' => $cnpj, 'legal_name' => 'Teste', 'active' => 1])->assertSessionHasErrors('cnpj');
        }
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_secretary_cannot_access_company_administration(): void
    {
        $this->seed();
        $user = User::factory()->create()->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->session_version]);
        $this->get('/empresas')->assertForbidden();
        $this->post('/empresas', ['cnpj' => '11222333000181', 'legal_name' => 'Teste', 'active' => 1])->assertForbidden();
    }
}
