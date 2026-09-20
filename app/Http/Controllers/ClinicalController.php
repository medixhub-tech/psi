<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicalController extends Controller
{
    public function index(Patient $patient): View
    {
        $entries = DB::table('clinical_entries')->where('patient_id', $patient->id)->orderByDesc('id')->paginate(20);
        Audit::record('clinical.listed', 'patient', $patient->id);

        return view('clinical.index', ['patient' => $patient, 'entries' => $entries, 'appointments' => Appointment::where('patient_id', $patient->id)->orderByDesc('starts_at')->get(['id', 'starts_at'])]);
    }

    public function show(Patient $patient, int $entry): View
    {
        $record = DB::table('clinical_entries')->where('patient_id', $patient->id)->where('id', $entry)->firstOrFail();
        $versions = DB::table('clinical_entry_versions')->where('entry_id', $entry)->orderByDesc('version')->get()->map(function ($v) {
            $v->content = Crypt::decryptString($v->content_ciphertext);
            $v->reason = $v->reason_ciphertext ? Crypt::decryptString($v->reason_ciphertext) : null;

            return $v;
        });
        Audit::record('clinical.read', 'clinical_entry', $entry);

        return view('clinical.show', ['patient' => $patient, 'entry' => $record, 'versions' => $versions]);
    }

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate(['appointment_id' => ['nullable', 'integer', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)], 'content' => 'required|string|max:50000', 'status' => ['required', Rule::in(['draft', 'final'])]]);
        $id = DB::transaction(function () use ($patient, $data) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $id = DB::table('clinical_entries')->insertGetId(['patient_id' => $patient->id, 'appointment_id' => $data['appointment_id'] ?? null, 'author_id' => auth()->id(), 'current_version' => 1, 'created_at' => now()]);
            $this->appendVersion($id, 1, $data);
            Audit::record('clinical.created', 'clinical_entry', $id);

            return $id;
        });

        return to_route('clinical.show', [$patient, $id])->with('status', 'Registro salvo.');
    }

    public function revise(Request $request, Patient $patient, int $entry): RedirectResponse
    {
        $data = $request->validate(['content' => 'required|string|max:50000', 'status' => ['required', Rule::in(['draft', 'final'])], 'amendment_reason' => 'nullable|string|max:255', 'current_version' => 'required|integer|min:1']);
        DB::transaction(function () use ($patient, $entry, $data) {
            $record = DB::table('clinical_entries')->where('patient_id', $patient->id)->where('id', $entry)->lockForUpdate()->firstOrFail();
            if ((int) $record->current_version !== (int) $data['current_version']) {
                throw ValidationException::withMessages(['record' => 'O registro mudou. Reabra a página antes de salvar.']);
            }
            $hasFinal = DB::table('clinical_entry_versions')->where('entry_id', $entry)->where('status', 'final')->exists();
            if ($hasFinal && empty($data['amendment_reason'])) {
                throw ValidationException::withMessages(['amendment_reason' => 'Informe o motivo da correção de um registro finalizado.']);
            }
            $version = $record->current_version + 1;
            $this->appendVersion($entry, $version, $data);
            DB::table('clinical_entries')->where('id', $entry)->update(['current_version' => $version]);
            Audit::record('clinical.revised', 'clinical_entry', $entry);
        }, 3);

        return to_route('clinical.show', [$patient, $entry])->with('status', 'Nova revisão salva. As versões anteriores foram preservadas.');
    }

    private function appendVersion(int $entry, int $version, array $data): void
    {
        DB::table('clinical_entry_versions')->insert(['entry_id' => $entry, 'version' => $version, 'content_ciphertext' => Crypt::encryptString($data['content']), 'reason_ciphertext' => empty($data['amendment_reason']) ? null : Crypt::encryptString($data['amendment_reason']), 'encryption_key_id' => hash('sha256', config('app.key')), 'status' => $data['status'], 'author_id' => auth()->id(), 'created_at' => now(), 'finalized_at' => $data['status'] === 'final' ? now() : null]);
    }
}
