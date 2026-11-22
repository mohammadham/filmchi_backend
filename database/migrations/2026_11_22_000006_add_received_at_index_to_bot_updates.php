<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The prune walks bot_updates by received_at, so the column needs an index of
 * its own. Wrapped and guarded like the other bot migrations: an install that
 * already has it (or a re-run) is a no-op rather than a failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bot_updates')) {
            return;
        }

        try {
            Schema::table('bot_updates', function (Blueprint $table) {
                $table->index('received_at');
            });
        } catch (Throwable $e) {
            // Already indexed: the prune still works, it just scans less well.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('bot_updates')) {
            return;
        }

        try {
            Schema::table('bot_updates', function (Blueprint $table) {
                $table->dropIndex(['received_at']);
            });
        } catch (Throwable $e) {
            // No index to drop.
        }
    }
};
