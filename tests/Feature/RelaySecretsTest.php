<?php

namespace Tests\Feature;

use App\GlobalSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RelaySecretsTest extends TestCase
{
    public function test_secrets_are_encrypted_at_rest_and_readable()
    {
        $setting = new GlobalSettings();
        $setting->is_live_tv_enable = 0;
        $setting->relay_nine_password = 'plain-secret';
        $setting->save();

        $raw = DB::table('global_settings')->where('id', $setting->id)->value('relay_nine_password');

        $this->assertNotSame('plain-secret', $raw);
        $this->assertSame('plain-secret', GlobalSettings::find($setting->id)->relay_nine_password);
    }

    public function test_legacy_plaintext_values_still_read()
    {
        $id = DB::table('global_settings')->insertGetId([
            'is_live_tv_enable' => 0,
            'relay_tg_password' => 'legacy-plain',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('legacy-plain', GlobalSettings::find($id)->relay_tg_password);
    }
}
