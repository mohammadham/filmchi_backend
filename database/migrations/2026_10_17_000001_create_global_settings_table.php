<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGlobalSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('global_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name', 55)->nullable();
            $table->boolean('is_live_tv_enable');
            $table->boolean('is_admob_android')->default(true);
            $table->boolean('is_admob_ios')->default(true);
            $table->boolean('is_custom_android')->default(true);
            $table->boolean('is_custom_ios')->default(true);
            $table->integer('videoad_skip_time')->default(8);
            $table->integer('storage_type')->default(0)->comment('0 = Local / 1 = AWS S3 / 2 = DigitalOcean Space / 3 = Combined (Telegram Drive + 9drive)');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('global_settings');
    }
}
