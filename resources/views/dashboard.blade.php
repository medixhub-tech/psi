@extends('layouts.app')
@section('title','Painel do consultório')
@section('subtitle','Acompanhe as chegadas e os atendimentos de hoje.')
@section('content')
@if($visible)<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><span class="text-secondary">{{ \Carbon\CarbonImmutable::parse($date)->format('d/m/Y') }} · {{ $timezone }}</span><a class="btn btn-outline-primary" href="{{ route('appointments.index') }}">Abrir agenda</a></div><p id="queue-status" class="small text-secondary" role="status">Atualização automática a cada 15 segundos.</p><div id="live-queue" data-url="{{ route('appointments.queue') }}">@include('appointments.queue')</div>
@else<div class="card"><div class="card-body p-4"><h2 class="h5">Olá, {{ explode(' ',auth()->user()->name)[0] }}.</h2><p class="text-secondary mb-0">Seu acesso está ativo. Solicite ao profissional as permissões necessárias para suas atividades.</p></div></div>@endif
@can('users.manage')<div class="d-flex gap-3 mt-4"><a href="{{ route('users.index') }}">Gerenciar usuários</a><a href="{{ route('roles.index') }}">Perfis de acesso</a></div>@endcan
@endsection
