@extends('layouts.app')
@section('title','Pacientes')
@section('subtitle','Cadastro administrativo e contatos do consultório.')
@section('content')
<div class="d-flex flex-wrap gap-3 justify-content-between mb-4">
    <form class="d-flex gap-2" method="get"><label for="q" class="visually-hidden">Buscar por nome</label><input class="form-control" id="q" name="q" value="{{ $term }}" placeholder="Buscar por nome" maxlength="160"><button class="btn btn-outline-primary">Buscar</button></form>
    <a class="btn btn-primary align-self-start" href="{{ route('patients.create') }}">Novo paciente</a>
</div>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Nome</th><th>Telefone</th><th>E-mail</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
@forelse($patients as $patient)
<tr><td class="fw-semibold">{{ $patient->full_name }}</td><td>{{ $patient->phone ?: '—' }}</td><td>{{ $patient->email ?: '—' }}</td><td><span class="badge {{ $patient->active?'text-bg-success':'text-bg-secondary' }}">{{ $patient->active?'Ativo':'Inativo' }}</span></td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('patients.edit',$patient) }}">Editar cadastro</a></td></tr>
@empty<tr><td colspan="5" class="text-center p-5 text-secondary">{{ $term?'Nenhum paciente encontrado para esta busca.':'Nenhum paciente cadastrado. Comece pelo botão Novo paciente.' }}</td></tr>@endforelse
</tbody></table></div><div class="card-footer">{{ $patients->links() }}</div></div>
@endsection
