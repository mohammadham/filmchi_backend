<?php

namespace Tests\Feature;

use App\GlobalSettings;
use App\Http\Controllers\SettingController;
use Illuminate\Http\Request;
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
    protected ?array $savedSettings = null;

    protected bool $settingsExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $row = GlobalSettings::first();
        $this->settingsExisted = $row !== null;
        $this->savedSettings = $row?->getAttributes();
    }

    protected function tearDown(): void
    {
        if ($this->settingsExisted && $this->savedSettings !== null) {
            (GlobalSettings::first() ?: new GlobalSettings())
                ->forceFill($this->savedSettings)
                ->save();
        } elseif (!$this->settingsExisted) {
            GlobalSettings::query()->delete();
        }

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

    public function test_a_blank_secret_box_keeps_the_stored_secret(): void
    {
        $this->settings()->forceFill(['relay_bot_token' => '999:stored', 'relay_bot_webhook_secret' => 'stored-secret'])->save();

        $payload = $this->save(['relay_bot_token' => '', 'relay_bot_webhook_secret' => '', 'relay_bot_quality' => '1080']);

        $this->assertTrue($payload['status']);
        $this->assertSame('999:stored', $this->settings()->relay_bot_token);
        $this->assertSame('stored-secret', $this->settings()->relay_bot_webhook_secret);
    }
}
