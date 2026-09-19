<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oracle_imports', function (Blueprint $t) {
            $t->id();
            $t->enum('source', ['excel', 'api'])->default('excel');
            $t->string('label', 200);
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->enum('status', ['previewed', 'committing', 'committed', 'failed', 'discarded'])->default('previewed');
            foreach (['new', 'changed', 'unchanged', 'missing', 'error'] as $c) {
                $t->unsignedInteger("{$c}_count")->default(0);
            }
            $t->json('unresolved_parties')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'committed_at']);
        });

        Schema::create('oracle_import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('oracle_import_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('row_no')->nullable();
            $t->unsignedInteger('oracle_line_id')->nullable();
            $t->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('party_code')->nullable();
            $t->enum('action', ['new', 'changed', 'unchanged', 'missing', 'error']);
            $t->json('payload')->nullable();
            $t->json('diff')->nullable();
            $t->string('message', 255)->nullable();
            $t->index(['oracle_import_id', 'action']);
            $t->index(['oracle_import_id', 'party_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oracle_import_rows');
        Schema::dropIfExists('oracle_imports');
    }
};
