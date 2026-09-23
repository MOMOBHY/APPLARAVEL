<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Agent> */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    public function definition(): array
    {
        return [
            'matricule' => fake()->unique()->bothify('AGT###??'),
            'civilite' => fake()->randomElement(['M.', 'Mme']),
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'solde_permission_annuel' => 30,
        ];
    }
}
