<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « EN_ATTENTE_VALIDATION » (21 caractères) dépasse varchar(20) : rejeté par
     * MySQL/PostgreSQL en mode strict. Les déclarations encore à l'ancienne étape
     * « service administratif » passent chez le Gestionnaire RH, qui la remplace.
     */
    public function up(): void
    {
        Schema::table('notes_service', function (Blueprint $table) {
            $table->string('statut', 30)->default('BROUILLON')->change();
        });

        foreach (['declarations_naissance', 'declarations_deces'] as $table) {
            DB::table($table)
                ->where('statut', 'EN_ATTENTE_SERVICE_GESTION_ADMINISTRATIVE')
                ->update(['statut' => 'EN_ATTENTE_GESTIONNAIRE_RH']);
        }
    }

    public function down(): void
    {
        Schema::table('notes_service', function (Blueprint $table) {
            $table->string('statut', 20)->default('BROUILLON')->change();
        });
    }
};
