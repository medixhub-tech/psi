<div class="d-flex flex-wrap gap-2">
@php
$today = $appointment->starts_at->setTimezone($timezone)->toDateString() === now($timezone)->toDateString();
$actions = [];
if(auth()->user()->can('attendance.record') && $today && $appointment->status === 'scheduled') {
    $actions['arrive'] = 'Registrar chegada';
    if($appointment->starts_at->lte(now())) $actions['no_show'] = 'Não compareceu';
}
if(auth()->user()->can('attendance.treat')) {
    if($today && $appointment->status === 'waiting') $actions['call'] = 'Chamar paciente';
    if($appointment->status === 'in_progress') $actions['finish'] = 'Concluir atendimento';
}
@endphp
@foreach($actions as $action=>$label)
<form method="post" action="{{ route('appointments.transition',$appointment) }}" data-appointment-action="{{ $action }}">@csrf<input type="hidden" name="action" value="{{ $action }}"><input type="hidden" name="lock_version" value="{{ $appointment->lock_version }}"><button class="btn btn-sm {{ in_array($action,['call','finish'])?'btn-primary':'btn-outline-primary' }}">{{ $label }}</button></form>
@endforeach
@can('appointments.manage')
@if(in_array($appointment->status,['scheduled','waiting']))
<a class="btn btn-sm btn-outline-secondary" href="{{ route('appointments.edit',$appointment) }}">Reagendar</a>
<form method="post" action="{{ route('appointments.transition',$appointment) }}" data-confirm="Cancelar esta consulta?">@csrf<input type="hidden" name="action" value="cancel"><input type="hidden" name="lock_version" value="{{ $appointment->lock_version }}"><button class="btn btn-sm btn-outline-danger">Cancelar</button></form>
@endif
@endcan
</div>
