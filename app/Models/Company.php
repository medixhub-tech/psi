<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['cnpj', 'legal_name', 'trade_name', 'phone', 'email', 'postal_code', 'street', 'address_number', 'address_complement', 'district', 'city', 'state', 'active'];

    protected $attributes = ['active' => true, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'lock_version' => 'integer'];
    }
}
