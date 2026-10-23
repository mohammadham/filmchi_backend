<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Google\Client;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
 

class GlobalFunction extends Model
{

    public static function sendPushNotificationToAllUsers($title, $description, $content_id)
    {
        // Load Google credentials and fetch the access token
        $googleCredentialsPath = base_path('googleCredentials.json');
        $googleCredentials = json_decode(File::get($googleCredentialsPath), true);

        $client = new Client();
        $client->setAuthConfig($googleCredentialsPath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->fetchAccessTokenWithAssertion();
        $accessToken = $client->getAccessToken()['access_token'];

        // FCM endpoint
        $url = 'https://fcm.googleapis.com/v1/projects/' . $googleCredentials['project_id'] . '/messages:send';
        
        // Common notification payload
        $notificationPayload = [
            'title' => $title,
            'body' => $description,
        ];

        // Prepare the iOS-specific payload
        $iosPayload = [
            'message' => [
                'topic' => env('NOTIFICATION_TOPIC') . '_ios',
                'notification' => $notificationPayload,
                'data' => ['content_id' => $content_id],
                'apns' => [
                    'payload' => [
                        'aps' => ['sound' => 'default']
                    ],
                ],
            ],
        ];

        // Prepare the Android-specific payload
        $androidPayload = [
            'message' => [
                'topic' => env('NOTIFICATION_TOPIC') . '_android',
                'data' => array_merge($notificationPayload, ['content_id' => $content_id]),
                'android' => ['priority' => 'high'],
            ],
        ];

        // Send iOS notification
        $iosResponse = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $iosPayload);

        // Log the iOS response
        // Log::debug('iOS Notification Response:', [$iosResponse->body()]);

        // Send Android notification
        $androidResponse = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $androidPayload);

        // Log the Android response
        // Log::debug('Android Notification Response:', [$androidResponse->body()]);

        // Check the response statuses
        if ($iosResponse->successful() && $androidResponse->successful()) {
            return json_encode(['status' => true, 'message' => 'Notification sent successfully']);
        }

        return json_encode(['status' => false, 'message' => 'Failed to send notification']);
    }


    public static function deleteFile($url)
    {
        if ($url == null) {
            return;
        }

        // A relay link (combined storage) points at /r/{token} or /d/{token}:
        // remove the remote copies as well instead of treating it as a disk path.
        if (self::deleteRelayFile($url)) {
            return;
        }

        // Define the base URLs for AWS, DigitalOcean, and Local storage
        $baseURLAWS = rtrim(env('ITEM_BASE_URL'), '/') . '/';
        $baseURLDO = rtrim(env('DO_SPACE_URL'), '/') . '/';
        $baseURLLocal = rtrim(env('APP_URL'), '/') . '/storage/';

        // Remove the base URLs from the given URL to get the file paths
        $fileNameAWS = str_replace($baseURLAWS, '', $url);
        $fileNameDO = str_replace($baseURLDO, '', $fileNameAWS);
        $fileNameLocal = str_replace($baseURLLocal, '', $url);

        // Check and delete the file from local storage
        if (Storage::disk('local')->exists('public/' . $fileNameLocal)) {
            Storage::disk('local')->delete('public/' . $fileNameLocal);
        }

        try {
            if (Storage::disk('digitalocean')->exists($fileNameDO)) {
                Storage::disk('digitalocean')->delete($fileNameDO);
            }    
        } catch (\Exception $e) {
            
        }
        
        try {
            if (Storage::disk('s3')->exists($fileNameAWS)) {
                Storage::disk('s3')->delete($fileNameAWS);
            }    
        } catch (\Exception $e) {
            
        }
        
    }

    /**
     * Remove a relay-backed file (Telegram copy, cache copy, links, events)
     * when the given URL is one of this site's relay links.
     *
     * Returns true when the URL was a relay link.
     */
    protected static function deleteRelayFile($url)
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path === '' || class_exists(\App\RelayLink::class) === false) {
            return false;
        }

        $templates = [
            (string) config('media_relay.relay.stream_path', 'r/{token}'),
            (string) config('media_relay.relay.download_path', 'd/{token}'),
        ];

        $token = null;

        foreach ($templates as $template) {
            $prefix = '/' . trim(str_replace('{token}', '', $template), '/');

            if (strpos($path, $prefix . '/') === 0) {
                $token = trim(substr($path, strlen($prefix) + 1), '/');

                if ($token !== '' && strpos($token, '/') === false) {
                    break;
                }

                $token = null;
            }
        }

        if ($token === null) {
            return false;
        }

        $link = \App\RelayLink::where('token', $token)->first();

        if (!$link) {
            return true;
        }

        $file = $link->file;

        if ($file) {
            try {
                if (!empty($file->nine_file_id)) {
                    app(\App\Services\Relay\CachePromoter::class)->evict($file, true);
                }
            } catch (\Throwable $e) {
                Log::warning('Relay cache purge failed', ['message' => $e->getMessage()]);
            }

            try {
                if (!empty($file->tg_file_id)) {
                    app(\App\Services\Relay\RelayManager::class)->telegram()->deleteFile((string) $file->tg_file_id, true);
                }
            } catch (\Throwable $e) {
                Log::warning('Relay Telegram purge failed', ['message' => $e->getMessage()]);
            }

            \App\RelayEvent::where('relay_file_id', $file->id)->delete();
            $file->delete();
        }

        $link->delete();

        return true;
    }

    public static function saveFileAndGivePath($file)
    {
        $storageType = (int) (GlobalSettings::first()->storage_type ?? 0);

        if ($storageType === GlobalSettings::STORAGE_COMBINED) {
            $relayUrl = self::saveFileToRelay($file);

            if ($relayUrl !== null) {
                return $relayUrl;
            }
        }

        $storageConfig = [
            1 => ['disk' => 's3', 'base_url' => env('ITEM_BASE_URL')],
            2 => ['disk' => 'digitalocean', 'base_url' => env('DO_SPACE_URL')],
        ];

        $storageDisk = $storageConfig[$storageType]['disk'] ?? 'public';
        $baseUrl = $storageConfig[$storageType]['base_url'] ?? env('APP_URL') . 'storage/';

        $fileName = time().'_'.env('APP_NAME').'_'.str_replace(" ", "_", $file->getClientOriginalName());
        $appName = env('APP_NAME') ? env('APP_NAME') . '/' : '';
        $filePath = ($storageDisk === 'public') ? 'uploads/' . $fileName : $appName . 'uploads/' . $fileName;

        Storage::disk($storageDisk)->put($filePath, file_get_contents($file), 'public');

        return $baseUrl . $filePath;
    }

    /**
     * Send an uploaded file into the relay (Telegram Drive primary + 9drive
     * cache) and return its public link on this site's domain.
     *
     * Returns null when the relay is unavailable so the caller can fall back
     * to local storage instead of losing the upload.
     */
    protected static function saveFileToRelay($file)
    {
        try {
            $storage = app(\App\Services\Relay\RelayStorage::class);

            if (!$storage->isReady()) {
                return null;
            }

            return $storage->storeUploadedFile($file);
        } catch (\Throwable $e) {
            Log::warning('Relay storage failed; falling back to the local disk.', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function saveImageFromUrl($url)
    {
        if ($url != null) {
            $storageType = (int) (GlobalSettings::first()->storage_type ?? 0);

            if ($storageType === GlobalSettings::STORAGE_COMBINED) {
                try {
                    $storage = app(\App\Services\Relay\RelayStorage::class);

                    if ($storage->isReady()) {
                        $relayUrl = $storage->storeUrl($url);

                        if ($relayUrl !== null) {
                            return $relayUrl;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Relay image import failed; falling back to the local disk.', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $storageConfig = [
                1 => ['disk' => 's3', 'base_url' => env('ITEM_BASE_URL')],
                2 => ['disk' => 'digitalocean', 'base_url' => env('DO_SPACE_URL')],
            ];

            $storageDisk = $storageConfig[$storageType]['disk'] ?? 'public';
            $baseUrl = $storageConfig[$storageType]['base_url'] ?? env('APP_URL') . 'storage/';

            $contents = file_get_contents($url);
            $fileName = uniqid() . '_' . basename($url);
            $appName = env('APP_NAME') ? env('APP_NAME') . '/' : '';
            $filePath = ($storageDisk === 'public') ? 'uploads/' . $fileName : $appName . 'uploads/' . $fileName;

            Storage::disk($storageDisk)->put($filePath, $contents, 'public');

            return $baseUrl . $filePath;
        } else {
            return null;
        }
    }


    public static function saveSubtitleFileAsSrt($file)
    {
        if ($file != null) {
            $storageType = (int) (GlobalSettings::first()->storage_type ?? 0);

            $extension = $file->getClientOriginalExtension(); // Get the original file extension
            $filename = time() . uniqid() . '.' . $extension; // Create a unique filename with the correct extension

            if ($storageType === GlobalSettings::STORAGE_COMBINED) {
                try {
                    $storage = app(\App\Services\Relay\RelayStorage::class);

                    if ($storage->isReady()) {
                        return $storage->storeUploadedFile($file, $filename);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Relay subtitle upload failed; falling back to the local disk.', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $storageConfig = [
                1 => ['disk' => 's3', 'base_url' => env('ITEM_BASE_URL')],
                2 => ['disk' => 'digitalocean', 'base_url' => env('DO_SPACE_URL')],
            ];

            $storageDisk = $storageConfig[$storageType]['disk'] ?? 'public';
            $baseUrl = $storageConfig[$storageType]['base_url'] ?? env('APP_URL') . 'storage/';

            $extension = $file->getClientOriginalExtension(); // Get the original file extension
            $filename = time() . uniqid() . '.' . $extension; // Create a unique filename with the correct extension
            $appName = env('APP_NAME') ? env('APP_NAME') . '/' : '';
            $filePath = ($storageDisk === 'public') ? 'uploads/' . $filename : $appName . 'uploads/' . $filename;

            Storage::disk($storageDisk)->put($filePath, file_get_contents($file), 'public');

            return $baseUrl . $filePath;
        } else {
            return null;
        }
    }

}
