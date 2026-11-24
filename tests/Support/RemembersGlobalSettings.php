<?php

namespace Tests\Support;

use App\GlobalSettings;

/**
 * These suites run on the install's own database and the panel writes to the
 * single global_settings row, so a test that saves a card would leave the
 * install changed. Call rememberGlobalSettings() before the test and
 * restoreGlobalSettings() after it: the row comes back exactly as it was,
 * and a row the test created is removed.
 */
trait RemembersGlobalSettings
{
    /** @var array<string, mixed>|null null means the install had no row */
    protected ?array $savedGlobalSettings = null;

    protected function rememberGlobalSettings(): void
    {
        $this->savedGlobalSettings = GlobalSettings::first()?->getAttributes();
    }

    protected function restoreGlobalSettings(): void
    {
        if ($this->savedGlobalSettings === null) {
            GlobalSettings::query()->delete();

            return;
        }

        (GlobalSettings::first() ?: new GlobalSettings())
            ->forceFill($this->savedGlobalSettings)
            ->save();
    }
}
