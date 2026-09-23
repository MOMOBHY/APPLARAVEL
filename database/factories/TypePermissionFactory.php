<?php

namespace Database\Factories;

use App\Models\TypePermission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TypePermission> */
class TypePermissionFactory extends Factory
{
    protected $model = TypePermission::class;

    public function definition(): array
    {
        return ['libelle' => fake()->unique()->sentence(2), 'duree_max' => 30];
    }
}
