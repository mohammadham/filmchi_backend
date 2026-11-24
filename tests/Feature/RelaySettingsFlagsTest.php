<?php

namespace Tests\Feature;

use App\GlobalSettings;
use App\Http\Controllers\SettingController;
use App\Services\Relay\RelaySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RemembersGlobalSettings;
use Tests\TestCase;

/**
 * Each card posts only its own fields, so a flag that is not in the request
 * must keep its saved value. The regression this pins: saving the bot card
 * silently switched the relay itself off, because the absent relay_enabled was
 * read as false.
 *
 * The suite runs on the install's own database, so the settings row is
 * replayed exactly as it was after every test.
 */
class RelaySettingsFlagsTest extends TestCase
{
    use RemembersGlobalSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberGlobalSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreGlobalSettings();

        parent::tearDown();
    }

    /** The settings row the panel writes to. */
    protected function settings(): GlobalSettings
    {
        $setting = GlobalSettings::first();

        if ($setting === null) {
            $setting = new GlobalSettings();
            $setting->save();
        }

        return $setting;
    }

    /** One card save, called the way the panel calls it. */
    protected function save(array $form): array
    {
        return (new SettingController())
            ->saveRelaySettings(Request::create('/saveRelaySettings', 'POST', $form))
            ->getData(true);
    }

    public function test_saving_only_the_bot_card_does_not_turn_the_relay_off(): void
    {
        $this->settings()->forceFill(['relay_enabled' => true])->save();

        $payload = $this->save([
            'relay_bot_enabled' => '1',
            'relay_bot_quality' => '720',
        ]);

        $this->assertTrue($payload['status']);
        $this->assertTrue((bool) $this->settings()->relay_enabled, 'the bot card must not touch the relay switch');
        $this->assertTrue((bool) $this->settings()->relay_bot_enabled);
        $this->assertSame('720', $this->settings()->relay_bot_quality);
    }

    public function test_a_flag_missing_from_the_request_keeps_its_saved_value(): void
    {
        $this->settings()->forceFill(['relay_enabled' => true, 'relay_bot_enabled' => true])->save();

        $payload = $this->save(['relay_bot_token' => '123:saved']);

        $this->assertTrue($payload['status']);
        $this->assertTrue((bool) $this->settings()->relay_enabled);
        $this->assertTrue((bool) $this->settings()->relay_bot_enabled, 'only an explicit 0 may switch a card off');
        $this->assertSame('123:saved', $this->settings()->relay_bot_token);
    }

    public function test_an_explicit_zero_still_switches_a_flag_off(): void
    {
        $this->settings()->forceFill(['relay_enabled' => true, 'relay_bot_enabled' => true])->save();

        $payload = $this->save(['relay_enabled' => '0']);

        $this->assertTrue($payload['status']);
        $this->assertFalse((bool) $this->settings()->relay_enabled, 'the hidden 0 of an unchecked toggle is honoured');
        $this->assertTrue((bool) $this->settings()->relay_bot_enabled, 'and it does not leak into the other card');
    }

    public function test_an_unchecked_bot_toggle_and_blank_boxes_leave_the_relay_card_alone(): void
    {
        $this->settings()->forceFill([
            'relay_enabled' => true,
            'relay_bot_enabled' => true,
            'relay_bot_token' => '123:stored',
            'relay_bot_webhook_secret' => 'stored-secret',
        ])->save();

        // Exactly what the bot form posts: an unchecked toggle sends the hidden
        // 0, and the secret boxes always render empty.
        $payload = $this->save([
            'relay_bot_enabled' => '0',
            'relay_bot_token' => '',
            'relay_bot_webhook_secret' => '',
        ]);

        $this->assertTrue($payload['status']);
        $this->assertFalse((bool) $this->settings()->relay_bot_enabled, 'the bot card switched only itself off');
        $this->assertTrue((bool) $this->settings()->relay_enabled, 'the relay card keeps its switch');
        $this->assertSame('123:stored', $this->settings()->relay_bot_token, 'a blank box keeps the stored token');
        $this->assertSame('stored-secret', $this->settings()->relay_bot_webhook_secret, 'and the stored secret');
    }

    public function test_the_bot_card_saves_with_blank_text_boxes_and_keeps_the_relay_switch(): void
    {
        $this->settings()->forceFill([
            'relay_enabled' => true,
            'relay_bot_token' => '123:stored',
            'relay_bot_storage_chat' => 'saved-chat',
            'relay_bot_allowed_ids' => '42',
        ])->save();

        // What the browser's blank boxes become: the global
        // ConvertEmptyStringsToNull middleware turns '' into null before the
        // controller sees it. The relay columns are NOT NULL, so this used to
        // die with "NOT NULL constraint failed:
        // global_settings.relay_bot_storage_chat" and the card was not saved.
        $payload = $this->save([
            'relay_bot_enabled' => '1',
            'relay_bot_token' => null,
            'relay_bot_webhook_secret' => null,
            'relay_bot_allowed_ids' => null,
            'relay_bot_storage_chat' => null,
            'relay_bot_quality' => null,
            'relay_bot_channel_id' => null,
            'relay_bot_daily_limit' => null,
            'relay_bot_max_size_mb' => null,
        ]);

        $this->assertTrue($payload['status'], 'a blank text box must not fail the save');

        $setting = $this->settings();
        $this->assertSame('', (string) $setting->relay_bot_storage_chat, 'a blank chat is saved empty, not null');
        $this->assertSame('', (string) $setting->relay_bot_allowed_ids);
        $this->assertSame('', (string) $setting->relay_bot_quality);
        $this->assertSame(0, (int) $setting->relay_bot_channel_id);
        $this->assertTrue((bool) $setting->relay_bot_enabled);
        $this->assertSame('123:stored', $setting->relay_bot_token, 'a blank secret box keeps the stored token');
        $this->assertTrue((bool) $setting->relay_enabled, 'the bot card must not touch the relay switch');
    }

    /**
     * The live crash was SQLite's "NOT NULL constraint failed:
     * global_settings.relay_bot_storage_chat", and the install's MySQL is lenient
     * enough to hide it, so the row is rebuilt on SQLite here: a blank box must
     * be stored as its empty value on the driver that reported the bug.
     */
    public function test_a_blank_text_box_never_reaches_the_not_null_column(): void
    {
        config(['database.connections.relay_settings_guard' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('relay_settings_guard');

        Schema::connection('relay_settings_guard')->create('global_settings', function ($table) {
            $table->increments('id');
            $table->boolean('relay_enabled')->default(false);
            $table->boolean('relay_bot_enabled')->default(false);
            $table->text('relay_bot_token')->nullable();
            $table->text('relay_bot_webhook_secret')->nullable();
            $table->text('relay_bot_allowed_ids')->nullable();
            $table->string('relay_bot_storage_chat')->default('');
            $table->unsignedInteger('relay_bot_channel_id')->default(0);
            $table->string('relay_bot_quality', 16)->default('1080');
            $table->unsignedInteger('relay_bot_daily_limit')->default(20);
            $table->unsignedInteger('relay_bot_max_size_mb')->default(2048);
            $table->timestamps();
        });

        $setting = new GlobalSettings();
        $setting->setConnection('relay_settings_guard');
        $setting->relay_enabled = true;
        $setting->save();

        // Exactly what the controller calls after ConvertEmptyStringsToNull.
        RelaySettings::save($setting, [
            'relay_bot_enabled' => '1',
            'relay_bot_allowed_ids' => null,
            'relay_bot_storage_chat' => null,
            'relay_bot_channel_id' => null,
        ]);

        $stored = $setting->fresh();
        $this->assertSame('', $stored->relay_bot_storage_chat);
        $this->assertSame('', $stored->relay_bot_allowed_ids);
        $this->assertSame(0, (int) $stored->relay_bot_channel_id);
        $this->assertTrue((bool) $stored->relay_bot_enabled);
        $this->assertTrue((bool) $stored->relay_enabled, 'saving the card keeps the relay switch');
    }

    public function test_a_blank_secret_box_keeps_the_stored_secret(): void
    {
        $this->settings()->forceFill(['relay_bot_token' => '999:stored', 'relay_bot_webhook_secret' => 'stored-secret'])->save();

        $payload = $this->save(['relay_bot_token' => '', 'relay_bot_webhook_secret' => '', 'relay_bot_quality' => '1080']);

        $this->assertTrue($payload['status']);
        $this->assertSame('999:stored', $this->settings()->relay_bot_token);
        $this->assertSame('stored-secret', $this->settings()->relay_bot_webhook_secret);
    }
}
