<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Reminders
{
    public static function plan(Appointment $appointment): void
    {
        DB::table('reminders')->where('appointment_id', $appointment->id)->where('status', 'pending')->where(function ($q) use ($appointment) {
            $q->where('schedule_version', '!=', $appointment->schedule_version);
            if ($appointment->status !== 'scheduled') {
                $q->orWhereRaw('1=1');
            }
        })->update(['status' => 'cancelled', 'error_code' => 'schedule_changed']);
        if ($appointment->status !== 'scheduled') {
            return;
        }
        $due = $appointment->starts_at->subHours(24);
        foreach (['whatsapp', 'email'] as $channel) {
            DB::table('reminders')->insertOrIgnore(['appointment_id' => $appointment->id, 'schedule_version' => $appointment->schedule_version, 'channel' => $channel, 'scheduled_at' => $due, 'expires_at' => $due->addMinutes(5), 'status' => $due < now() ? 'expired' : 'pending', 'error_code' => $due < now() ? 'booked_after_target' : null]);
        }
    }

    public static function backfill(): void
    {
        $ids = DB::table('appointments')->where('status', 'scheduled')->where('starts_at', '>', now())->whereNotExists(fn ($q) => $q->selectRaw('1')->from('reminders')->whereColumn('reminders.appointment_id', 'appointments.id'))->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                DB::table('practice')->where('id', 1)->lockForUpdate()->first();
                $appointment = Appointment::find($id);
                if ($appointment) {
                    self::plan($appointment);
                }
            });
        }
    }

    public static function dispatch(int $id): void
    {
        $payload = DB::transaction(function () use ($id) {
            DB::table('practice')->where('id', 1)->lockForUpdate()->firstOrFail();
            $reminder = DB::table('reminders')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($reminder->status !== 'pending' || CarbonImmutable::parse($reminder->scheduled_at, 'UTC') > now()) {
                return null;
            }
            $fail = function (string $status, string $code) use ($id) {
                DB::table('reminders')->where('id', $id)->update(['status' => $status, 'error_code' => $code]);

                return null;
            };
            if (CarbonImmutable::parse($reminder->expires_at, 'UTC') < now()) {
                return $fail('expired', 'window_elapsed');
            }
            $appointment = Appointment::findOrFail($reminder->appointment_id);
            if ($appointment->schedule_version !== (int) $reminder->schedule_version || $appointment->status !== 'scheduled') {
                return $fail('cancelled', 'schedule_changed');
            }
            $settings = DB::table('integration_settings')->where('id', 1)->first();
            $enabled = $reminder->channel.'_enabled';
            if (! $settings->$enabled) {
                return null;
            }
            $patient = Patient::lockForUpdate()->findOrFail($appointment->patient_id);
            $preference = $reminder->channel.'_reminders';
            if (! $patient->active || ! $patient->$preference) {
                return $fail('cancelled', 'patient_not_authorized');
            }
            $destination = $reminder->channel === 'email' ? $patient->email : preg_replace('/[^0-9]/', '', $patient->phone ?? '');
            if ($reminder->channel === 'email' ? ! filter_var($destination, FILTER_VALIDATE_EMAIL) : ! preg_match('/^\+[1-9][0-9]{9,14}$/', $patient->phone ?? '')) {
                return $fail('failed', 'invalid_destination');
            }
            $practice = DB::table('practice')->where('id', 1)->first();
            $owner = DB::table('users')->where('id', $practice->owner_user_id)->first();
            $provider = $reminder->channel === 'email' ? 'smtp' : $settings->whatsapp_provider;
            if (! ReminderGateway::ready($provider)) {
                return $fail('failed', 'provider_not_configured');
            }
            $token = (string) Str::uuid();
            DB::table('reminders')->where('id', $id)->update(['status' => 'processing', 'provider' => $provider, 'lease_token' => $token, 'lease_until' => now()->addMinutes(2)]);
            $local = $appointment->starts_at->setTimezone(Agenda::timezone());

            return ['provider' => $provider, 'destination' => $destination, 'token' => $token, 'parts' => [explode(' ', trim($patient->full_name))[0], $owner->name, $local->format('d/m/Y'), $local->format('H:i'), $practice->contact_phone]];
        }, 3);
        if (! $payload) {
            return;
        }
        try {
            $result = ReminderGateway::send($payload['provider'], $payload['destination'], $payload['parts']);
        } catch (\Throwable) {
            $result = ['status' => 'unknown', 'error_code' => 'transport_uncertain'];
        }
        DB::table('reminders')->where('id', $id)->where('status', 'processing')->where('lease_token', $payload['token'])->update($result + ['accepted_at' => $result['status'] === 'accepted' ? now() : null, 'lease_until' => null]);
    }

    public static function run(float $deadline): void
    {
        self::backfill();
        DB::table('reminders')->where('status', 'processing')->where('lease_until', '<', now())->update(['status' => 'unknown', 'error_code' => 'worker_interrupted']);
        DB::table('reminders')->where('status', 'pending')->where('expires_at', '<', now())->update(['status' => 'expired', 'error_code' => 'window_elapsed']);
        $ids = DB::table('reminders')->where('status', 'pending')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            if (microtime(true) > $deadline) {
                break;
            }self::dispatch($id);
        }
    }
}
