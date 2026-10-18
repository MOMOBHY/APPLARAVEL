<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Refonte des notes de service : la secrétaire dépose un document Word, son directeur le valide (ou le
 * retourne avec un motif), puis elle l'envoie aux destinataires qu'elle choisit, par e-mail et dans
 * l'application. L'ancien circuit (rédaction dans l'application, validation par tous les directeurs,
 * diffusion directe) et ses tables sont supprimés. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('note_historique');
        Schema::dropIfExists('note_structure');
        Schema::dropIfExists('notes_service');

        // Notifications de l'ancien circuit : elles renvoient vers des notes qui n'existent plus.
        DB::table('notifications')
            ->whereIn('type', ['VALIDEE', 'REFUS_VALIDATION', 'ATTENTE_SAISIE', 'NOTE_SERVICE'])
            ->orWhere('reference_dossier', 'like', 'NOTE N°%')
            ->delete();

        Schema::create('notes_service', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 60)->unique();
            $table->string('objet', 255);
            $table->date('date_note');
            $table->string('fichier_path');
            $table->string('fichier_nom');
            $table->unsignedInteger('fichier_taille')->default(0);
            $table->string('statut', 30)->default('BROUILLON')->index();
            $table->foreignId('secretaire_id')->constrained('agents');
            $table->foreignId('directeur_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->text('motif_retour')->nullable();
            $table->boolean('envoi_a_tous')->default(false);
            $table->timestamp('transmise_le')->nullable();
            $table->timestamp('validee_le')->nullable();
            $table->timestamp('envoyee_le')->nullable();
            $table->timestamps();
        });

        // Structures choisies par la secrétaire au moment de l'envoi.
        Schema::create('note_structure', function (Blueprint $table) {
            $table->foreignId('note_id')->constrained('notes_service')->cascadeOnDelete();
            $table->foreignId('structure_id')->constrained('structures')->cascadeOnDelete();
            $table->primary(['note_id', 'structure_id']);
        });

        // Destinataires effectifs, figés à l'envoi : « choisi » = désigné nommément par la secrétaire.
        Schema::create('note_destinataires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained('notes_service')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->boolean('choisi')->default(false);
            $table->timestamp('lu_le')->nullable();
            $table->timestamps();
            $table->unique(['note_id', 'agent_id']);
        });

        Schema::create('note_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained('notes_service')->cascadeOnDelete();
            $table->foreignId('acteur_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('acteur_nom')->nullable();
            $table->string('acteur_role', 40)->nullable();
            $table->string('action', 40);
            $table->text('commentaire')->nullable();
            $table->timestamps();
            $table->index('note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_historique');
        Schema::dropIfExists('note_destinataires');
        Schema::dropIfExists('note_structure');
        Schema::dropIfExists('notes_service');
    }
};
