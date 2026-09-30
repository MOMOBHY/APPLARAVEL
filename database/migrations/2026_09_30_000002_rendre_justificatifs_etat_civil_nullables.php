<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Un brouillon de déclaration peut être enregistré avant que l'agent ait scanné son justificatif. La
 * pièce reste exigée à la soumission (EtatCivilService). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('declarations_naissance', function (Blueprint $table) {
            $table->string('extrait_path')->nullable()->change();
        });
        Schema::table('declarations_deces', function (Blueprint $table) {
            $table->string('certificat_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('declarations_naissance', function (Blueprint $table) {
            $table->string('extrait_path')->nullable(false)->change();
        });
        Schema::table('declarations_deces', function (Blueprint $table) {
            $table->string('certificat_path')->nullable(false)->change();
        });
    }
};
