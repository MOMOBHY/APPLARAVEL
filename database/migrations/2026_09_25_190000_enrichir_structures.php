<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('structures', function (Blueprint $table) {
            $table->string('sigle', 20)->nullable()->after('nom');
            $table->foreignId('parent_id')->nullable()->after('type')->constrained('structures')->nullOnDelete();
            // false = structure fictive de démonstration (comptes d'essai), absente de l'annuaire officiel.
            $table->boolean('officielle')->default(true)->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('structures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['sigle', 'officielle']);
        });
    }
};
