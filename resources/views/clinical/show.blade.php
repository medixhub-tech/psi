@extends('layouts.app')
@section('title','Registro clínico #'.$entry->id)
@section('subtitle',$patient->full_name)
@section('content')
<a class="btn btn-light mb-4" href="{{ route('clinical.index',$patient) }}">Voltar ao prontuário</a>
@php($latest=$versions->first())
<div class="card mb-4"><div class="card-body p-4"><h2 class="h5">Nova revisão</h2><form method="post" action="{{ route('clinical.revise',[$patient,$entry->id]) }}" autocomplete="off">@csrf<input type="hidden" name="current_version" value="{{ $entry->current_version }}">
<label class="form-label" for="content">Anotação clínica</label><textarea class="form-control mb-3" name="content" id="content" rows="8" maxlength="50000" required>{{ $latest->content }}</textarea>
<label class="form-label" for="status">Salvar como</label><select class="form-select mb-3" id="status" name="status"><option value="draft">Rascunho</option><option value="final">Finalizado</option></select>
<label class="form-label" for="amendment_reason">Motivo da correção {{ $versions->contains('status','final')?'(obrigatório)':'(opcional)' }}</label><input class="form-control mb-3" id="amendment_reason" name="amendment_reason" maxlength="255" @required($versions->contains('status','final'))>
<p class="small text-secondary">As versões anteriores permanecem preservadas. Em caso de erro, o texto não é guardado na sessão.</p><button class="btn btn-primary">Salvar nova revisão</button></form></div></div>
<h2 class="h5 mb-3">Histórico de versões</h2>
@foreach($versions as $version)<div class="card mb-3"><div class="card-body p-4"><h3 class="h6">Versão {{ $version->version }} · {{ $version->status==='final'?'Finalizado':'Rascunho' }}</h3><p class="small text-secondary">{{ \Carbon\CarbonImmutable::parse($version->created_at,'UTC')->setTimezone(\App\Support\Agenda::timezone())->format('d/m/Y H:i') }}</p><div style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $version->content }}</div>@if($version->reason)<p class="mt-3 mb-0">Motivo: {{ $version->reason }}</p>@endif</div></div>@endforeach
@endsection
