<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PatientFactory extends Factory
{
    public function definition(): array
    {
        return ['full_name' => fake()->name(), 'email' => fake()->safeEmail(), 'created_by' => User::factory()];
    }
}
