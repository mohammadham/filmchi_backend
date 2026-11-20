<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence for quarantining a dead source.
 *
 * The ingest job already counts failed attempts, but "failed" cannot tell a
 * link that is gone from a single bad Worker: both end in the same retry. These
 * columns keep the two facts the quarantine rule needs — how many attempts
 * wrote zero bytes and which Workers served them — so a link is only marked
 * dead when more than one route proved it.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    protected $tables = ['episode_sources', 'content_sources'];

    public function up()
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'ingest_zero_attempts')) {
                    $table->unsignedSmallInteger('ingest_zero_attempts')->default(0);
                }
                if (!Schema::hasColumn($tableName, 'ingest_zero_workers')) {
                    $table->text('ingest_zero_workers')->nullable();
                }
            });
        }
    }

    public function down()
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['ingest_zero_attempts', 'ingest_zero_workers'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
