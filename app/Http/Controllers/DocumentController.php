<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    public const CATEGORIES = ['report' => 'Relatório', 'consent' => 'Termo de consentimento', 'referral' => 'Encaminhamento', 'other' => 'Outro'];

    public function index(Patient $patient): View
    {
        $documents = DB::table('documents')->where('patient_id', $patient->id)->orderByDesc('id')->paginate(20);
        foreach ($documents as $document) {
            $document->name = Crypt::decryptString($document->original_name_ciphertext);
        }
        Audit::record('documents.listed', 'patient', $patient->id);

        return view('documents.index', ['patient' => $patient, 'documents' => $documents, 'appointments' => Appointment::where('patient_id', $patient->id)->orderByDesc('starts_at')->get(['id', 'starts_at'])]);
    }

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate(['appointment_id' => ['nullable', 'integer', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)], 'category' => ['required', Rule::in(array_keys(self::CATEGORIES))], 'document' => 'required|file|mimes:pdf,jpg,jpeg,png|extensions:pdf,jpg,jpeg,png|max:10240']);
        $file = $request->file('document');
        $bytes = $file->get();
        $mime = $file->getMimeType();
        abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true), 422);
        $path = (string) Str::uuid().'.enc';
        $disk = Storage::disk('clinical');
        try {
            $disk->put($path, Crypt::encryptString($bytes));
            DB::transaction(function () use ($patient, $data, $file, $bytes, $mime, $path) {
                $id = DB::table('documents')->insertGetId(['patient_id' => $patient->id, 'appointment_id' => $data['appointment_id'] ?? null, 'category' => $data['category'], 'storage_key' => $path, 'original_name_ciphertext' => Crypt::encryptString($file->getClientOriginalName()), 'encryption_key_id' => hash('sha256', config('app.key')), 'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'uploaded_by' => auth()->id(), 'created_at' => now(), 'lock_version' => 1]);
                Audit::record('documents.uploaded', 'document', $id);
            });
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return to_route('documents.index', $patient)->with('status', 'Documento privado salvo.');
    }

    public function download(Patient $patient, int $document): Response
    {
        $record = DB::table('documents')->where('patient_id', $patient->id)->where('id', $document)->firstOrFail();
        abort_if($record->archived_at !== null, 404);
        $disk = Storage::disk('clinical');
        abort_unless($disk->exists($record->storage_key), 404);
        $bytes = Crypt::decryptString($disk->get($record->storage_key));
        abort_unless(hash_equals($record->sha256, hash('sha256', $bytes)), 409, 'Falha de integridade do documento.');
        $extension = match ($record->mime_type) {
            'application/pdf' => 'pdf','image/jpeg' => 'jpg','image/png' => 'png'
        };
        Audit::record('documents.downloaded', 'document', $document);

        return response($bytes, 200, ['Content-Type' => $record->mime_type, 'Content-Disposition' => 'attachment; filename="documento-'.$document.'.'.$extension.'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function archive(Request $request, Patient $patient, int $document): RedirectResponse
    {
        $data = $request->validate(['lock_version' => 'required|integer|min:1', 'archived' => 'required|boolean']);
        DB::transaction(function () use ($patient, $document, $data) {
            $record = DB::table('documents')->where('patient_id', $patient->id)->where('id', $document)->lockForUpdate()->firstOrFail();
            if ((int) $record->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['document' => 'O documento mudou. Reabra a página.']);
            }
            DB::table('documents')->where('id', $document)->update(['archived_at' => $data['archived'] ? now() : null, 'lock_version' => $record->lock_version + 1]);
            Audit::record($data['archived'] ? 'documents.archived' : 'documents.restored', 'document', $document);
        }, 3);

        return to_route('documents.index', $patient)->with('status', 'Situação do documento atualizada.');
    }
}
