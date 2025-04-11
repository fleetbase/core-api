<?php

namespace Fleetbase\Support;

use Fleetbase\Models\File;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kreait\Laravel\Firebase\FirebaseProjectManager;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use Pushok\AuthProvider\Token as PuskOkToken;
use Pushok\Client as PushOkClient;

class PushNotification
{
    public static function createApnMessage(string $title, string $body, array $data = [], ?string $action = null): ApnMessage
    {
        $client     = static::getApnClient();
        $message    = ApnMessage::create()
            ->badge(1)
            ->title($title)
            ->body($body);

        foreach ($data as $key => $value) {
            $message->custom($key, $value);
        }

        if ($action) {
            $message->action($action, $data);
        }

        $message->via($client);

        return $message;
    }

    public static function createFcmMessage(string $title, string $body, array $data = []): FcmMessage
    {
        // Configure FCM
        static::configureFcmClient();

        // Get FCM Client using Notification Channel
        $container      = Container::getInstance();
        $projectManager = new FirebaseProjectManager($container);
        $client         = $projectManager->project('app')->messaging();

        // Create Notification
        $notification = new FcmNotification(
            title: $title,
            body: $body
        );

        return (new FcmMessage(notification: $notification))
            ->data($data)
            ->custom([
                'android' => [
                    'notification' => [
                        'color' => '#4391EA',
                        'sound' => 'default',
                    ],
                    'fcm_options' => [
                        'analytics_label' => 'analytics',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                    'fcm_options' => [
                        'analytics_label' => 'analytics',
                    ],
                ],
            ])
            ->usingClient($client);
    }

    public static function getApnClient(): PushOkClient
    {
        $config = config('broadcasting.connections.apn');

        // Get the APN key file and it's contents and store to config
        if (is_array($config) && isset($config['private_key_file_id']) && Str::isUuid($config['private_key_file_id'])) {
            $apnKeyFile = File::where('uuid', $config['private_key_file_id'])->first();
            if ($apnKeyFile) {
                $apnKeyFileContents = Storage::disk($apnKeyFile->disk)->get($apnKeyFile->path);
                if ($apnKeyFileContents) {
                    $config['private_key_content'] = str_replace('\\n', "\n", trim($apnKeyFileContents));
                }
            }
        }

        // Always unsetset apn `private_key_path` and `private_key_file`
        unset($config['private_key_path'], $config['private_key_file']);

        $isProductionEnv = Utils::castBoolean(data_get($config, 'production', app()->isProduction()));

        return new PushOkClient(PuskOkToken::create($config), $isProductionEnv);
    }

    public static function configureFcmClient()
    {
        $config = config('firebase.projects.app');

        if (is_array($config) && isset($config['credentials_file_id']) && Str::isUuid($config['credentials_file_id'])) {
            $firebaseCredentialsFile = File::where('uuid', $config['credentials_file_id'])->first();
            if ($firebaseCredentialsFile) {
                $firebaseCredentialsContent = Storage::disk($firebaseCredentialsFile->disk)->get($firebaseCredentialsFile->path);
                if ($firebaseCredentialsContent) {
                    $firebaseCredentialsContentArray = json_decode($firebaseCredentialsContent, true);
                    if (is_array($firebaseCredentialsContentArray)) {
                        $firebaseCredentialsContentArray['private_key'] =  str_replace('\\n', "\n", trim($firebaseCredentialsContentArray['private_key']));
                    }
                    $config['credentials'] = $firebaseCredentialsContentArray;
                }
            }
        }

        // Always unset apn `credentials_file`
        unset($config['credentials_file']);

        // Update config
        config(['firebase.projects.app' => $config]);

        return $config;
    }
}
