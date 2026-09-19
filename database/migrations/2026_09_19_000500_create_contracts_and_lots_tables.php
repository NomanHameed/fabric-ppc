<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('po_no')->unique();
            $t->foreignId('party_id')->constrained()->restrictOnDelete();
            $t->string('ref_no', 60)->nullable();
            $t->date('po_date');
            $t->decimal('contract_qty', 12, 2)->default(0);
            $t->timestamp('oracle_missing_at')->nullable();
            $t->timestamps();
            $t->index('po_date');
        });

        Schema::create('lots', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('oracle_line_id')->unique();
            $t->string('lot_no', 10)->index();
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->foreignId('quality_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('gsm')->nullable();
            $t->string('design_code', 20)->nullable();
            $t->string('design', 60)->nullable();
            $t->string('colour_code', 20)->nullable();
            $t->string('colour', 80)->nullable();
            $t->decimal('g_width', 6, 2)->nullable();
            $t->decimal('f_width', 6, 2)->nullable();
            $t->string('po_type', 40);
            $t->decimal('greige_qty', 12, 2);
            $t->string('colour_status', 40)->nullable();
            $t->enum('state', ['open', 'hold', 'closed', 'cancelled'])->default('open');
            $t->string('hold_reason', 40)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('loss_qty', 12, 2)->default(0);
            $t->string('loss_reason', 40)->nullable();
            $t->timestamp('oracle_missing_at')->nullable();
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->index(['contract_id', 'state']);
            $t->index(['state', 'lot_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
        Schema::dropIfExists('contracts');
    }
};
