@php
$waiting = $appointments->where('status','waiting')->sortBy('arrived_at');
$current = $appointments->where('status','in_progress');
@endphp
<div class="row g-4 mb-4"><div class="col-lg-6"><div class="card h-100"><div class="card-body p-4"><h2 class="h5">Aguardando <span class="badge text-bg-warning">{{ $waiting->count() }}</span></h2>
@forelse($waiting as $appointment)<div class="border-top py-3"><p class="fw-semibold mb-1">{{ $appointment->patient->full_name }}</p><p class="small text-secondary">Chegada às {{ $appointment->arrived_at?->setTimezone($timezone)->format('H:i') }}</p>@include('appointments.actions')</div>@empty<p class="text-secondary mb-0 mt-3">Nenhum paciente na fila de espera.</p>@endforelse
</div></div></div><div class="col-lg-6"><div class="card h-100"><div class="card-body p-4"><h2 class="h5">Em atendimento</h2>
@forelse($current as $appointment)<div class="border-top py-3"><p class="fw-semibold mb-1">{{ $appointment->patient->full_name }}</p><p class="small text-secondary">Iniciado às {{ $appointment->called_at?->setTimezone($timezone)->format('H:i') }}</p>@include('appointments.actions')</div>@empty<p class="text-secondary mb-0 mt-3">Nenhum atendimento em andamento.</p>@endforelse
</div></div></div></div><div class="card"><div class="card-header bg-transparent p-3"><h2 class="h5 mb-0">Consultas de hoje</h2></div>@include('appointments.table')</div>
