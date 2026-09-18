<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:255']);
        $data['email'] = Str::lower(trim($data['email']));
        $key = 'login:'.hash('sha256', $data['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Muitas tentativas. Aguarde um minuto e tente novamente.']);
        }
        RateLimiter::hit($key, 60);
        if (! Auth::attempt([...$data, 'active' => true])) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $request->user()->session_version);
        Audit::record('auth.login', 'user', $request->user()->id);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email|max:254']);
        $email = Str::lower(trim($request->input('email')));
        if (User::where('email', $email)->where('active', true)->exists()) {
            Password::sendResetLink(['email' => $email, 'active' => true]);
        }

        return back()->with('status', 'Se houver uma conta ativa para esse e-mail, enviaremos as instruções de recuperação.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:254', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers(), 'max:72']]);
        $data['email'] = Str::lower(trim($data['email']));
        $status = Password::reset([...$data, 'active' => true], function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->increment('session_version');
                DB::table('sessions')->where('user_id', $user->id)->delete();
                Audit::record('auth.password_reset', 'user', $user->id, $user->id);
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Link inválido ou expirado. Solicite um novo link.']);
        }

        return redirect()->route('login')->with('status', 'Senha atualizada. Entre com a nova senha.');
    }
}
