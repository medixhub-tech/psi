<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $attributes = ['active' => true, 'session_version' => 1];

    protected $fillable = ['name', 'email', 'password', 'role_id', 'active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'session_version' => 'integer'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isOwner(): bool
    {
        return $this->active && DB::table('practice')->where('id', 1)->where('owner_user_id', $this->id)->exists();
    }

    public function hasPermission(string $code): bool
    {
        if (! $this->active) {
            return false;
        }
        $permission = DB::table('permissions')->where('code', $code)->first();
        if (! $permission) {
            return false;
        }
        if ($this->isOwner()) {
            return true;
        }

        return ! $permission->owner_only && $this->role->permissions()->where('code', $code)->exists();
    }
}
