@extends('layouts.app')
@section('title',$company->exists?'Editar empresa':'Nova empresa')
@section('subtitle','Dados cadastrais da empresa e endereço.')
@section('content')
<div class="card form-card"><div class="card-body p-4"><form method="post" action="{{ $company->exists?route('companies.update',$company):route('companies.store') }}">@csrf
@if($company->exists) @method('PUT')<input type="hidden" name="lock_version" value="{{ $company->lock_version }}">@endif
<div class="row g-3">
@foreach(['cnpj'=>'CNPJ','legal_name'=>'Razão social','trade_name'=>'Nome fantasia','phone'=>'Telefone','email'=>'E-mail'] as $field=>$label)
<div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control" id="{{ $field }}" name="{{ $field }}" type="{{ $field==='email'?'email':'text' }}" maxlength="{{ $field==='cnpj'?18:($field==='email'?254:($field==='phone'?25:160)) }}" value="{{ old($field,$company->$field) }}" @required(in_array($field,['cnpj','legal_name']))></div>
@endforeach
@include('partials.address',['record'=>$company])
<div class="col-md-6"><label class="form-label" for="active">Situação</label><select class="form-select" name="active" id="active"><option value="1" @selected(old('active',$company->active))>Ativa</option><option value="0" @selected(!old('active',$company->active))>Inativa</option></select></div>
</div><div class="mt-4 d-flex gap-2"><button class="btn btn-primary">Salvar empresa</button><a class="btn btn-light" href="{{ route('companies.index') }}">Cancelar</a></div>
</form></div></div>
@endsection
