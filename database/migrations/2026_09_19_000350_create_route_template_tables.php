<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('po_type', 40)->nullable();
            $t->string('quality_pattern', 120)->nullable();
            $t->smallInteger('priority')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('route_template_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('route_template_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('seq');
            $t->foreignId('operation_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['route_template_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_template_steps');
        Schema::dropIfExists('route_templates');
    }
};
