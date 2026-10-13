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

    public static function saveFileAndGivePath($file)
    {
        $storageType = GlobalSettings::first()->storage_type;

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

    public static function saveImageFromUrl($url)
    {
        if ($url != null) {
            $storageType = GlobalSettings::first()->storage_type;

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
            $storageType = GlobalSettings::first()->storage_type;

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
