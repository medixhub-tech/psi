<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::with('role')->orderBy('name')->paginate(15)]);
    }

    public function create(): View
    {
        return view('users.form', ['account' => new User, 'roles' => Role::where('code', '!=', 'psychologist')->orderBy('name')->get()]);
    }

    public function edit(User $user): View
    {
        abort_if($user->isOwner(), 403, 'O proprietário não pode ser alterado por este formulário.');

        return view('users.form', ['account' => $user, 'roles' => Role::where('code', '!=', 'psychologist')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data) {
            $user = User::create($data);
            Audit::record('users.created', 'user', $user->id);
        });

        return to_route('users.index')->with('status', 'Usuário criado.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isOwner(), 403);
        $data = $this->validated($request, $user);
        DB::transaction(function () use ($data, $user) {
            $account = User::lockForUpdate()->findOrFail($user->id);
            abort_if($account->isOwner(), 403);
            $account->fill($data);
            $account->session_version++;
            $account->remember_token = null;
            $account->save();
            DB::table('sessions')->where('user_id', $account->id)->delete();
            Audit::record('users.updated', 'user', $account->id);
        });

        return to_route('users.index')->with('status', 'Usuário atualizado. As sessões anteriores foram encerradas.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($user?->id)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->where('code', '!=', 'psychologist'))],
            'active' => 'required|boolean',
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(12)->letters()->numbers(), 'max:72'],
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}
