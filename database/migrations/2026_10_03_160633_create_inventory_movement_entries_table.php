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
        Schema::create('inventory_movement_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity_delta');
            $table->unsignedInteger('balance_after');
            $table->index(['location_id', 'inventory_movement_id'], 'movement_entries_location_movement_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_entries');
    }
};
