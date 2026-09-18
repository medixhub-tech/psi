<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('roles.index', ['roles' => Role::withCount('users')->with('permissions')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('roles.form', ['role' => new Role, 'permissions' => Permission::where('owner_only', false)->get()]);
    }

    public function edit(Role $role): View
    {
        abort_if($role->code === 'psychologist', 403);

        return view('roles.form', ['role' => $role, 'permissions' => Permission::where('owner_only', false)->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Role);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->code === 'psychologist', 403);

        return $this->save($request, $role);
    }

    private function save(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'permissions' => 'sometimes|array', 'permissions.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where('owner_only', 0)]]);
        DB::transaction(function () use ($role, $data) {
            if ($role->exists) {
                $role = Role::lockForUpdate()->findOrFail($role->id);
            }
            $role->name = $data['name'];
            if (! $role->exists) {
                $role->code = 'custom_'.Str::uuid();
            } $role->save();
            $role->permissions()->sync($data['permissions'] ?? []);
            $role->users()->increment('session_version');
            DB::table('sessions')->whereIn('user_id', $role->users()->select('id'))->delete();
            Audit::record('roles.saved', 'role', $role->id);
        });

        return to_route('roles.index')->with('status', 'Perfil salvo. As permissões serão aplicadas no próximo login.');
    }
}
