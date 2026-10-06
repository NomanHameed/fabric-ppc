<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self-registration with moderator approval. The column defaults to `pending` (deny by default): a row that
 * reaches the table without anyone saying otherwise is not allowed in. Rows that already existed when this
 * ran were created by an administrator, so they are backfilled to `approved`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending')->after('is_active');
            $t->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable()->after('approved_by');
            $t->string('rejected_reason', 200)->nullable()->after('approved_at');
            $t->index(['approval_status', 'created_at']);
        });

        DB::table('users')->update(['approval_status' => 'approved', 'approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['approval_status', 'created_at']);
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $t->dropForeign(['approved_by']);
            }
            $t->dropColumn(['approval_status', 'approved_by', 'approved_at', 'rejected_reason']);
        });
    }
};
