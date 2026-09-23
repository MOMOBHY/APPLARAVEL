<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes_service', function (Blueprint $table) {
            $table->id();
            $table->string('numero_reference', 80)->unique();
            $table->string('objet');
            $table->text('contenu')->nullable();
            $table->foreignId('signataire_id')->constrained('agents');
            $table->foreignId('secretaire_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('statut', 20)->default('BROUILLON');
            $table->date('date_emission');
            $table->timestamp('date_diffusion')->nullable();
            $table->timestamps();
        });

        Schema::create('note_structure', function (Blueprint $table) {
            $table->foreignId('note_id')->constrained('notes_service')->cascadeOnDelete();
            $table->foreignId('structure_id')->constrained('structures')->cascadeOnDelete();
            $table->primary(['note_id', 'structure_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('titre');
            $table->text('message');
            $table->string('type', 40)->default('INFO');
            $table->string('reference_dossier', 80)->nullable();
            $table->boolean('est_lu')->default(false);
            $table->timestamps();
            $table->index(['agent_id', 'est_lu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('note_structure');
        Schema::dropIfExists('notes_service');
    }
};
