<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            // store uuid and default to DB UUID() when supported
            $table->string('uuid', 36)->nullable()->unique()->default(DB::raw('(UUID())'));
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // image paths
            $table->string('original_path')->nullable();
            $table->string('feed_path')->nullable();
            $table->string('thumb_path')->nullable();

            // privacy / access
            $table->string('access')->default('public'); // Ex: public, private

            // metadata
            $table->float('weight')->nullable(); // Peso do animal ou objeto
            $table->integer('age')->nullable(); // Idade

            // post content
            $table->string('title')->nullable(); // Título da foto/post
            $table->text('description')->nullable(); // Descrição da foto/post

            // social metrics
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedInteger('comments_count')->default(0);

            // publishing
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // indexes for common queries
            $table->index(['user_id']);
            $table->index(['is_published', 'published_at']);
        });        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
