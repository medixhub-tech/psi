@extends('layouts.app')
@section('title','Prontuário')
@section('subtitle',$patient->full_name)
@section('content')
<div class="d-flex gap-2 mb-4"><a class="btn btn-light" href="{{ route('patients.index') }}">Pacientes</a><a class="btn btn-outline-primary" href="{{ route('documents.index',$patient) }}">Documentos do paciente</a></div>
<div class="card mb-4"><div class="card-body p-4"><h2 class="h5">Novo registro</h2><form method="post" action="{{ route('clinical.store',$patient) }}" autocomplete="off">@csrf
<label class="form-label" for="appointment_id">Consulta vinculada (opcional)</label><select class="form-select mb-3" id="appointment_id" name="appointment_id"><option value="">Registro sem consulta vinculada</option>@foreach($appointments as $appointment)<option value="{{ $appointment->id }}">{{ $appointment->starts_at->setTimezone(\App\Support\Agenda::timezone())->format('d/m/Y H:i') }}</option>@endforeach</select>
<label class="form-label" for="content">Anotação clínica</label><textarea class="form-control mb-3" id="content" name="content" rows="8" maxlength="50000" required></textarea>
<label class="form-label" for="status">Salvar como</label><select class="form-select mb-3" id="status" name="status"><option value="draft">Rascunho</option><option value="final">Finalizado</option></select><p class="small text-secondary">Cada salvamento preserva uma versão. Após finalizar, correções exigem motivo. Não há salvamento automático; em caso de erro, o texto não é guardado na sessão.</p><button class="btn btn-primary">Salvar registro</button>
</form></div></div>
<div class="card"><div class="card-body p-4"><h2 class="h5">Registros do paciente</h2>@forelse($entries as $entry)<div class="border-top py-3 d-flex flex-wrap justify-content-between gap-2"><span>Registro #{{ $entry->id }} · {{ \Carbon\CarbonImmutable::parse($entry->created_at,'UTC')->setTimezone(\App\Support\Agenda::timezone())->format('d/m/Y H:i') }} · Versão {{ $entry->current_version }}</span><a class="btn btn-sm btn-outline-primary" href="{{ route('clinical.show',[$patient,$entry->id]) }}">Abrir registro</a></div>@empty<p class="text-secondary">Nenhum registro clínico.</p>@endforelse{{ $entries->links() }}</div></div>
@endsection
