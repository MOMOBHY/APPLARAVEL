<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'historique conserve l'ancien statut « EN_ATTENTE_SERVICE_GESTION_ADMINISTRATIVE »
     * (41 caractères), refusé par MySQL dans un varchar(40).
     */
    public function up(): void
    {
        Schema::table('declaration_historique', function (Blueprint $table) {
            $table->string('ancien_statut', 60)->nullable()->change();
            $table->string('nouveau_statut', 60)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('declaration_historique', function (Blueprint $table) {
            $table->string('ancien_statut', 40)->nullable()->change();
            $table->string('nouveau_statut', 40)->nullable()->change();
        });
    }
};
