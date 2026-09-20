<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Support\GoogleCalendar;
use App\Support\ReminderGateway;
use App\Support\Reminders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'));
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
        DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $this->owner->id, 'display_name' => 'Teste', 'contact_phone' => '11999990000']);
        $this->secretary = User::factory()->create()->refresh();
        $this->patient = Patient::factory()->create(['full_name' => 'Paciente Ficticio', 'phone' => '+5511999999999', 'email' => 'patient@example.test', 'whatsapp_reminders' => true, 'email_reminders' => true, 'communication_source' => 'Autorizacao ficticia', 'created_by' => $this->owner->id]);
        $this->type = ServiceType::create(['name' => 'Sessao', 'amount' => '150.00', 'active' => true]);
        config(['integrations.evolution' => ['url' => 'https://evolution.example.test', 'token' => 'fake-secret', 'instance' => 'test'], 'integrations.google' => ['client_id' => 'client', 'client_secret' => 'secret', 'calendar_id' => 'test@group.calendar.google.com'], 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        DB::table('integration_settings')->where('id', 1)->update(['whatsapp_enabled' => true, 'email_enabled' => true]);
    }

    private function loginAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth_version' => $user->session_version]);
    }

    private function schedule(string $start = '2026-09-21T10:00', string $end = '2026-09-21T11:00'): Appointment
    {
        $this->loginAs($this->secretary)->post('/agenda', ['patient_id' => $this->patient->id, 'service_type_id' => $this->type->id, 'starts_at' => $start, 'ends_at' => $end, 'modality' => 'in_person'])->assertSessionHasNoErrors();

        return Appointment::latest('id')->firstOrFail();
    }

    private function reminder(Appointment $a, string $channel = 'whatsapp'): object
    {
        return DB::table('reminders')->where('appointment_id', $a->id)->where('schedule_version', $a->schedule_version)->where('channel', $channel)->first();
    }

    private function connection(): int
    {
        return DB::table('google_connections')->insertGetId(['namespace' => (string) Str::uuid(), 'calendar_id' => 'test@group.calendar.google.com', 'refresh_token_ciphertext' => Crypt::encryptString('refresh-secret'), 'active' => true]);
    }

    public function test_planning_is_exactly_24_hours_and_idempotent(): void
    {
        $a = $this->schedule();
        $r = $this->reminder($a);
        $this->assertSame('2026-09-20 13:00:00', $r->scheduled_at);
        $this->assertSame('2026-09-20 13:05:00', $r->expires_at);
        Reminders::plan($a);
        $this->assertDatabaseCount('reminders', 2);
        Reminders::dispatch($r->id);
        Http::assertNothingSent();
    }

    public function test_evolution_reminder_uses_current_recipient_and_is_not_duplicated(): void
    {
        $a = $this->schedule();
        $r = $this->reminder($a);
        $this->patient->update(['phone' => '+5511888888888']);
        $this->travel(1)->hours();
        Http::fake(['evolution.example.test/*' => Http::response(['key' => ['id' => 'message-1']], 201)]);
        Reminders::dispatch($r->id);
        Reminders::dispatch($r->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['number'] === '5511888888888' && str_contains($request['text'], 'cancelar ou reagendar') && str_contains($request['text'], '21/09/2026') && $request->hasHeader('apikey', 'fake-secret'));
        $this->assertDatabaseHas('reminders', ['id' => $r->id, 'status' => 'accepted', 'provider_message_id' => 'message-1']);
    }

    public function test_email_is_sent_at_same_target_without_real_delivery(): void
    {
        $a = $this->schedule();
        $r = $this->reminder($a, 'email');
        $this->travel(1)->hours();
        Mail::shouldReceive('raw')->once()->withArgs(fn ($text, $callback) => str_contains($text, 'cancelar ou reagendar') && str_contains($text, '10:00'));
        Reminders::dispatch($r->id);
        $this->assertDatabaseHas('reminders', ['id' => $r->id, 'status' => 'accepted', 'provider' => 'smtp']);
    }

    public function test_log_mailer_is_never_reported_as_sent(): void
    {
        $a = $this->schedule();
        $r = $this->reminder($a, 'email');
        $this->travel(1)->hours();
        config(['mail.default' => 'log']);
        Reminders::dispatch($r->id);
        $this->assertDatabaseHas('reminders', ['id' => $r->id, 'status' => 'failed', 'error_code' => 'provider_not_configured']);
    }

    public function test_reschedule_and_cancel_invalidate_old_plans(): void
    {
        $a = $this->schedule();
        $this->put('/agenda/'.$a->id, ['patient_id' => $this->patient->id, 'starts_at' => '2026-09-22T10:00', 'ends_at' => '2026-09-22T11:00', 'modality' => 'in_person', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reminders', 4);
        $this->assertDatabaseHas('reminders', ['appointment_id' => $a->id, 'schedule_version' => 1, 'status' => 'cancelled']);
        $this->post('/agenda/'.$a->id.'/situacao', ['action' => 'cancel', 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('reminders')->where('status', 'pending')->count());
    }

    public function test_late_bookings_and_delayed_cron_do_not_send(): void
    {
        $a = $this->schedule('2026-09-20T10:00', '2026-09-20T11:00');
        $this->assertSame('expired', $this->reminder($a)->status);
        $future = $this->schedule();
        $this->travel(66)->minutes();
        Reminders::dispatch($this->reminder($future)->id);
        $this->assertDatabaseHas('reminders', ['id' => $this->reminder($future)->id, 'status' => 'expired']);
        Http::assertNothingSent();
    }

    public function test_disabled_channels_and_revoked_permission_do_not_send(): void
    {
        $a = $this->schedule();
        $r = $this->reminder($a);
        $this->travel(1)->hours();
        DB::table('integration_settings')->where('id', 1)->update(['whatsapp_enabled' => false]);
        Reminders::dispatch($r->id);
        $this->assertDatabaseHas('reminders', ['id' => $r->id, 'status' => 'pending']);
        DB::table('integration_settings')->where('id', 1)->update(['whatsapp_enabled' => true]);
        $this->patient->update(['whatsapp_reminders' => false]);
        Reminders::dispatch($r->id);
        $this->assertDatabaseHas('reminders', ['id' => $r->id, 'status' => 'cancelled', 'error_code' => 'patient_not_authorized']);
        Http::assertNothingSent();
    }

    public function test_uncertain_transport_and_expired_lease_are_not_retried(): void
    {
        $a = $this->schedule();
        $r = $this->reminder($a);
        $this->travel(1)->hours();
        Http::fake(fn () => throw new ConnectionException('Sensitive provider detail'));
        Reminders::dispatch($r->id);
        Reminders::dispatch($r->id);
        $this->assertDatabaseHas('reminders', ['id' => $r->id, 'status' => 'unknown', 'error_code' => 'transport_uncertain']);
        $email = $this->reminder($a, 'email');
        DB::table('reminders')->where('id', $email->id)->update(['status' => 'processing', 'lease_until' => now()->subMinute()]);
        Reminders::run(microtime(true) + 2);
        $this->assertDatabaseHas('reminders', ['id' => $email->id, 'status' => 'unknown']);
    }

    public function test_provider_rejection_is_distinct_from_acceptance(): void
    {
        $a = $this->schedule();
        $this->travel(1)->hours();
        Http::fake(['evolution.example.test/*' => Http::response(['error' => 'denied'], 401)]);
        Reminders::dispatch($this->reminder($a)->id);
        $this->assertDatabaseHas('reminders', ['id' => $this->reminder($a)->id, 'status' => 'failed', 'error_code' => 'http_401']);
    }

    public function test_secretary_cannot_access_configuration_or_oauth(): void
    {
        $this->loginAs($this->secretary);
        $this->get('/integracoes')->assertForbidden();
        $this->put('/integracoes', [])->assertForbidden();
        $this->post('/integracoes/google/conectar')->assertForbidden();
        $this->get('/integracoes/google/retorno?state=x&code=x')->assertForbidden();
        $this->post('/integracoes/google/desconectar')->assertForbidden();
    }

    public function test_configuration_does_not_expose_tokens_and_rejects_stale_forms(): void
    {
        $this->loginAs($this->owner)->get('/integracoes')->assertOk()->assertDontSee('fake-secret');
        $data = ['whatsapp_enabled' => 1, 'email_enabled' => 1, 'whatsapp_provider' => 'evolution', 'lock_version' => 1];
        $this->put('/integracoes', $data)->assertSessionHasNoErrors();
        $this->put('/integracoes', $data)->assertSessionHasErrors('integration');
    }

    public function test_patient_consent_requires_source_and_international_phone(): void
    {
        $this->loginAs($this->secretary)->post('/pacientes', ['full_name' => 'Ficticio', 'active' => 1, 'whatsapp_reminders' => 1, 'phone' => '11999990000'])->assertSessionHasErrors(['phone', 'communication_source']);
    }

    public function test_google_sync_contains_no_patient_details_and_has_stable_id(): void
    {
        $this->connection();
        $a = $this->schedule();
        $event = DB::table('calendar_events')->first();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event->event_id);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'access']), 'www.googleapis.com/*' => Http::sequence()->push([], 404)->push(['id' => $event->event_id], 200)]);
        GoogleCalendar::sync($event->id);
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'status' => 'synced', 'synced_version' => 1]);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/events?') && $request['id'] === $event->event_id && $request['summary'] === 'Consulta' && ! isset($request['attendees']) && ! str_contains($request->body(), $this->patient->full_name));
    }

    public function test_google_stale_response_does_not_mark_new_version_synced(): void
    {
        $this->connection();
        $a = $this->schedule();
        $event = DB::table('calendar_events')->first();
        Http::fake(function ($request) use ($a) {
            if (str_contains($request->url(), 'oauth2')) {
                return Http::response(['access_token' => 'access']);
            }$a->update(['schedule_version' => 2]);
            GoogleCalendar::plan($a->fresh());

            return Http::response([], 200);
        });
        GoogleCalendar::sync($event->id);
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'target_version' => 2, 'status' => 'pending', 'synced_version' => null]);
    }

    public function test_google_cancel_deletes_event_and_retry_uses_same_id(): void
    {
        $this->connection();
        $a = $this->schedule();
        $event = DB::table('calendar_events')->first();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'access']), 'www.googleapis.com/*' => Http::sequence()->push([], 503)->push([], 404)]);
        GoogleCalendar::sync($event->id);
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'status' => 'retry', 'event_id' => $event->event_id]);
        $this->post('/agenda/'.$a->id.'/situacao', ['action' => 'cancel', 'lock_version' => 1])->assertSessionHasNoErrors();
        GoogleCalendar::sync($event->id);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), $event->event_id));
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'status' => 'synced', 'synced_version' => 2]);
    }

    public function test_oauth_state_is_single_use_and_tokens_are_encrypted(): void
    {
        $this->loginAs($this->owner)->post('/integracoes/google/conectar')->assertRedirect();
        $saved = session('google_oauth');
        $this->assertNotEmpty($saved['verifier']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['refresh_token' => 'refresh-private'], 200)]);
        $this->get('/integracoes/google/retorno?'.http_build_query(['state' => $saved['state'], 'code' => 'code-test']))->assertRedirect('/integracoes');
        $connection = DB::table('google_connections')->first();
        $this->assertSame('refresh-private', Crypt::decryptString($connection->refresh_token_ciphertext));
        $this->assertStringNotContainsString('refresh-private', $connection->refresh_token_ciphertext);
        $this->get('/integracoes/google/retorno?'.http_build_query(['state' => $saved['state'], 'code' => 'code-test']))->assertForbidden();
        $this->post('/integracoes/google/desconectar')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('google_connections', ['id' => $connection->id, 'active' => false, 'refresh_token_ciphertext' => '']);
    }

    public function test_reconnect_preserves_calendar_namespace(): void
    {
        $id = $this->connection();
        $this->loginAs($this->owner)->post('/integracoes/google/conectar');
        $state = session('google_oauth.state');
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['refresh_token' => 'new-refresh'])]);
        $this->get('/integracoes/google/retorno?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirect('/integracoes');
        $this->assertDatabaseCount('google_connections', 1);
        $this->assertDatabaseHas('google_connections', ['id' => $id, 'active' => true]);
    }

    public function test_meta_adapter_uses_approved_template_parameters(): void
    {
        config(['integrations.meta' => ['token' => 'secret', 'phone_id' => '123', 'version' => 'v25.0', 'template' => 'consultation_reminder', 'language' => 'pt_BR']]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'meta-1']]])]);
        $result = ReminderGateway::send('meta', '5511999999999', ['Paciente', 'Profissional', '21/09/2026', '10:00', '11999990000']);
        $this->assertSame('accepted', $result['status']);
        Http::assertSent(fn ($request) => $request['type'] === 'template' && count($request['template']['components'][0]['parameters']) === 5);
    }

    public function test_command_updates_heartbeat_without_sending_when_disabled(): void
    {
        $this->schedule();
        DB::table('integration_settings')->where('id', 1)->update(['whatsapp_enabled' => false, 'email_enabled' => false]);
        $this->travel(1)->hours();
        $this->artisan('psi:integrations')->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertNotNull(DB::table('integration_settings')->value('last_run_at'));
    }

    public function test_google_reconciles_changes_made_while_disconnected(): void
    {
        $connection = $this->connection();
        $a = $this->schedule();
        DB::table('google_connections')->where('id', $connection)->update(['active' => false]);
        $a->update(['schedule_version' => 2, 'status' => 'cancelled']);
        GoogleCalendar::plan($a->fresh());
        DB::table('google_connections')->where('id', $connection)->update(['active' => true]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'access']), 'www.googleapis.com/*' => Http::response([], 204)]);
        GoogleCalendar::run(microtime(true) + 5);
        $this->assertDatabaseHas('calendar_events', ['appointment_id' => $a->id, 'status' => 'synced', 'synced_version' => 2]);
    }

    public function test_oauth_rejects_expired_state_without_contacting_google(): void
    {
        $this->loginAs($this->owner)->withSession(['google_oauth' => ['state' => 'nonce', 'verifier' => 'test', 'calendar' => 'test', 'at' => time() - 601]])->get('/integracoes/google/retorno?state=nonce&code=x')->assertForbidden();
        Http::assertNothingSent();
    }
}
