<?php

namespace App\Http\Controllers;

use App\Models\Charge;
use App\Models\ServiceType;
use App\Support\Agenda;
use App\Support\Audit;
use App\Support\Billing;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    private function amountRule(): array
    {
        return ['required', 'string', 'regex:/^[0-9]{1,8}([.,][0-9]{1,2})?$/D'];
    }

    public function today(): View
    {
        Gate::authorize('billing.today');
        $date = CarbonImmutable::now(Agenda::timezone())->toDateString();
        [$start,$end] = Agenda::window($date);
        $charges = Charge::with('appointment.patient')->whereHas('appointment', fn ($q) => $q->where('starts_at', '>=', $start)->where('starts_at', '<', $end))->orderBy('id')->get();

        return view('billing.today', compact('charges', 'date'));
    }

    public function show(Charge $charge): View
    {
        Billing::authorize($charge->appointment);
        $charge->load('appointment.patient');
        $owner = auth()->user()->hasPermission('finance.manage');
        $payments = $owner ? DB::table('payments')->leftJoin('payment_reversals', 'payment_reversals.payment_id', '=', 'payments.id')->where('payments.charge_id', $charge->id)->select('payments.*', 'payment_reversals.id as reversal_id', 'payment_reversals.reason as reversal_reason')->orderBy('payments.id')->get() : collect();
        $history = $owner ? DB::table('charge_history')->where('charge_id', $charge->id)->orderByDesc('id')->get() : collect();

        return view('billing.show', compact('charge', 'payments', 'owner', 'history'));
    }

    public function receive(Request $request, Charge $charge): RedirectResponse
    {
        Billing::authorize($charge->appointment);
        $data = $request->validate(['amount' => $this->amountRule(), 'method' => ['required', Rule::in(array_keys(Billing::METHODS))], 'idempotency_key' => 'required|uuid', 'lock_version' => 'required|integer|min:1']);
        Billing::receive($charge->id, $data);

        return to_route('billing.show', $charge)->with('status', 'Recebimento registrado.');
    }

    public function adjust(Request $request, Charge $charge): RedirectResponse
    {
        $data = $request->validate(['amount' => $this->amountRule(), 'status' => ['required', Rule::in(['open', 'waived', 'void'])], 'reason' => 'required|string|max:255', 'lock_version' => 'required|integer|min:1']);
        Billing::adjust($charge->id, $data);

        return to_route('billing.show', $charge)->with('status', 'Cobrança ajustada.');
    }

    public function reverse(Request $request, Charge $charge, int $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        Billing::reverse($charge->id, $payment, $data['reason']);

        return to_route('billing.show', $charge)->with('status', 'Estorno integral registrado.');
    }

    public function types(): View
    {
        return view('billing.types', ['types' => ServiceType::orderBy('name')->get()]);
    }

    public function saveType(Request $request, ?ServiceType $type = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'amount' => $this->amountRule(), 'active' => 'required|boolean', 'lock_version' => ($type ? 'required' : 'sometimes').'|integer|min:1']);
        DB::transaction(function () use ($data, $type) {
            DB::table('practice')->where('id', 1)->lockForUpdate()->firstOrFail();
            $record = $type ? ServiceType::lockForUpdate()->findOrFail($type->id) : new ServiceType;
            if ($type && $record->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['type' => 'O atendimento mudou. Reabra a página.']);
            }
            $record->fill(['name' => $data['name'], 'amount' => Money::decimal(Money::cents($data['amount'])), 'active' => $data['active'], 'lock_version' => $type ? $record->lock_version + 1 : 1]);
            $record->save();
            Audit::record($type ? 'service_types.updated' : 'service_types.created', 'service_type', $record->id);
        });

        return to_route('finance.types')->with('status', 'Tipo de atendimento salvo. Consultas anteriores preservam o valor acordado.');
    }

    public function index(Request $request): View
    {
        $today = CarbonImmutable::now(Agenda::timezone());
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = $data['from'] ?? $today->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today->endOfMonth()->toDateString();
        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            throw ValidationException::withMessages(['period' => 'Selecione um período de até 366 dias, com fim igual ou posterior ao início.']);
        }
        [$start] = Agenda::window($from);
        [, $end] = Agenda::window($to);
        $received = Money::cents((string) DB::table('payments')->where('received_at', '>=', $start)->where('received_at', '<', $end)->sum('amount'));
        $reversed = Money::cents((string) DB::table('payment_reversals')->where('created_at', '>=', $start)->where('created_at', '<', $end)->sum('amount'));
        $query = Charge::with('appointment.patient')->where('due_on', '>=', $from)->where('due_on', '<', CarbonImmutable::parse($to)->addDay()->toDateString());
        $outstanding = 0;
        $unpriced = 0;
        foreach ((clone $query)->lazyById(100) as $charge) {
            if ($charge->status === 'open') {
                if ($charge->amount === null) {
                    $unpriced++;
                } else {
                    $outstanding += $charge->balanceCents();
                }
            }
        }
        $charges = $query->orderByDesc('due_on')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('billing.index', compact('from', 'to', 'received', 'reversed', 'outstanding', 'unpriced', 'charges'));
    }
}
