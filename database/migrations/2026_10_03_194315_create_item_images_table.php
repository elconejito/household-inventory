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
        Schema::create('item_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('thumbnail_path');
            $table->string('thumbnail_mime_type', 100);
            $table->unsignedInteger('thumbnail_width');
            $table->unsignedInteger('thumbnail_height');
            $table->string('display_path');
            $table->string('display_mime_type', 100);
            $table->unsignedInteger('display_width');
            $table->unsignedInteger('display_height');
            $table->string('caption', 500)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->softDeletes();

            $table->index(['item_id', 'deleted_at', 'is_primary']);
            $table->index(['item_id', 'uploaded_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_images');
    }
};
