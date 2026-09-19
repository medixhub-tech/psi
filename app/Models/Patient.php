<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = ['full_name', 'birth_date', 'phone', 'email', 'guardian_name', 'guardian_phone', 'active'];

    protected $attributes = ['active' => true, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'birth_date' => 'date', 'lock_version' => 'integer'];
    }
}
