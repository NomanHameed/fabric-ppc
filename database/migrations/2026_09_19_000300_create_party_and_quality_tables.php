<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('oracle_code')->nullable()->unique();
            $t->string('name', 150);
            $t->string('name_normalized', 150)->unique();
            $t->enum('type', ['brand', 'local'])->default('brand');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('party_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('party_id')->constrained()->cascadeOnDelete();
            $t->string('alias', 150);
            $t->string('alias_normalized', 150)->unique();
            $t->timestamps();
        });

        Schema::create('qualities', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('oracle_code')->nullable()->unique();
            $t->string('description', 255);
            $t->unsignedSmallInteger('ends')->nullable();
            $t->unsignedSmallInteger('picks')->nullable();
            $t->string('warp_count', 20)->nullable();
            $t->string('weft_count', 20)->nullable();
            $t->string('composition', 80)->nullable();
            $t->string('loom', 10)->nullable();
            $t->string('notes', 150)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_aliases');
        Schema::dropIfExists('parties');
        Schema::dropIfExists('qualities');
    }
};
