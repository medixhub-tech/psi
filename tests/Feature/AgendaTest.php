<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $secretary;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00:00', 'UTC'));
        $this->owner = User::factory()->create(['role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
        DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $this->owner->id, 'display_name' => 'Teste', 'contact_phone' => '11999990000']);
        $this->secretary = User::factory()->create()->refresh();
        $this->patient = Patient::factory()->create(['created_by' => $this->owner->id]);
    }

    private function loginAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth_version' => $user->session_version]);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['patient_id' => $this->patient->id, 'starts_at' => '2026-09-19T10:00', 'ends_at' => '2026-09-19T11:00', 'modality' => 'in_person'], $extra);
    }

    private function schedule(array $extra = []): Appointment
    {
        $this->loginAs($this->secretary)->post('/agenda', $this->payload($extra))->assertSessionHasNoErrors()->assertRedirect();

        return Appointment::latest('id')->firstOrFail();
    }

    private function action(Appointment $appointment, string $action, ?int $version = null): TestResponse
    {
        return $this->post('/agenda/'.$appointment->id.'/situacao', ['action' => $action, 'lock_version' => $version ?? $appointment->fresh()->lock_version]);
    }

    public function test_patient_administration_and_stale_update(): void
    {
        $this->loginAs($this->secretary)->post('/pacientes', ['full_name' => 'Paciente Exemplo', 'active' => 1])->assertRedirect('/pacientes');
        $p = Patient::where('full_name', 'Paciente Exemplo')->firstOrFail();
        $this->put('/pacientes/'.$p->id, ['full_name' => 'Novo nome', 'active' => 0, 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->put('/pacientes/'.$p->id, ['full_name' => 'Nome antigo', 'active' => 1, 'lock_version' => 1])->assertSessionHasErrors('patient');
        $this->assertSame('Novo nome', $p->fresh()->full_name);
        $this->assertFalse($p->fresh()->active);
    }

    public function test_screens_render_for_owner_and_secretary(): void
    {
        $a = $this->schedule();
        foreach ([$this->owner, $this->secretary] as $user) {
            $this->loginAs($user);
            foreach (['/pacientes', '/pacientes/create', '/pacientes/'.$this->patient->id.'/edit', '/agenda', '/agenda/nova', '/agenda/'.$a->id.'/editar', '/painel', '/painel/fila'] as $url) {
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_permissions_restrict_all_agenda_and_patient_entry_points(): void
    {
        $this->secretary->role->permissions()->detach();
        $this->loginAs($this->secretary->fresh());
        foreach (['/pacientes', '/agenda', '/agenda/nova', '/painel/fila'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/agenda', $this->payload())->assertForbidden();
        $this->post('/pacientes', ['full_name' => 'Teste', 'active' => 1])->assertForbidden();
        $this->get('/painel')->assertOk()->assertDontSee($this->patient->full_name);
    }

    public function test_timezone_conversion_overlap_and_adjacent_slots(): void
    {
        $a = $this->schedule();
        $this->assertSame('2026-09-19 13:00:00', $a->starts_at->format('Y-m-d H:i:s'));
        $this->post('/agenda', $this->payload(['starts_at' => '2026-09-19T10:30', 'ends_at' => '2026-09-19T11:30']))->assertSessionHasErrors('agenda');
        $this->post('/agenda', $this->payload(['starts_at' => '2026-09-19T11:00', 'ends_at' => '2026-09-19T12:00']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_local_day_boundary(): void
    {
        $this->schedule(['starts_at' => '2026-09-19T23:30', 'ends_at' => '2026-09-20T00:30']);
        $this->get('/agenda?date=2026-09-19')->assertSee($this->patient->full_name);
        $this->get('/agenda?date=2026-09-20')->assertDontSee($this->patient->full_name);
    }

    public function test_invalid_interval_past_and_inactive_patient_are_rejected(): void
    {
        $this->loginAs($this->secretary);
        foreach ([['ends_at' => '2026-09-19T09:30'], ['starts_at' => '2026-09-19T08:00'], ['ends_at' => '2026-09-20T10:00']] as $extra) {
            $this->post('/agenda', $this->payload($extra))->assertSessionHasErrors('agenda');
        }
        $this->patient->update(['active' => false]);
        $this->post('/agenda', $this->payload())->assertSessionHasErrors('agenda');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_blocks_prevent_scheduling_and_can_be_removed(): void
    {
        $this->loginAs($this->secretary)->post('/bloqueios', ['starts_at' => '2026-09-19T10:00', 'ends_at' => '2026-09-19T11:00', 'label' => 'Indisponível'])->assertSessionHasNoErrors();
        $this->post('/agenda', $this->payload())->assertSessionHasErrors('agenda');
        $this->delete('/bloqueios/'.DB::table('agenda_blocks')->value('id'))->assertSessionHasNoErrors();
        $this->schedule();
        $this->post('/bloqueios', ['starts_at' => '2026-09-19T10:00', 'ends_at' => '2026-09-19T11:00', 'label' => 'Indisponível'])->assertSessionHasErrors('agenda');
    }

    public function test_reception_to_treatment_flow_and_audit(): void
    {
        $a = $this->schedule();
        $this->action($a, 'arrive')->assertSessionHasNoErrors();
        $this->assertSame('waiting', $a->fresh()->status);
        $this->get('/painel/fila')->assertSee('Aguardando')->assertSee($this->patient->full_name);
        $this->action($a, 'call')->assertForbidden();
        $this->action($a, 'finish')->assertForbidden();
        $this->loginAs($this->owner);
        $this->action($a, 'call')->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $a->fresh()->status);
        $this->action($a, 'finish')->assertSessionHasNoErrors();
        $this->assertSame('completed', $a->fresh()->status);
        $this->assertNotNull($a->fresh()->finished_at);
        $this->action($a, 'cancel')->assertSessionHasErrors('agenda');
        $this->assertDatabaseCount('appointment_history', 4);
        $this->assertDatabaseHas('audit_events', ['action' => 'appointments.finish', 'actor_id' => $this->owner->id]);
    }

    public function test_only_one_patient_can_be_in_treatment(): void
    {
        $a = $this->schedule();
        $b = $this->schedule(['starts_at' => '2026-09-19T11:00', 'ends_at' => '2026-09-19T12:00']);
        $this->action($a, 'arrive');
        $this->action($b, 'arrive');
        $this->loginAs($this->owner);
        $this->action($a, 'call')->assertSessionHasNoErrors();
        $this->action($b, 'call')->assertSessionHasErrors('agenda');
        $this->assertSame('waiting', $b->fresh()->status);
    }

    public function test_reschedule_rejects_stale_changes_and_patient_replacement(): void
    {
        $a = $this->schedule();
        $data = $this->payload(['starts_at' => '2026-09-19T12:00', 'ends_at' => '2026-09-19T13:00', 'lock_version' => 1]);
        $this->put('/agenda/'.$a->id, $data)->assertSessionHasNoErrors();
        $this->assertSame(2, $a->fresh()->schedule_version);
        $this->put('/agenda/'.$a->id, $data)->assertSessionHasErrors('agenda');
        $this->action($a, 'cancel', 1)->assertSessionHasErrors('agenda');
        $other = Patient::factory()->create(['created_by' => $this->owner->id]);
        $this->put('/agenda/'.$a->id, array_replace($data, ['patient_id' => $other->id, 'lock_version' => 2]))->assertSessionHasErrors('agenda');
    }

    public function test_waiting_reschedule_requires_confirmation_and_cancellation_releases_slot(): void
    {
        $a = $this->schedule();
        $this->action($a, 'arrive');
        $data = $this->payload(['lock_version' => 2]);
        $this->put('/agenda/'.$a->id, $data)->assertSessionHasErrors('agenda');
        $this->put('/agenda/'.$a->id, $data + ['confirm_waiting' => 1])->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $a->fresh()->status);
        $this->assertNull($a->fresh()->arrived_at);
        $this->action($a, 'cancel')->assertSessionHasNoErrors();
        $this->schedule();
    }

    public function test_no_show_and_arrival_date_rules(): void
    {
        $a = $this->schedule();
        $this->action($a, 'no_show')->assertSessionHasErrors('agenda');
        $tomorrow = $this->schedule(['starts_at' => '2026-09-20T10:00', 'ends_at' => '2026-09-20T11:00']);
        $this->action($tomorrow, 'arrive')->assertSessionHasErrors('agenda');
        $this->travel(2)->hours();
        $this->action($a,'no_show')->assertSessionHasNoErrors();
        $this->assertSame('no_show',$a->fresh()->status);
    }
}
