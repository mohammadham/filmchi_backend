<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relay credentials are now encrypted at rest by the EncryptedString cast.
 * The columns were varchar(255), which is too small for the ciphertext, so
 * they are widened to text and any existing plaintext values are encrypted
 * in place.
 */
class EncryptRelaySecrets extends Migration
{
    /** @var array<int, string> */
    protected $columns = [
        'relay_tg_password',
        'relay_tg_api_key',
        'relay_nine_password',
        'relay_nine_api_key',
    ];

    public function up()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            foreach ($this->columns as $column) {
                DB::statement("ALTER TABLE `global_settings` MODIFY `{$column}` TEXT NULL");
            }
        }

        foreach (DB::table('global_settings')->get() as $row) {
            $changes = [];

            foreach ($this->columns as $column) {
                $value = $row->{$column} ?? null;

                if ($value === null || $value === '' || $this->isEncrypted($value)) {
                    continue;
                }

                $changes[$column] = Crypt::encryptString((string) $value);
            }

            if ($changes) {
                DB::table('global_settings')->where('id', $row->id)->update($changes);
            }
        }
    }

    public function down()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        foreach (DB::table('global_settings')->get() as $row) {
            $changes = [];

            foreach ($this->columns as $column) {
                $value = $row->{$column} ?? null;

                if ($value === null || $value === '' || !$this->isEncrypted($value)) {
                    continue;
                }

                try {
                    $changes[$column] = Crypt::decryptString($value);
                } catch (DecryptException $e) {
                    // Leave unreadable values alone.
                }
            }

            if ($changes) {
                DB::table('global_settings')->where('id', $row->id)->update($changes);
            }
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            foreach ($this->columns as $column) {
                DB::statement("ALTER TABLE `global_settings` MODIFY `{$column}` VARCHAR(255) NULL");
            }
        }
    }

    protected function isEncrypted($value): bool
    {
        try {
            Crypt::decryptString((string) $value);

            return true;
        } catch (DecryptException $e) {
            return false;
        }
    }
}
