@extends('layouts.app')
@section('title','Financeiro')
@section('subtitle','Recebimentos, estornos e pendências do consultório.')
@section('content')
<form method="get" class="d-flex flex-wrap gap-3 align-items-end mb-4"><div><label for="from" class="form-label">De</label><input class="form-control" type="date" id="from" name="from" value="{{ $from }}" required></div><div><label for="to" class="form-label">Até</label><input class="form-control" type="date" id="to" name="to" value="{{ $to }}" required></div><button class="btn btn-primary">Consultar período</button><a href="{{ route('finance.types') }}" class="btn btn-outline-primary">Tipos de atendimento</a></form>
<div class="row g-3 mb-4">@foreach(['Recebido no período'=>$received,'Estornado no período'=>$reversed,'Movimento líquido no período'=>$received-$reversed,'Saldo das consultas do período'=>$outstanding] as $label=>$value)<div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-body"><p class="text-secondary">{{ $label }}</p><strong class="h4">{{ \App\Support\Money::display($value) }}</strong></div></div></div>@endforeach</div>
<p class="small text-secondary">Recebimentos e estornos consideram a data do lançamento. O saldo considera as consultas com vencimento no período e todos os pagamentos e estornos registrados até agora. Cancelamento e falta não dispensam a cobrança automaticamente.</p>
@if($unpriced)<div class="alert alert-warning">{{ $unpriced }} cobrança(s) sem valor definido, fora do total de saldo. Abra cada cobrança para informar o valor.</div>@endif
<div class="card mb-3">@include('billing.table')</div>{{ $charges->links() }}
@endsection
