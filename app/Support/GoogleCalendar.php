<?php

namespace App\Support;

use App\Models\Appointment;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GoogleCalendar
{
    public static function configured(): bool
    {
        return filled(config('integrations.google.client_id')) && filled(config('integrations.google.client_secret')) && filled(config('integrations.google.calendar_id')) && config('integrations.google.calendar_id') !== 'primary';
    }

    public static function plan(Appointment $appointment): void
    {
        $connection = DB::table('google_connections')->where('active', true)->first();
        if (! $connection) {
            return;
        }
        $record = DB::table('calendar_events')->where('connection_id', $connection->id)->where('appointment_id', $appointment->id)->first();
        if (! $record) {
            DB::table('calendar_events')->insert(['connection_id' => $connection->id, 'appointment_id' => $appointment->id, 'event_id' => hash('sha256', $connection->namespace.':'.$appointment->id), 'target_version' => $appointment->schedule_version, 'status' => 'pending', 'available_at' => now()]);
        } elseif ((int) $record->target_version !== $appointment->schedule_version) {
            DB::table('calendar_events')->where('id', $record->id)->update(['target_version' => $appointment->schedule_version, 'status' => 'pending', 'available_at' => now(), 'attempts' => 0, 'error_code' => null]);
        }
    }

    public static function run(float $deadline): void
    {
        $connection = DB::table('google_connections')->where('active', true)->first();
        if (! $connection) {
            return;
        }
        $stale = DB::table('calendar_events')->join('appointments', 'appointments.id', '=', 'calendar_events.appointment_id')->where('connection_id', $connection->id)->whereColumn('target_version', '!=', 'appointments.schedule_version')->limit(100)->pluck('appointment_id');
        foreach ($stale as $id) {
            DB::transaction(function () use ($id) {
                DB::table('practice')->where('id', 1)->lockForUpdate()->first();
                self::plan(Appointment::findOrFail($id));
            });
        }
        $ids = DB::table('appointments')->where('starts_at', '>', now())->where('status', '!=', 'cancelled')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('calendar_events')->whereColumn('calendar_events.appointment_id', 'appointments.id')->where('connection_id', $connection->id))->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                DB::table('practice')->where('id', 1)->lockForUpdate()->first();
                self::plan(Appointment::findOrFail($id));
            });
        }
        $ids = DB::table('calendar_events')->where('connection_id', $connection->id)->whereIn('status', ['pending', 'retry'])->where('available_at', '<=', now())->orderBy('id')->limit(10)->pluck('id');
        foreach ($ids as $id) {
            if (microtime(true) > $deadline) {
                break;
            }self::sync($id);
        }
    }

    public static function sync(int $id): void
    {
        $snapshot = DB::transaction(function () use ($id) {
            DB::table('practice')->where('id', 1)->lockForUpdate()->first();
            $row = DB::table('calendar_events')->where('id', $id)->firstOrFail();
            $connection = DB::table('google_connections')->where('id', $row->connection_id)->where('active', true)->first();
            if (! $connection) {
                return null;
            }$appointment = Appointment::findOrFail($row->appointment_id);

            return [$row, $connection, $appointment];
        });
        if (! $snapshot) {
            return;
        }[$row,$connection,$appointment] = $snapshot;
        try {
            $tokenResponse = Http::asForm()->connectTimeout(3)->timeout(5)->withoutRedirecting()->post('https://oauth2.googleapis.com/token', ['client_id' => config('integrations.google.client_id'), 'client_secret' => config('integrations.google.client_secret'), 'refresh_token' => Crypt::decryptString($connection->refresh_token_ciphertext), 'grant_type' => 'refresh_token']);
            $token = $tokenResponse->json('access_token');
            if (! $tokenResponse->successful() || ! is_string($token) || $token === '') {
                self::result($row, 'error', 'oauth_refresh_failed');

                return;
            }
            $http = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(5)->withoutRedirecting();
            $base = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($connection->calendar_id).'/events';
            $url = $base.'/'.$row->event_id;
            if ($appointment->status === 'cancelled') {
                $response = $http->delete($url.'?sendUpdates=none');
                $ok = $response->successful() || in_array($response->status(), [404, 410], true);
            } else {
                $body = ['summary' => 'Consulta', 'visibility' => 'private', 'start' => ['dateTime' => $appointment->starts_at->toRfc3339String()], 'end' => ['dateTime' => $appointment->ends_at->toRfc3339String()], 'reminders' => ['useDefault' => false], 'extendedProperties' => ['private' => ['medpsico_version' => (string) $appointment->schedule_version]]];
                $response = $http->patch($url.'?sendUpdates=none', $body);
                if ($response->status() === 404) {
                    $response = $http->post($base.'?sendUpdates=none', $body + ['id' => $row->event_id]);
                    if ($response->status() === 409) {
                        $response = $http->patch($url.'?sendUpdates=none', $body);
                    }
                }
                $ok = $response->successful();
            }
            self::result($row, $ok ? 'synced' : (($response->serverError() || $response->status() === 429) ? 'retry' : 'error'), $ok ? null : 'http_'.$response->status());
        } catch (\Throwable) {
            self::result($row, 'retry', 'transport_uncertain');
        }
    }

    private static function result(object $row, string $status, ?string $error): void
    {
        $attempts = $row->attempts + 1;
        if ($status === 'retry' && $attempts >= 5) {
            $status = 'error';
        }
        $data = ['status' => $status, 'error_code' => $error, 'attempts' => $attempts, 'available_at' => now()->addMinutes(min(30, 2 ** min($attempts, 5)))];
        if ($status === 'synced') {
            $data['synced_version'] = $row->target_version;
        }
        DB::table('calendar_events')->where('id', $row->id)->where('target_version', $row->target_version)->update($data);
    }
}
