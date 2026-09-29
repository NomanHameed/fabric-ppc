<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_sheets', function (Blueprint $t) {
            $t->id();
            $t->date('sheet_date');
            $t->foreignId('shift_id')->constrained()->restrictOnDelete();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('machine_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('downtime_minutes')->default(0);
            $t->text('remarks')->nullable();
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['sheet_date', 'shift_id', 'machine_id']);
            $t->index(['sheet_date', 'machine_id', 'shift_id']);
            $t->index(['department_id', 'sheet_date']);
        });

        Schema::create('production_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_sheet_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->string('batch_no', 30)->nullable();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            // restrict: a route step with production recorded against it cannot be removed (LotRouteService maps the FK violation)
            $t->foreignId('lot_route_step_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('operation_id')->constrained()->restrictOnDelete();
            $t->boolean('is_reprocess')->default(false);
            $t->string('reprocess_reason', 40)->nullable();
            $t->decimal('qty_in', 12, 2);
            $t->decimal('qty_out', 12, 2);
            $t->enum('output_type', ['dyed', 'print'])->nullable();
            $t->decimal('g_width', 6, 2)->nullable();
            $t->decimal('f_width', 6, 2)->nullable();
            $t->string('remarks', 200)->nullable();
            $t->timestamps();
            $t->index('lot_id');
            $t->index(['production_sheet_id', 'line_no']);
            $t->index('batch_no');
        });

        // Spec §5.4. Created here so the colour gate can store overrides now; P5 reuses the table as is.
        // Ruling: only colour_overrides.production_line_id exists (unique) — the spec's production_lines.colour_override_id
        // would make the pair a circular FK. ProductionLine::colourOverride() is a hasOne.
        Schema::create('colour_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->foreignId('production_line_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->string('reason', 200);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            // dateTime, not timestamp: on MariaDB (and on MySQL with explicit_defaults_for_timestamp off) the
            // FIRST timestamp column of a table silently gains DEFAULT CURRENT_TIMESTAMP ON UPDATE
            // CURRENT_TIMESTAMP. `approved_at` is the first one here, so any later edit to a colour-override
            // row — re-stamping the reason, nulling production_line_id when a line is deleted — rewrote when
            // the override was approved. It records a decision and a moment; it must never move on its own.
            // Same fix as 628dc9c made for user_devices / user_actions.
            $t->dateTime('approved_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colour_overrides');
        Schema::dropIfExists('production_lines');
        Schema::dropIfExists('production_sheets');
    }
};
