<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\NoteService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NoteService> */
class NoteServiceFactory extends Factory
{
    protected $model = NoteService::class;

    public function definition(): array
    {
        return [
            'numero_reference' => 'NOTE N° '.fake()->unique()->bothify('####').'/MFP/CAB/'.now()->year,
            'objet' => fake()->sentence(),
            'signataire_id' => Agent::factory(),
            'statut' => 'BROUILLON',
            'date_emission' => now()->toDateString(),
        ];
    }
}
