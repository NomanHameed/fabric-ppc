<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Machine capacity, and the allocation of that capacity to an operation for a date (optionally a shift).
 *
 * Capacity carries an explicit unit; every figure in this application is metres, so `capacity_uom` defaults to
 * `metres` and exists so a future roll/kg machine cannot be silently misread. Allocation quantities are
 * decimal(12,2) like every other quantity.
 *
 * The allocation table records *plan*, not production: it never touches lot_movements or lot_balances, which
 * only LedgerService may write. Utilisation compares the plan (allocations) with the machine's capacity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $t) {
            $t->decimal('hourly_capacity', 12, 2)->nullable()->after('name');
            $t->decimal('daily_capacity', 12, 2)->nullable()->after('hourly_capacity');
            $t->string('capacity_uom', 10)->default('metres')->after('daily_capacity');
        });

        Schema::create('machine_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('machine_id')->constrained()->restrictOnDelete();
            // The process the capacity is allocated to. Nullable: a block can be reserved before the operation is fixed.
            $t->foreignId('operation_id')->nullable()->constrained()->restrictOnDelete();
            // Denormalised from the operation (or the machine's department) so department utilisation is one query.
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('allocated_on');
            $t->decimal('allocated_qty', 12, 2);
            $t->string('uom', 10)->default('metres');
            $t->string('note', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['machine_id', 'allocated_on']);
            $t->index(['department_id', 'allocated_on']);
            $t->index(['allocated_on', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_allocations');
        Schema::table('machines', fn (Blueprint $t) => $t->dropColumn(['hourly_capacity', 'daily_capacity', 'capacity_uom']));
    }
};
