<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist retry attempts and failures for URLs that fail while downloading or
 * uploading. Without these columns the queue retried in the background while
 * the panel already showed the row as permanently failed and had no real
 * failure counter to display.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    protected $tables = ['episode_sources', 'content_sources', 'relay_files'];

    public function up()
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'ingest_attempts')) {
                    $table->unsignedSmallInteger('ingest_attempts')->default(0);
                }
                if (!Schema::hasColumn($tableName, 'ingest_fail_count')) {
                    $table->unsignedSmallInteger('ingest_fail_count')->default(0);
                }
                if (!Schema::hasColumn($tableName, 'ingest_next_retry_at')) {
                    $table->timestamp('ingest_next_retry_at')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'upload_fail_count')) {
                    $table->unsignedSmallInteger('upload_fail_count')->default(0);
                }
                if (!Schema::hasColumn($tableName, 'upload_next_retry_at')) {
                    $table->timestamp('upload_next_retry_at')->nullable();
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
                foreach ([
                    'ingest_attempts',
                    'ingest_fail_count',
                    'ingest_next_retry_at',
                    'upload_fail_count',
                    'upload_next_retry_at',
                ] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
