<?php

/*
 * One-off check: upload a small file through Flixy's TelegramDriveClient using
 * the panel-issued key (TGDRIVE_API_KEY), then report the file id. Run it
 * inside the app container; it leaves the uploaded file behind so the caller
 * can delete it through the API.
 */

require '/app/vendor/autoload.php';

$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = sys_get_temp_dir() . '/keycheck.bin';

if (!is_file($path)) {
    file_put_contents($path, str_repeat('flixy-key-check', 524288));
}

$client = new App\Services\Relay\TelegramDriveClient((array) config('media_relay.telegram'));

echo 'base_url=' . config('media_relay.telegram.base_url') . PHP_EOL;
echo 'has_api_key=' . (config('media_relay.telegram.api_key') !== '' ? 'yes' : 'no') . PHP_EOL;

try {
    $result = $client->uploadFromPath(
        $path,
        'keycheck.bin',
        'application/octet-stream',
        (string) config('media_relay.telegram.storage_chat'),
        'Flixy-Test'
    );

    echo 'upload=' . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $e) {
    echo 'upload_failed=' . $e->getMessage() . PHP_EOL;
} finally {
    @unlink($path);
}
