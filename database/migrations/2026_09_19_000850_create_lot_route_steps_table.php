<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_route_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('seq');
            $t->foreignId('operation_id')->constrained()->restrictOnDelete();
            $t->boolean('is_added')->default(false);
            $t->enum('status', ['pending', 'partial', 'done', 'skipped'])->default('pending');
            $t->timestamps();
            $t->unique(['lot_id', 'seq']);
            $t->index(['lot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_route_steps');
    }
};
