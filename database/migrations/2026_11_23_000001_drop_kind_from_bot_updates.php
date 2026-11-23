<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `bot_updates.kind`: nothing ever read it.
 *
 * The webhook recorded whether the update was a message or a callback_query,
 * but the table only has to dedupe an update_id and say when it arrived - the
 * pruner walks received_at and the flow is decided from the update itself.
 * Guarded like the other bot migrations, so a re-run, a fresh install or an
 * engine that refuses the drop is a no-op instead of a failed deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bot_updates') || !Schema::hasColumn('bot_updates', 'kind')) {
            return;
        }

        try {
            Schema::table('bot_updates', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        } catch (Throwable $e) {
            // The column is nullable and unread, so keeping it costs nothing
            // while the bot keeps working.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('bot_updates') || Schema::hasColumn('bot_updates', 'kind')) {
            return;
        }

        Schema::table('bot_updates', function (Blueprint $table) {
            $table->string('kind', 24)->nullable();
        });
    }
};
