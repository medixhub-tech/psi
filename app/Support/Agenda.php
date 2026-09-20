<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Agenda
{
    public static function timezone(): string
    {
        return DB::table('practice')->where('id', 1)->value('timezone') ?? 'America/Sao_Paulo';
    }

    public static function mayView(User $user): bool
    {
        return $user->hasPermission('appointments.manage') || $user->hasPermission('attendance.record') || $user->hasPermission('attendance.treat');
    }

    public static function window(string $date): array
    {
        $start = CarbonImmutable::parse($date, self::timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    public static function interval(array $data): array
    {
        $tz = self::timezone();
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], $tz);
        $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['ends_at'], $tz);
        if ($end <= $start || $end->diffInHours($start, true) > 12) {
            self::invalid('Informe um intervalo válido de até 12 horas.');
        }

        return [$start->utc(), $end->utc()];
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['agenda' => $message]);
    }

    private static function lockPractice(): void
    {
        abort_unless(DB::table('practice')->where('id', 1)->lockForUpdate()->first(), 409, 'Configure o profissional antes de usar a agenda.');
    }

    private static function available(CarbonImmutable $start, CarbonImmutable $end, ?int $except = null): void
    {
        $overlap = Appointment::whereNotIn('status', ['cancelled', 'no_show'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->when($except, fn ($query) => $query->where('id', '!=', $except))->exists();
        $blocked = DB::table('agenda_blocks')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
        if ($overlap || $blocked) {
            self::invalid('Este horário está ocupado ou bloqueado. Escolha outro intervalo.');
        }
    }

    private static function snapshot(Appointment $appointment): array
    {
        return $appointment->only(['patient_id', 'starts_at', 'ends_at', 'timezone', 'status', 'modality', 'location', 'arrived_at', 'called_at', 'finished_at', 'lock_version', 'schedule_version']);
    }

    private static function history(Appointment $appointment, string $action, ?array $before = null, ?string $reason = null): void
    {
        DB::table('appointment_history')->insert(['appointment_id' => $appointment->id, 'actor_id' => auth()->id(), 'action' => $action, 'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after_snapshot' => json_encode(self::snapshot($appointment), JSON_THROW_ON_ERROR), 'reason' => $reason, 'created_at' => now()]);
        Audit::record('appointments.'.$action, 'appointment', $appointment->id);
    }

    public static function save(array $data, ?Appointment $existing = null): Appointment
    {
        Gate::authorize('appointments.manage');

        return DB::transaction(function () use ($data, $existing) {
            self::lockPractice();
            $appointment = $existing ? Appointment::lockForUpdate()->findOrFail($existing->id) : new Appointment;
            $before = $existing ? self::snapshot($appointment) : null;
            if ($existing && $appointment->lock_version !== (int) $data['lock_version']) {
                self::invalid('A consulta foi alterada. Atualize a página antes de salvar.');
            }
            if (! in_array($appointment->status, ['scheduled', 'waiting'], true)) {
                self::invalid('Esta consulta não pode mais ser reagendada.');
            }
            if ($appointment->status === 'waiting' && empty($data['confirm_waiting'])) {
                self::invalid('Confirme o reagendamento do paciente que já está aguardando.');
            }
            $patient = Patient::lockForUpdate()->findOrFail($data['patient_id']);
            if (! $patient->active) {
                self::invalid('O paciente está inativo. Reative o cadastro antes de agendar.');
            }
            if ($existing && $appointment->patient_id !== $patient->id) {
                self::invalid('Não é permitido trocar o paciente. Cancele e crie outra consulta.');
            }
            [$start,$end] = self::interval($data);
            if ($start <= now()) {
                self::invalid('O início da consulta deve estar no futuro.');
            }
            self::available($start, $end, $existing?->id);
            $appointment->fill(['patient_id' => $patient->id, 'starts_at' => $start, 'ends_at' => $end, 'timezone' => self::timezone(), 'modality' => $data['modality'], 'location' => $data['location'] ?? null, 'updated_by' => auth()->id(), 'status' => 'scheduled', 'arrived_at' => null]);
            if ($existing) {
                $appointment->lock_version++;
                $appointment->schedule_version++;
            } else {
                $appointment->created_by = auth()->id();
            }
            $fee = null;
            if (! $existing) {
                $type = ServiceType::where('active', true)->lockForUpdate()->find($data['service_type_id'] ?? null);
                if (! $type) {
                    self::invalid('Selecione um tipo de atendimento ativo cadastrado pelo profissional.');
                }
                $appointment->service_type_id = $type->id;
                $appointment->service_name = $type->name;
                $fee = $type->amount;
            }
            $appointment->save();
            Billing::syncAppointment($appointment, $fee);
            Reminders::plan($appointment);
            GoogleCalendar::plan($appointment);
            self::history($appointment, $existing ? 'rescheduled' : 'created', $before, $data['reason'] ?? null);

            return $appointment;
        }, 3);
    }

    public static function transition(Appointment $existing, string $action, int $version, ?string $reason = null): void
    {
        $permission = match ($action) {
            'arrive','no_show' => 'attendance.record','call','finish' => 'attendance.treat','cancel' => 'appointments.manage',default => abort(422)
        };
        Gate::authorize($permission);
        DB::transaction(function () use ($existing, $action, $version, $reason) {
            self::lockPractice();
            $appointment = Appointment::lockForUpdate()->findOrFail($existing->id);
            if ($appointment->lock_version !== $version) {
                self::invalid('A consulta foi alterada. Atualize a página.');
            }
            $before = self::snapshot($appointment);
            $allowed = match ($action) {
                'arrive','no_show' => ['scheduled'],'call' => ['waiting'],'finish' => ['in_progress'],'cancel' => ['scheduled', 'waiting']
            };
            if (! in_array($appointment->status, $allowed, true)) {
                self::invalid('Esta ação não é permitida para a situação atual.');
            }
            if (in_array($action, ['arrive', 'call', 'no_show'], true) && $appointment->starts_at->setTimezone(self::timezone())->toDateString() !== CarbonImmutable::now(self::timezone())->toDateString()) {
                self::invalid('Esta ação só é permitida nas consultas de hoje.');
            }
            if ($action === 'no_show' && $appointment->starts_at->isFuture()) {
                self::invalid('A falta só pode ser registrada após o horário previsto.');
            }
            if ($action === 'call' && Appointment::where('status', 'in_progress')->exists()) {
                self::invalid('Conclua o atendimento atual antes de chamar outro paciente.');
            }
            $appointment->status = match ($action) {
                'arrive' => 'waiting','call' => 'in_progress','finish' => 'completed','no_show' => 'no_show','cancel' => 'cancelled'
            };
            if ($action === 'arrive') {
                $appointment->arrived_at = now();
            }
            if ($action === 'call') {
                $appointment->called_at = now();
            }
            if ($action === 'finish') {
                $appointment->finished_at = now();
            }
            if ($action === 'cancel') {
                $appointment->schedule_version++;
            }
            $appointment->updated_by = auth()->id();
            $appointment->lock_version++;
            $appointment->save();
            Reminders::plan($appointment);
            if ($action === 'cancel') {
                GoogleCalendar::plan($appointment);
            }
            self::history($appointment, $action, $before, $reason);
        }, 3);
    }

    public static function block(array $data): void
    {
        Gate::authorize('appointments.manage');
        DB::transaction(function () use ($data) {
            self::lockPractice();
            [$start,$end] = self::interval($data);
            if ($start <= now()) {
                self::invalid('O bloqueio deve iniciar no futuro.');
            }
            self::available($start, $end);
            $id = DB::table('agenda_blocks')->insertGetId(['starts_at' => $start, 'ends_at' => $end, 'label' => $data['label'], 'created_by' => auth()->id(), 'created_at' => now()]);
            Audit::record('agenda.blocked', 'agenda_block', $id);
        }, 3);
    }

    public static function unblock(int $id): void
    {
        Gate::authorize('appointments.manage');
        DB::transaction(function () use ($id) {
            self::lockPractice();
            abort_unless(DB::table('agenda_blocks')->where('id', $id)->exists(), 404);
            DB::table('agenda_blocks')->where('id', $id)->delete();
            Audit::record('agenda.unblocked', 'agenda_block', $id);
        }, 3);
    }
}
