<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('original_path');
            $table->string('feed_path');
            $table->string('thumb_path');
            $table->string('access')->default('public'); // Ex: public, private
            $table->float('weight')->nullable(); // Peso do animal ou objeto
            $table->integer('age')->nullable(); // Idade
            $table->string('title')->nullable(); // Título da foto
            $table->text('description')->nullable(); // Descrição da foto
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_photos');
    }
};
