@extends('layouts.app')
@section('title','Empresas')
@section('subtitle','Cadastro de empresas do consultório.')
@section('content')
<div class="d-flex justify-content-between mb-4"><form class="d-flex gap-2"><label class="visually-hidden" for="q">Razão social</label><input class="form-control" id="q" name="q" value="{{ $term }}" maxlength="160" placeholder="Buscar razão social"><button class="btn btn-outline-primary">Buscar</button></form><a class="btn btn-primary" href="{{ route('companies.create') }}">Nova empresa</a></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Razão social</th><th>CNPJ</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
@forelse($companies as $company)<tr><td>{{ $company->legal_name }}</td><td>{{ $company->cnpj }}</td><td>{{ $company->active?'Ativa':'Inativa' }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('companies.edit',$company) }}">Editar</a></td></tr>
@empty<tr><td colspan="4" class="p-4 text-center">Nenhuma empresa encontrada.</td></tr>@endforelse
</tbody></table></div><div class="card-footer">{{ $companies->links() }}</div></div>
@endsection
