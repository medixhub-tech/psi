@extends('layouts.app')
@section('title',$patient->exists?'Editar paciente':'Novo paciente')
@section('subtitle','Dados administrativos. As anotações clínicas terão um espaço exclusivo do profissional.')
@section('content')
<div class="card form-card"><div class="card-body p-4"><form method="post" action="{{ $patient->exists?route('patients.update',$patient):route('patients.store') }}">@csrf
@if($patient->exists) @method('PUT')<input type="hidden" name="lock_version" value="{{ $patient->lock_version }}">@endif
<div class="row g-3">
<div class="col-md-8"><label class="form-label" for="full_name">Nome completo</label><input class="form-control" id="full_name" name="full_name" value="{{ old('full_name',$patient->full_name) }}" maxlength="160" required></div>
<div class="col-md-4"><label class="form-label" for="birth_date">Data de nascimento</label><input class="form-control" id="birth_date" name="birth_date" type="date" value="{{ old('birth_date',$patient->birth_date?->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}"></div>
<div class="col-md-6"><label class="form-label" for="phone">Telefone</label><input class="form-control" id="phone" name="phone" type="tel" value="{{ old('phone',$patient->phone) }}" maxlength="25"></div>
<div class="col-md-6"><label class="form-label" for="email">E-mail</label><input class="form-control" id="email" name="email" type="email" value="{{ old('email',$patient->email) }}" maxlength="254"></div>
<div class="col-md-6"><label class="form-label" for="guardian_name">Nome do responsável (opcional)</label><input class="form-control" id="guardian_name" name="guardian_name" value="{{ old('guardian_name',$patient->guardian_name) }}" maxlength="160"></div>
<div class="col-md-6"><label class="form-label" for="guardian_phone">Telefone do responsável</label><input class="form-control" id="guardian_phone" name="guardian_phone" type="tel" value="{{ old('guardian_phone',$patient->guardian_phone) }}" maxlength="25"></div>
<div class="col-md-6"><label class="form-label" for="active">Situação</label><select class="form-select" id="active" name="active"><option value="1" @selected(old('active',$patient->active)==1)>Ativo</option><option value="0" @selected(old('active',$patient->active)==0)>Inativo</option></select><div class="form-text">Inativar impede novos agendamentos e preserva o histórico.</div></div>
</div><div class="d-flex gap-2 mt-4"><button class="btn btn-primary">Salvar cadastro</button><a class="btn btn-light" href="{{ route('patients.index') }}">Cancelar</a></div>
</form></div></div>
@endsection
