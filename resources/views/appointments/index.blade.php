@extends('layouts.app')
@section('title','Agenda')
@section('subtitle','Organize as consultas e acompanhe a rotina do consultório.')
@section('content')
<div class="d-flex flex-wrap justify-content-between gap-3 mb-4"><form class="d-flex gap-2" method="get"><label for="date" class="visually-hidden">Dia da agenda</label><input class="form-control" id="date" name="date" type="date" value="{{ $date }}" required><button class="btn btn-outline-primary">Consultar</button></form>@can('appointments.manage')<a class="btn btn-primary align-self-start" href="{{ route('appointments.create') }}">Nova consulta</a>@endcan</div>
<p class="small text-secondary">Horários no fuso {{ $timezone }}.</p><div class="card">@include('appointments.table')</div>
@can('appointments.manage')
<div class="card mt-4"><div class="card-body p-4"><h2 class="h5">Horários indisponíveis</h2>
@forelse($blocks as $block)<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom py-3 gap-2"><div>{{ $block->label }} · {{ \Carbon\CarbonImmutable::parse($block->starts_at,'UTC')->setTimezone($timezone)->format('d/m H:i') }} — {{ \Carbon\CarbonImmutable::parse($block->ends_at,'UTC')->setTimezone($timezone)->format('d/m H:i') }}</div><form method="post" action="{{ route('blocks.destroy',$block->id) }}" data-confirm="Liberar este horário na agenda?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-secondary">Liberar horário</button></form></div>@empty<p class="text-secondary">Nenhum bloqueio neste dia.</p>@endforelse
<details class="mt-3"><summary class="text-primary">Adicionar bloqueio</summary><form method="post" action="{{ route('blocks.store') }}" class="row g-3 mt-1">@csrf<div class="col-md-4"><label class="form-label" for="label">Descrição administrativa</label><input class="form-control" id="label" name="label" required maxlength="160" placeholder="Ex.: intervalo"></div><div class="col-md-3"><label class="form-label" for="block-start">Início</label><input class="form-control" id="block-start" name="starts_at" type="datetime-local" required></div><div class="col-md-3"><label class="form-label" for="block-end">Fim</label><input class="form-control" id="block-end" name="ends_at" type="datetime-local" required></div><div class="col-md-2 align-self-end"><button class="btn btn-outline-primary">Bloquear</button></div></form></details>
</div></div>@endcan
@endsection
