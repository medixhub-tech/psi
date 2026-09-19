<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return ['patient_id' => Patient::factory(), 'starts_at' => now()->addDay()->startOfHour(), 'ends_at' => now()->addDay()->startOfHour()->addHour(), 'timezone' => 'America/Sao_Paulo', 'modality' => 'in_person', 'created_by' => User::factory(), 'updated_by' => User::factory()];
    }
}
