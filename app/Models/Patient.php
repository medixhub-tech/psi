<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = ['postal_code', 'street', 'address_number', 'address_complement', 'district', 'city', 'state', 'full_name', 'birth_date', 'phone', 'email', 'guardian_name', 'guardian_phone', 'active', 'whatsapp_reminders', 'email_reminders', 'communication_source'];

    protected $attributes = ['active' => true, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['whatsapp_reminders' => 'boolean', 'email_reminders' => 'boolean', 'active' => 'boolean', 'birth_date' => 'date', 'lock_version' => 'integer'];
    }
}
