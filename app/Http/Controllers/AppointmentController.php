<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use App\Support\Agenda;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function dashboard(): View
    {
        $visible = Agenda::mayView(auth()->user());
        $date = CarbonImmutable::now(Agenda::timezone())->toDateString();

        return view('dashboard', ['visible' => $visible, 'date' => $date, 'appointments' => $visible ? $this->appointments($date) : collect(), 'timezone' => Agenda::timezone()]);
    }

    public function queue(): View
    {
        abort_unless(Agenda::mayView(auth()->user()), 403);
        $date = CarbonImmutable::now(Agenda::timezone())->toDateString();

        return view('appointments.queue', ['appointments' => $this->appointments($date), 'timezone' => Agenda::timezone()]);
    }

    private function appointments(string $date): Collection
    {
        [$start,$end] = Agenda::window($date);

        return Appointment::with('patient')->where(function ($q) use ($start, $end) {
            $q->where('starts_at', '>=', $start)->where('starts_at', '<', $end);
        })->orWhere('status', 'in_progress')->orderBy('starts_at')->get();
    }

    public function index(Request $request): View
    {
        abort_unless(Agenda::mayView($request->user()), 403);
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $data['date'] ?? CarbonImmutable::now(Agenda::timezone())->toDateString();
        [$start,$end] = Agenda::window($date);

        return view('appointments.index', ['appointments' => Appointment::with('patient')->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->orderBy('starts_at')->get(), 'blocks' => DB::table('agenda_blocks')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get(), 'date' => $date, 'timezone' => Agenda::timezone()]);
    }

    public function create(): View
    {
        return $this->form(new Appointment);
    }

    public function edit(Appointment $appointment): View
    {
        abort_unless(in_array($appointment->status, ['scheduled', 'waiting'], true), 409);

        return $this->form($appointment);
    }

    private function form(Appointment $appointment): View
    {
        return view('appointments.form', ['appointment' => $appointment, 'patients' => Patient::where('active', true)->orWhere('id', $appointment->patient_id)->orderBy('full_name')->get(['id', 'full_name', 'active']), 'timezone' => Agenda::timezone()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $appointment = Agenda::save($this->validated($request));

        return to_route('appointments.index', ['date' => $appointment->starts_at->setTimezone(Agenda::timezone())->toDateString()])->with('status', 'Consulta agendada.');
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $request->validate(['lock_version' => 'required|integer|min:1']);
        $saved = Agenda::save($this->validated($request), $appointment);

        return to_route('appointments.index', ['date' => $saved->starts_at->setTimezone(Agenda::timezone())->toDateString()])->with('status', 'Consulta reagendada.');
    }

    public function transition(Request $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['arrive', 'no_show', 'call', 'finish', 'cancel'])], 'lock_version' => 'required|integer|min:1', 'reason' => 'nullable|string|max:255']);
        Agenda::transition($appointment, $data['action'], (int) $data['lock_version'], $data['reason'] ?? null);

        return back()->with('status', 'Situação da consulta atualizada.');
    }

    public function block(Request $request): RedirectResponse
    {
        $data = $request->validate(['starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i', 'label' => 'required|string|max:160']);
        Agenda::block($data);

        return back()->with('status', 'Horário bloqueado.');
    }

    public function unblock(int $block): RedirectResponse
    {
        Agenda::unblock($block);

        return back()->with('status', 'Bloqueio removido.');
    }

    private function validated(Request $request): array
    {
        return $request->validate(['patient_id' => 'required|integer|exists:patients,id', 'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i', 'modality' => ['required', Rule::in(['in_person', 'online'])], 'location' => 'nullable|string|max:255', 'lock_version' => 'sometimes|integer|min:1', 'reason' => 'nullable|string|max:255', 'confirm_waiting' => 'sometimes|accepted']);
    }
}
