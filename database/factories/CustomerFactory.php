<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->name(), 'phone' => fake()->unique()->numerify('017########'), 'email' => fake()->safeEmail(), 'address' => fake()->address()];
    }
}
