<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return ['cnpj' => '11222333000181', 'legal_name' => 'Empresa Ficticia', 'active' => true];
    }
}
