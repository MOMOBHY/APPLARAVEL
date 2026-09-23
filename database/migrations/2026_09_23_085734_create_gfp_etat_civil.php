<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('declarations_naissance', function (Blueprint $table) {
            $table->id();
            $table->string('code_dossier', 40)->unique();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('nom_enfant');
            $table->string('prenom_enfant');
            $table->date('date_naissance_enfant');
            $table->string('lieu_naissance_enfant');
            $table->string('extrait_path');
            $table->string('statut', 30)->default('SOUMISE');
            $table->text('motif_rejet')->nullable();
            $table->foreignId('valideur_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('declarations_deces', function (Blueprint $table) {
            $table->id();
            $table->string('code_dossier', 40)->unique();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('nom_defunt');
            $table->string('prenom_defunt');
            $table->enum('lien_parente', ['ascendant', 'descendant', 'conjoint']);
            $table->date('date_deces');
            $table->string('lieu_deces');
            $table->string('certificat_path');
            $table->string('statut', 30)->default('SOUMISE');
            $table->text('motif_rejet')->nullable();
            $table->foreignId('valideur_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declarations_deces');
        Schema::dropIfExists('declarations_naissance');
    }
};
