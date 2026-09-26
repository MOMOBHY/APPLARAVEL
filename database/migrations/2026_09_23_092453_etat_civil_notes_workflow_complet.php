<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes_service', function (Blueprint $table) {
            $table->string('fichier_path')->nullable()->after('contenu');
            $table->timestamp('valide_le')->nullable()->after('date_diffusion');
            $table
                ->foreignId('valideur_id')
                ->nullable()
                ->after('valide_le')
                ->constrained('agents')
                ->nullOnDelete();
        });

        Schema::create('note_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained('notes_service')->cascadeOnDelete();
            $table->foreignId('acteur_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('acteur_nom')->nullable();
            $table->string('acteur_role', 40)->nullable();
            $table->string('action', 60);
            $table->string('ancien_statut', 40)->nullable();
            $table->string('nouveau_statut', 40)->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamps();
            $table->index('note_id');
        });

        Schema::table('declarations_naissance', function (Blueprint $table) {
            $table->text('motif_retour')->nullable()->after('motif_rejet');
        });

        Schema::table('declarations_deces', function (Blueprint $table) {
            $table->text('motif_retour')->nullable()->after('motif_rejet');
        });

        Schema::create('declaration_historique', function (Blueprint $table) {
            $table->id();
            $table->string('type_dossier', 20);
            $table->unsignedBigInteger('dossier_id');
            $table->string('code_dossier', 40)->nullable();
            $table->foreignId('acteur_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('acteur_nom')->nullable();
            $table->string('acteur_role', 40)->nullable();
            $table->string('action', 60);
            $table->string('ancien_statut', 40)->nullable();
            $table->string('nouveau_statut', 40)->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamps();
            $table->index(['type_dossier', 'dossier_id']);
        });

        Schema::create('pieces_jointes', function (Blueprint $table) {
            $table->id();
            $table->string('dossier_type', 20);
            $table->unsignedBigInteger('dossier_id')->nullable();
            $table->string('reference_dossier', 80)->nullable();
            $table->string('nom_fichier');
            $table->string('type_mime', 100)->nullable();
            $table->string('chemin_stockage');
            $table
                ->foreignId('televerse_par_id')
                ->nullable()
                ->constrained('agents')
                ->nullOnDelete();
            $table->timestamps();
            $table->index(['dossier_type', 'dossier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_jointes');
        Schema::dropIfExists('declaration_historique');
        Schema::table('declarations_deces', function (Blueprint $table) {
            $table->dropColumn('motif_retour');
        });
        Schema::table('declarations_naissance', function (Blueprint $table) {
            $table->dropColumn('motif_retour');
        });
        Schema::dropIfExists('note_historique');
        Schema::table('notes_service', function (Blueprint $table) {
            $table->dropConstrainedForeignId('valideur_id');
            $table->dropColumn(['fichier_path', 'valide_le']);
        });
    }
};
