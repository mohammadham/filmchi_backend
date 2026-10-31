<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIngestColumnsToSources extends Migration
{
    protected $columns = [
        'relay_file_id' => ['integer', 'nullable'],
        'ingest_status' => ['string', 'nullable'],
        'ingest_progress' => ['integer', 'default 0'],
        'ingest_error' => ['text', 'nullable'],
    ];

    public function up()
    {
        foreach (['content_sources', 'episode_sources'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach ($this->columns as $name => $definition) {
                    if (Schema::hasColumn($table, $name)) {
                        continue;
                    }

                    $column = $blueprint->{$definition[0]}($name);

                    if ($definition[1] === 'nullable') {
                        $column->nullable();
                    }
                }
            });
        }
    }

    public function down()
    {
        foreach (['content_sources', 'episode_sources'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $drop = [];

                foreach (array_keys($this->columns) as $name) {
                    if (Schema::hasColumn($table, $name)) {
                        $drop[] = $name;
                    }
                }

                if ($drop) {
                    $blueprint->dropColumn($drop);
                }
            });
        }
    }
}
