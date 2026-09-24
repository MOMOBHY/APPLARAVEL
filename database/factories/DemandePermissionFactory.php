<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\DemandePermission;
use App\Models\TypePermission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DemandePermission> */
class DemandePermissionFactory extends Factory
{
    protected $model = DemandePermission::class;

    public function definition(): array
    {
        return [
            'code_dossier' => 'PERM-'.fake()->unique()->bothify('######'),
            'agent_id' => Agent::factory(),
            'type_permission_id' => TypePermission::factory(),
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addDay()->toDateString(),
            'nombre_jours' => 2,
            'motif' => fake()->sentence(),
            'statut' => DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH,
        ];
    }
}
