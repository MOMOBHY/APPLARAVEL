<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('matricule', 30)->unique();
            $table->string('civilite', 10)->default('M.');
            $table->string('nom');
            $table->string('prenom');
            $table->date('date_naissance')->nullable();
            $table->enum('sexe', ['M', 'F'])->default('M');
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('solde_permission_annuel')->default(30);
            $table->foreignId('structure_id')->nullable()->constrained('structures')->nullOnDelete();
            $table->foreignId('fonction_id')->nullable()->constrained('fonctions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['nom', 'prenom']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('matricule', 30)->nullable()->unique()->after('email');
            $table->foreignId('agent_id')->nullable()->after('matricule')->constrained('agents')->nullOnDelete();
            $table->foreignId('structure_id')->nullable()->after('agent_id')->constrained('structures')->nullOnDelete();
            $table->timestamp('derniere_connexion')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('structure_id');
            $table->dropConstrainedForeignId('agent_id');
            $table->dropColumn(['matricule', 'derniere_connexion']);
        });
        Schema::dropIfExists('agents');
    }
};
