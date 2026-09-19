<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:160']);
        $term = trim($data['q'] ?? '');
        $patients = Patient::when($term, fn ($query) => $query->where('full_name', 'like', '%'.addcslashes($term, '%_\\').'%'))->orderBy('full_name')->paginate(20)->withQueryString();

        return view('patients.index', compact('patients', 'term'));
    }

    public function create(): View
    {
        return view('patients.form', ['patient' => new Patient]);
    }

    public function edit(Patient $patient): View
    {
        return view('patients.form', compact('patient'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data) {
            $patient = new Patient($data);
            $patient->created_by = auth()->id();
            $patient->save();
            Audit::record('patients.created', 'patient', $patient->id);
        });

        return to_route('patients.index')->with('status', 'Paciente cadastrado.');
    }

    public function update(Request $request, Patient $patient): RedirectResponse
    {
        $data = $this->validated($request);
        $request->validate(['lock_version' => 'required|integer|min:1']);
        DB::transaction(function () use ($data, $patient, $request) {
            $record = Patient::lockForUpdate()->findOrFail($patient->id);
            if ($record->lock_version !== (int) $request->input('lock_version')) {
                throw ValidationException::withMessages(['patient' => 'O cadastro foi alterado. Reabra a página antes de salvar.']);
            }
            $record->fill($data);
            $record->lock_version++;
            $record->save();
            Audit::record('patients.updated', 'patient', $record->id);
        });

        return to_route('patients.index')->with('status', 'Cadastro atualizado. Consultas existentes foram preservadas.');
    }

    private function validated(Request $request): array
    {
        return $request->validate(['full_name' => 'required|string|max:160', 'birth_date' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'phone' => 'nullable|string|max:25', 'email' => 'nullable|email|max:254', 'guardian_name' => 'nullable|string|max:160', 'guardian_phone' => 'nullable|string|max:25', 'active' => 'required|boolean']);
    }
}
