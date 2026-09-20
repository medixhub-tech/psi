<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Patient;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $secretary;

    private Patient $patient;

    private ServiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00:00', 'UTC'));
        $this->owner = User::factory()->create(['role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
        DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $this->owner->id, 'display_name' => 'Teste', 'contact_phone' => '11999990000']);
        $this->secretary = User::factory()->create()->refresh();
        $this->patient = Patient::factory()->create(['created_by' => $this->owner->id]);
        $this->type = ServiceType::create(['name' => 'Psicoterapia individual', 'amount' => '150.30', 'active' => true, 'lock_version' => 1]);
    }

    private function loginAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth_version' => $user->session_version]);
    }

    private function schedule(string $day = '2026-09-19'): Charge
    {
        $this->loginAs($this->secretary)->post('/agenda', ['patient_id' => $this->patient->id, 'service_type_id' => $this->type->id, 'starts_at' => $day.'T10:00', 'ends_at' => $day.'T11:00', 'modality' => 'in_person', 'amount' => '0.01'])->assertSessionHasNoErrors();

        return Charge::latest('id')->firstOrFail();
    }

    private function payment(Charge $charge, string $amount = '50.10', ?string $key = null): array
    {
        return ['amount' => $amount, 'method' => 'pix', 'idempotency_key' => $key ?? (string) Str::uuid(), 'lock_version' => $charge->fresh()->lock_version];
    }

    private function pay(Charge $charge, array $data): TestResponse
    {
        return $this->post('/cobrancas/'.$charge->id.'/recebimentos', $data);
    }

    public function test_only_owner_can_manage_types_and_price_changes_preserve_bookings(): void
    {
        $charge = $this->schedule();
        $this->assertSame('150.30', $charge->amount);
        $this->get('/financeiro/atendimentos')->assertForbidden();
        $this->put('/financeiro/atendimentos/'.$this->type->id, ['name' => 'Injetado', 'amount' => '1.00', 'active' => 1, 'lock_version' => 1])->assertForbidden();
        $this->loginAs($this->owner)->put('/financeiro/atendimentos/'.$this->type->id, ['name' => 'Psicoterapia', 'amount' => '200,10', 'active' => 1, 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('150.30', $charge->fresh()->amount);
        $this->assertSame('Psicoterapia individual', $charge->appointment->service_name);
        $next = $this->schedule('2026-09-20');
        $this->assertSame('200.10', $next->amount);
        $this->loginAs($this->owner)->put('/financeiro/atendimentos/'.$this->type->id, ['name' => 'Velho', 'amount' => '0.01', 'active' => 1, 'lock_version' => 1])->assertSessionHasErrors('type');
    }

    public function test_inactive_and_missing_types_cannot_be_scheduled(): void
    {
        $this->type->update(['active' => false]);
        $this->loginAs($this->secretary)->post('/agenda', ['patient_id' => $this->patient->id, 'service_type_id' => $this->type->id, 'starts_at' => '2026-09-19T10:00', 'ends_at' => '2026-09-19T11:00', 'modality' => 'in_person'])->assertSessionHasErrors('agenda');
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('charges', 0);
    }

    public function test_secretary_sees_only_today_and_cannot_access_management(): void
    {
        $today = $this->schedule();
        $future = $this->schedule('2026-09-20');
        $future->update(['amount' => '912.34']);
        $this->get('/cobrancas/hoje?date=2026-09-20')->assertOk()->assertSee('150,30')->assertDontSee('912,34');
        $this->get('/cobrancas/'.$future->id)->assertForbidden();
        $this->pay($future, $this->payment($future))->assertForbidden();
        $this->get('/agenda?date=2026-09-20')->assertOk()->assertDontSee('912,34')->assertDontSee('912.34');
        $this->get('/agenda/nova')->assertOk()->assertDontSee('150.30')->assertDontSee('150,30');
        $this->get('/financeiro')->assertForbidden();
        $this->put('/cobrancas/'.$today->id, [])->assertForbidden();
        $this->post('/cobrancas/'.$today->id.'/estornos/1', [])->assertForbidden();
        $this->get('/cobrancas/'.$today->id)->assertOk()->assertDontSee('Ajustar cobrança')->assertDontSee('Recebimentos e estornos');
    }

    public function test_partial_payments_exact_money_overpayment_and_invalid_amounts(): void
    {
        $charge = $this->schedule();
        $this->pay($charge, $this->payment($charge))->assertSessionHasNoErrors();
        $this->assertSame(10020, $charge->fresh()->balanceCents());
        $this->pay($charge, $this->payment($charge, '100,20'))->assertSessionHasNoErrors();
        $this->assertSame(0, $charge->fresh()->balanceCents());
        $this->assertSame('Quitada', $charge->fresh()->label());
        $this->pay($charge, $this->payment($charge, '0.01'))->assertSessionHasErrors('billing');
        foreach (['-1', '0.001', '1e2', '1.000,00'] as $amount) {
            $this->pay($charge, $this->payment($charge, $amount))->assertSessionHasErrors('amount');
        }
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_duplicate_submission_is_idempotent_and_changed_payload_conflicts(): void
    {
        $charge = $this->schedule();
        $data = $this->payment($charge);
        $this->pay($charge, $data)->assertSessionHasNoErrors();
        $this->pay($charge, $data)->assertSessionHasNoErrors();
        $this->pay($charge, array_replace($data, ['amount' => '51.00']))->assertStatus(409);
        $this->pay($charge, array_replace($data, ['method' => 'cash']))->assertStatus(409);
        $this->loginAs($this->owner);
        $this->pay($charge, $data)->assertStatus(409);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_stale_payment_is_rejected(): void
    {
        $charge = $this->schedule();
        $first = $this->payment($charge);
        $stale = $this->payment($charge);
        $this->pay($charge, $first)->assertSessionHasNoErrors();
        $this->pay($charge, $stale)->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reversal_is_owner_only_integral_and_idempotent(): void
    {
        $charge = $this->schedule();
        $this->pay($charge, $this->payment($charge))->assertSessionHasNoErrors();
        $payment = DB::table('payments')->first();
        $url = '/cobrancas/'.$charge->id.'/estornos/'.$payment->id;
        $this->post($url, ['reason' => 'Correção'])->assertForbidden();
        $this->loginAs($this->owner)->post($url, ['reason' => 'Correção'])->assertSessionHasNoErrors();
        $this->post($url, ['reason' => 'Correção'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payment_reversals', 1);
        $this->assertSame(15030, $charge->fresh()->balanceCents());
        $this->assertDatabaseHas('payment_reversals', ['amount' => '50.10', 'recorded_by' => $this->owner->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'payments.reversed']);
    }

    public function test_adjustments_cannot_hide_received_money_and_require_reason(): void
    {
        $charge = $this->schedule();
        $this->pay($charge, $this->payment($charge));
        $this->loginAs($this->owner);
        $data = ['amount' => '150.30', 'status' => 'waived', 'reason' => 'Dispensa', 'lock_version' => $charge->fresh()->lock_version];
        $this->put('/cobrancas/'.$charge->id, $data)->assertSessionHasErrors('billing');
        $this->put('/cobrancas/'.$charge->id, array_replace($data, ['amount' => '40.00', 'status' => 'open']))->assertSessionHasErrors('billing');
        $this->put('/cobrancas/'.$charge->id, array_replace($data, ['status' => 'open', 'reason' => '']))->assertSessionHasErrors('reason');
        $this->put('/cobrancas/'.$charge->id, array_replace($data, ['amount' => '100.00', 'status' => 'open']))->assertSessionHasNoErrors();
        $this->assertSame(4990, $charge->fresh()->balanceCents());
        $this->assertDatabaseHas('charge_history', ['action' => 'adjusted', 'reason' => 'Dispensa']);
    }

    public function test_waiver_blocks_receipts_and_owner_can_reopen(): void
    {
        $charge = $this->schedule();
        $this->loginAs($this->owner)->put('/cobrancas/'.$charge->id, ['amount' => '150.30', 'status' => 'waived', 'reason' => 'Cortesia', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->pay($charge, $this->payment($charge))->assertSessionHasErrors('billing');
        $this->assertSame(0, $charge->fresh()->balanceCents());
        $this->put('/cobrancas/'.$charge->id, ['amount' => '150.30', 'status' => 'open', 'reason' => 'Correção', 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(15030, $charge->fresh()->balanceCents());
    }

    public function test_rescheduling_preserves_price_and_receipts_and_revokes_secretary_access(): void
    {
        $charge = $this->schedule();
        $this->pay($charge, $this->payment($charge));
        $appointment = $charge->appointment;
        $this->put('/agenda/'.$appointment->id, ['patient_id' => $this->patient->id, 'starts_at' => '2026-09-20T10:00', 'ends_at' => '2026-09-20T11:00', 'modality' => 'in_person', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('2026-09-20', $charge->fresh()->due_on->toDateString());
        $this->assertSame('150.30', $charge->fresh()->amount);
        $this->assertSame(10020, $charge->fresh()->balanceCents());
        $this->get('/cobrancas/'.$charge->id)->assertForbidden();
        $this->pay($charge, $this->payment($charge))->assertForbidden();
    }

    public function test_cancellation_does_not_erase_receipts_or_waive_charge(): void
    {
        $charge = $this->schedule();
        $this->pay($charge, $this->payment($charge));
        $this->post('/agenda/'.$charge->appointment_id.'/situacao', ['action' => 'cancel', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('open', $charge->fresh()->status);
        $this->assertSame(10020, $charge->fresh()->balanceCents());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_local_midnight_limits_secretary_access(): void
    {
        $charge = $this->schedule('2026-09-20');
        $charge->appointment->update(['starts_at' => '2026-09-20 02:59:59']);
        $this->get('/cobrancas/'.$charge->id)->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 03:00:00', 'UTC'));
        $this->get('/cobrancas/'.$charge->id)->assertForbidden();
        $this->pay($charge, $this->payment($charge))->assertForbidden();
    }

    public function test_custom_role_without_billing_cannot_access_or_receive(): void
    {
        $charge = $this->schedule();
        $this->secretary->role->permissions()->detach();
        $this->loginAs($this->secretary->fresh());
        $this->get('/cobrancas/hoje')->assertForbidden();
        $this->get('/cobrancas/'.$charge->id)->assertForbidden();
        $this->pay($charge, $this->payment($charge))->assertForbidden();
    }

    public function test_owner_report_reconciles_cash_movements_and_outstanding_balance(): void
    {
        $charge = $this->schedule();
        $this->pay($charge, $this->payment($charge, '100.10'));
        $payment = DB::table('payments')->first();
        $this->travel(1)->days();
        $this->loginAs($this->owner)->post('/cobrancas/'.$charge->id.'/estornos/'.$payment->id, ['reason' => 'Correção'])->assertSessionHasNoErrors();
        $this->get('/financeiro?from=2026-09-19&to=2026-09-19')->assertOk()->assertViewHas('received', 10010)->assertViewHas('reversed', 0)->assertViewHas('outstanding', 15030);
        $this->get('/financeiro?from=2026-09-20&to=2026-09-20')->assertOk()->assertViewHas('received', 0)->assertViewHas('reversed', 10010)->assertSee('-R$ 100,10');
        $this->get('/financeiro/atendimentos')->assertOk();
        $this->get('/cobrancas/'.$charge->id)->assertOk()->assertSee('Correção');
    }

    public function test_legacy_unpriced_charge_requires_professional_to_set_amount(): void
    {
        $charge = $this->schedule();
        $charge->update(['amount' => null]);
        $this->pay($charge, $this->payment($charge))->assertSessionHasErrors('billing');
        $this->loginAs($this->owner)->get('/financeiro')->assertOk()->assertViewHas('unpriced', 1);
        $this->put('/cobrancas/'.$charge->id, ['amount' => '75,25', 'status' => 'open', 'reason' => 'Valor acordado', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('75.25',$charge->fresh()->amount);
    }

    public function test_money_uses_integer_cents(): void
    {
        $this->assertSame(30,Money::cents('0.10') + Money::cents('0.20'));
        $this->assertSame('1234.56',Money::decimal(123456));
        $this->assertSame('R$ 1.234,56',Money::display(123456));
    }
}
