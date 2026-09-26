<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('structures', function (Blueprint $table) {
            $table
                ->foreignId('responsable_agent_id')
                ->nullable()
                ->after('type')
                ->constrained('agents')
                ->nullOnDelete();
        });

        Schema::table('demandes_permission', function (Blueprint $table) {
            $table->string('visa_attendu', 20)->nullable()->after('visa_direction_id');
            $table->text('motif_retour')->nullable()->after('motif_rejet');
            $table->timestamp('notifie_le')->nullable()->after('date_decision');
            $table
                ->foreignId('notifie_par_id')
                ->nullable()
                ->after('notifie_le')
                ->constrained('agents')
                ->nullOnDelete();
        });

        Schema::create('demande_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('demandes_permission')->cascadeOnDelete();
            $table->foreignId('acteur_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('acteur_nom')->nullable();
            $table->string('acteur_role', 40)->nullable();
            $table->string('action', 60);
            $table->string('ancien_statut', 40)->nullable();
            $table->string('nouveau_statut', 40)->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamps();
            $table->index('demande_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demande_historique');
        Schema::table('demandes_permission', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notifie_par_id');
            $table->dropColumn(['visa_attendu', 'motif_retour', 'notifie_le']);
        });
        Schema::table('structures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_agent_id');
        });
    }
};
