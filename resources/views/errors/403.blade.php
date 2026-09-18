@extends('layouts.guest')
@section('title','Acesso')
@section('content')<h1 class="h4">403</h1><p>Seu perfil não tem permissão para acessar esta página.</p><a class="btn btn-primary" href="{{ route('dashboard') }}">Voltar ao sistema</a>@endsection
