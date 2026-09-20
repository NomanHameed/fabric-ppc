<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->enum('kind', ['import', 'issue', 'process', 'reprocess', 'dispatch', 'loss', 'adjustment']);
            $t->string('from_position', 40)->nullable();
            $t->string('to_position', 40)->nullable();
            $t->decimal('qty_out_of_from', 12, 2)->default(0);
            $t->decimal('qty_into_to', 12, 2)->default(0);
            $t->nullableMorphs('source');
            $t->dateTime('moved_at'); // stored as given (a TIMESTAMP would get MariaDB's implicit ON UPDATE)
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reversal_of_id')->nullable()->unique()->constrained('lot_movements')->restrictOnDelete(); // unique: a movement is reversed at most once
            $t->string('reason', 200)->nullable();
            $t->timestamps();
            $t->index(['lot_id', 'moved_at']);
            $t->index(['lot_id', 'kind']);
        });

        Schema::create('lot_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->string('position', 40);
            $t->decimal('qty', 12, 2)->default(0);
            $t->timestamps();
            $t->unique(['lot_id', 'position']);
        });

        Schema::table('lots', function (Blueprint $t) {
            $previous = 'greige_qty';
            foreach (['stock_qty', 'issued_qty', 'wip_qty', 'finished_qty', 'dispatched_qty'] as $col) {
                $t->decimal($col, 12, 2)->default(0)->after($previous);
                $previous = $col;
            }
            $t->string('current_label', 40)->nullable()->after('remarks');
            $t->timestamp('last_movement_at')->nullable()->after('current_label');
            $t->index(['state', 'current_label']);
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->dropIndex(['state', 'current_label']);
            $t->dropColumn(['stock_qty', 'issued_qty', 'wip_qty', 'finished_qty', 'dispatched_qty', 'current_label', 'last_movement_at']);
        });
        Schema::dropIfExists('lot_balances');
        Schema::dropIfExists('lot_movements');
    }
};
