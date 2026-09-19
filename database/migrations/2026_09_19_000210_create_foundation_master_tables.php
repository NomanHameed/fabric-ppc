<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name_en', 100);
            $t->string('name_ur', 100)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('machines', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('department_machine', function (Blueprint $t) {
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $t->primary(['department_id', 'machine_id']);
        });

        Schema::create('department_user', function (Blueprint $t) {
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['department_id', 'user_id']);
        });

        Schema::create('operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->enum('kind', ['normal', 'reprocess'])->default('normal');
            $t->string('stage_label', 40);
            $t->boolean('counts_as_finish_production')->default(false);
            $t->enum('finish_type', ['dyed', 'print'])->nullable();
            $t->boolean('is_stretch')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['department_id', 'name']);
        });

        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 5)->unique();
            $t->string('name', 50);
            $t->time('starts_at');
            $t->time('ends_at');
            $t->timestamps();
        });

        Schema::create('lookup_values', function (Blueprint $t) {
            $t->id();
            $t->string('list_key', 40);
            $t->string('code', 40);
            $t->string('label_en', 120);
            $t->string('label_ur', 120)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->json('meta')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['list_key', 'code']);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'lookup_values', 'shifts', 'operations', 'department_user', 'department_machine', 'machines', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
