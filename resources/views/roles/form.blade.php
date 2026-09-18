@extends('layouts.app')
@section('title',$role->exists?'Editar perfil':'Novo perfil')
@section('subtitle','Escolha as tarefas que esse perfil poderá realizar.')
@section('content')<div class="card form-card"><div class="card-body p-4"><form method="post" action="{{ $role->exists?route('roles.update',$role):route('roles.store') }}">@csrf @if($role->exists) @method('PUT') @endif
<label for="name" class="form-label">Nome do perfil</label><input class="form-control mb-4" id="name" name="name" maxlength="100" value="{{ old('name',$role->name) }}" required>
<fieldset><legend class="h6">Permissões operacionais</legend><p class="text-secondary small">As permissões abaixo serão usadas pelos módulos nas próximas etapas do desenvolvimento.</p>
@foreach($permissions as $permission)<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="permissions[]" id="permission-{{ $permission->id }}" value="{{ $permission->id }}" @checked(in_array($permission->id,old('permissions',session()->hasOldInput()?[]:$role->permissions->pluck('id')->all())))><label class="form-check-label" for="permission-{{ $permission->id }}">{{ $permission->name }}</label></div>@endforeach</fieldset>
<div class="alert alert-light border small">Prontuário, documentos clínicos, gestão financeira, integrações e administração são exclusivos do psicólogo e não podem ser delegados.</div><div class="d-flex gap-2 mt-4"><button class="btn btn-primary">Salvar perfil</button><a class="btn btn-light" href="{{ route('roles.index') }}">Cancelar</a></div></form></div></div>@endsection
