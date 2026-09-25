<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('matricule', 30)->nullable()->index();
            $table->string('nom', 200)->nullable();
            $table->string('role', 50)->nullable();
            $table->string('categorie', 20)->index();
            $table->string('action', 40);
            $table->string('description', 500)->nullable();
            $table->string('reference', 60)->nullable()->index();
            $table->boolean('reussi')->default(true);
            $table->string('ip', 45)->nullable();
            $table->string('appareil', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_audit');
    }
};
