@extends('layouts.guest')
@section('title','Entrar')
@section('content')
<h1 class="h4 mb-2">Bem-vindo ao seu consultório</h1><p class="text-secondary mb-4">Entre com sua conta para continuar.</p>
<form action="{{ route('login') }}" method="post">@csrf
<label for="email" class="form-label">E-mail</label><input id="email" name="email" type="email" class="form-control mb-3" value="{{ old('email') }}" autocomplete="username" required autofocus>
<label for="password" class="form-label">Senha</label><input id="password" name="password" type="password" class="form-control mb-4" autocomplete="current-password" required>
<button class="btn btn-primary w-100" type="submit">Entrar</button></form>
<a href="{{ route('password.request') }}" class="d-block text-center mt-3">Esqueci minha senha</a>
@endsection
