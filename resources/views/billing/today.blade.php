@extends('layouts.app')
@section('title','Cobranças do dia')
@section('subtitle','Consulte os valores e registre os pagamentos das consultas de hoje.')
@section('content')
<p>{{ \Carbon\CarbonImmutable::parse($date)->format('d/m/Y') }} · {{ \App\Support\Agenda::timezone() }}</p>
<div class="card">@include('billing.table')</div>
@endsection
