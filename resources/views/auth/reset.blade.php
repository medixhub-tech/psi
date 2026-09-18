@extends('layouts.guest')
@section('title','Nova senha')
@section('content')<h1 class="h4">Defina uma nova senha</h1><p class="text-secondary">Use pelo menos 12 caracteres, incluindo letras e números.</p>
<form action="{{ route('password.update') }}" method="post">@csrf<input type="hidden" name="token" value="{{ $token }}">
<label for="email" class="form-label">E-mail</label><input id="email" name="email" type="email" class="form-control mb-3" value="{{ old('email',request('email')) }}" required>
<label for="password" class="form-label">Nova senha</label><input id="password" name="password" type="password" class="form-control mb-3" autocomplete="new-password" minlength="12" maxlength="72" required>
<label for="password_confirmation" class="form-label">Confirme a nova senha</label><input id="password_confirmation" name="password_confirmation" type="password" class="form-control mb-3" autocomplete="new-password" required><button class="btn btn-primary w-100">Salvar nova senha</button></form>@endsection
