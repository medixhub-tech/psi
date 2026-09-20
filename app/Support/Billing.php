<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Charge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Billing
{
    public const METHODS = ['cash' => 'Dinheiro', 'pix' => 'Pix', 'debit_card' => 'Cartão de débito', 'credit_card' => 'Cartão de crédito', 'bank_transfer' => 'Transferência', 'other' => 'Outro'];

    public static function authorize(Appointment $appointment): void
    {
        if (auth()->user()->hasPermission('finance.manage')) {
            return;
        }
        Gate::authorize('billing.today');
        abort_unless($appointment->starts_at->setTimezone(Agenda::timezone())->toDateString() === CarbonImmutable::now(Agenda::timezone())->toDateString(), 403);
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['billing' => $message]);
    }

    private static function lock(int $id): Charge
    {
        DB::table('practice')->where('id', 1)->lockForUpdate()->firstOrFail();
        $appointmentId = Charge::whereKey($id)->value('appointment_id');
        $appointment = Appointment::lockForUpdate()->findOrFail($appointmentId);
        self::authorize($appointment);

        return Charge::lockForUpdate()->findOrFail($id);
    }

    private static function version(Charge $charge, int $version): void
    {
        if ($charge->lock_version !== $version) {
            self::invalid('A cobrança mudou. Reabra a página antes de continuar.');
        }
    }

    private static function history(Charge $charge, string $action, ?array $before = null, ?string $reason = null): void
    {
        DB::table('charge_history')->insert(['charge_id' => $charge->id, 'actor_id' => auth()->id(), 'action' => $action, 'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after_snapshot' => json_encode($charge->only(['amount', 'status', 'due_on', 'lock_version']), JSON_THROW_ON_ERROR), 'reason' => $reason, 'created_at' => now()]);
        Audit::record('billing.'.$action, 'charge', $charge->id);
    }

    public static function syncAppointment(Appointment $appointment, ?string $fee = null): void
    {
        $charge = Charge::where('appointment_id', $appointment->id)->lockForUpdate()->first();
        if (! $charge) {
            $charge = Charge::create(['appointment_id' => $appointment->id, 'amount' => $fee, 'due_on' => $appointment->starts_at->setTimezone(Agenda::timezone())->toDateString(), 'status' => 'open', 'lock_version' => 1, 'created_by' => auth()->id()]);
            self::history($charge, 'created');

            return;
        }
        $before = $charge->only(['amount', 'status', 'due_on', 'lock_version']);
        $charge->due_on = $appointment->starts_at->setTimezone(Agenda::timezone())->toDateString();
        $charge->lock_version++;
        $charge->save();
        self::history($charge, 'rescheduled', $before);
    }

    public static function receive(int $id, array $data): void
    {
        DB::transaction(function () use ($id, $data) {
            $charge = self::lock($id);
            $amount = Money::cents($data['amount']);
            $key = strtolower($data['idempotency_key']);
            $previous = DB::table('payments')->where('idempotency_key', $key)->first();
            if ($previous) {
                abort_unless((int) $previous->charge_id === $id && (int) $previous->recorded_by === auth()->id() && Money::cents((string) $previous->amount) === $amount && $previous->method === $data['method'], 409, 'Chave de recebimento já utilizada com outros dados.');

                return;
            }
            self::version($charge, (int) $data['lock_version']);
            if ($charge->status !== 'open' || $charge->amount === null) {
                self::invalid('O profissional precisa definir uma cobrança aberta antes do recebimento.');
            }
            if ($amount <= 0 || $amount > $charge->balanceCents()) {
                self::invalid('O recebimento deve ser positivo e não pode ultrapassar o saldo.');
            }
            $payment = DB::table('payments')->insertGetId(['charge_id' => $charge->id, 'amount' => Money::decimal($amount), 'method' => $data['method'], 'received_at' => now(), 'recorded_by' => auth()->id(), 'idempotency_key' => $key, 'created_at' => now()]);
            $charge->lock_version++;
            $charge->save();
            Audit::record('payments.recorded', 'payment', $payment);
        }, 3);
    }

    public static function adjust(int $id, array $data): void
    {
        Gate::authorize('finance.manage');
        DB::transaction(function () use ($id, $data) {
            $charge = self::lock($id);
            self::version($charge, (int) $data['lock_version']);
            $amount = Money::cents($data['amount']);
            $net = $charge->netCents();
            if ($amount < $net || ($data['status'] !== 'open' && $net !== 0)) {
                self::invalid('Estorne os recebimentos antes de reduzir abaixo do recebido, dispensar ou anular.');
            }
            $before = $charge->only(['amount', 'status', 'due_on', 'lock_version']);
            $charge->fill(['amount' => Money::decimal($amount), 'status' => $data['status']]);
            $charge->lock_version++;
            $charge->save();
            self::history($charge, 'adjusted', $before, $data['reason']);
        }, 3);
    }

    public static function reverse(int $id, int $paymentId, string $reason): void
    {
        Gate::authorize('finance.manage');
        DB::transaction(function () use ($id, $paymentId, $reason) {
            $charge = self::lock($id);
            $payment = DB::table('payments')->where('charge_id', $id)->where('id', $paymentId)->firstOrFail();
            if (DB::table('payment_reversals')->where('payment_id', $paymentId)->exists()) {
                return;
            }
            $reversal = DB::table('payment_reversals')->insertGetId(['payment_id' => $paymentId, 'amount' => $payment->amount, 'reason' => $reason, 'recorded_by' => auth()->id(), 'created_at' => now()]);
            $charge->lock_version++;
            $charge->save();
            Audit::record('payments.reversed', 'payment_reversal', $reversal);
        }, 3);
    }
}
