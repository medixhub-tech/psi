@extends('layouts.guest')
@section('title','Recuperar acesso')
@section('content')<h1 class="h4">Recuperar acesso</h1><p class="text-secondary">Informe seu e-mail para receber as instruções de recuperação.</p>
<form action="{{ route('password.email') }}" method="post">@csrf<label for="email" class="form-label">E-mail</label><input id="email" name="email" type="email" class="form-control mb-3" value="{{ old('email') }}" autocomplete="email" required><button class="btn btn-primary w-100">Enviar instruções</button></form><a href="{{ route('login') }}" class="d-block text-center mt-3">Voltar para entrar</a>@endsection
