<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auteur_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('titre', 200);
            $table->text('message')->nullable();
            $table->boolean('toutes_structures')->default(false);
            $table->foreignId('piece_id')->nullable()->constrained('pieces_jointes')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('partage_structure', function (Blueprint $table) {
            $table->foreignId('partage_id')->constrained('partages')->cascadeOnDelete();
            $table->foreignId('structure_id')->constrained('structures')->cascadeOnDelete();
            $table->primary(['partage_id', 'structure_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partage_structure');
        Schema::dropIfExists('partages');
    }
};
