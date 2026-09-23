<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types_permission', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->unique();
            $table->unsignedInteger('duree_max')->default(30);
            $table->timestamps();
        });

        Schema::create('demandes_permission', function (Blueprint $table) {
            $table->id();
            $table->string('code_dossier', 40)->unique();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('type_permission_id')->constrained('types_permission');
            $table->date('date_debut');
            $table->date('date_fin');
            $table->unsignedInteger('nombre_jours');
            $table->text('motif');
            $table->string('piece_path')->nullable();
            $table->string('statut', 40)->default('SOUMISE');
            $table->foreignId('gestionnaire_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->text('avis_gestionnaire')->nullable();
            $table->foreignId('visa_direction_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->text('avis_direction')->nullable();
            $table->text('decision_drh')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->foreignId('decideur_drh_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->timestamp('date_verif_rh')->nullable();
            $table->timestamp('date_visa')->nullable();
            $table->timestamp('date_decision')->nullable();
            $table->timestamps();
            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_permission');
        Schema::dropIfExists('types_permission');
    }
};
