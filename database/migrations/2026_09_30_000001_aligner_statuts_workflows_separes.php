<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sépare les workflows et renomme leurs statuts (EN_ATTENTE_RH, EN_ATTENTE_VALIDATION_*,
     * EN_ATTENTE_DRH) sans supprimer de données. */
    public function up(): void
    {
        Schema::table('declarations_naissance', function (Blueprint $table) {
            $table->string('statut', 40)->change();
        });
        Schema::table('declarations_deces', function (Blueprint $table) {
            $table->string('statut', 40)->change();
        });

        DB::table('demandes_permission')
            ->where('statut', 'EN_ATTENTE_GESTIONNAIRE_RH')
            ->update(['statut' => 'EN_ATTENTE_RH']);
        DB::table('demandes_permission')
            ->where('statut', 'EN_ATTENTE_VISA_SOUS_DIRECTEUR')
            ->update(['statut' => 'EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR']);
        DB::table('demandes_permission')
            ->where('statut', 'EN_ATTENTE_VISA_DIRECTEUR')
            ->update(['statut' => 'EN_ATTENTE_VALIDATION_DIRECTEUR']);

        DB::table('declarations_naissance')
            ->where('statut', 'EN_ATTENTE_RH')
            ->update(['statut' => 'EN_ATTENTE_VALIDATION_RESPONSABLE']);
        DB::table('declarations_naissance')
            ->where('statut', 'EN_ATTENTE_GESTIONNAIRE_RH')
            ->update(['statut' => 'EN_ATTENTE_RH']);

        DB::table('declarations_deces')
            ->where('statut', 'EN_ATTENTE_RH')
            ->update(['statut' => 'EN_ATTENTE_DRH']);
        DB::table('declarations_deces')
            ->where('statut', 'EN_ATTENTE_GESTIONNAIRE_RH')
            ->update(['statut' => 'EN_ATTENTE_RH']);
    }

    public function down(): void
    {
        DB::table('demandes_permission')
            ->where('statut', 'EN_ATTENTE_RH')
            ->update(['statut' => 'EN_ATTENTE_GESTIONNAIRE_RH']);
        DB::table('demandes_permission')
            ->where('statut', 'EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR')
            ->update(['statut' => 'EN_ATTENTE_VISA_SOUS_DIRECTEUR']);
        DB::table('demandes_permission')
            ->where('statut', 'EN_ATTENTE_VALIDATION_DIRECTEUR')
            ->update(['statut' => 'EN_ATTENTE_VISA_DIRECTEUR']);

        DB::table('declarations_naissance')
            ->where('statut', 'EN_ATTENTE_RH')
            ->update(['statut' => 'EN_ATTENTE_GESTIONNAIRE_RH']);

        DB::table('declarations_deces')
            ->where('statut', 'EN_ATTENTE_RH')
            ->update(['statut' => 'EN_ATTENTE_GESTIONNAIRE_RH']);
    }
};
