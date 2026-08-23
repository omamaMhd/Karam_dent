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
        Schema::create('audit_items', function (Blueprint $table) {
        $table->id();
        $table->foreignId('inventory_audit_id')->constrained()->cascadeOnDelete();
        $table->foreignId('item_id')->constrained()->cascadeOnDelete();
        $table->string('batch_number')->nullable();
        $table->integer('quantity_expected')->nullable();
        $table->integer('quantity_actual');
        $table->integer('variance')->virtualAs('quantity_actual - quantity_expected');
        $table->text('variance_reason')->nullable();
        $table->text('notes')->nullable();
         $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_items');
    }
};
