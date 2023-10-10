<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\File;
use Fleetbase\Models\Setting;
use Fleetbase\Notifications\TestPushNotification;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;

class SettingController extends Controller
{
    /**
     * Simple admin overview metrics -- v1.
     *
     * @return void
     */
    public function adminOverview()
    {
        $metrics                        = [];
        $metrics['total_users']         = \Fleetbase\Models\User::all()->count();
        $metrics['total_organizations'] = \Fleetbase\Models\Company::all()->count();
        $metrics['total_transactions']  = \Fleetbase\Models\Transaction::all()->count();

        return response()->json($metrics);
    }

    /**
     * Loads and sends the filesystem configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFilesystemConfig()
    {
        $driver = config('filesystems.default');
        $disks  = array_keys(config('filesystems.disks', []));

        // additional configurables
        $s3Bucket   = config('filesystems.disks.s3.bucket');
        $s3Url      = config('filesystems.disks.s3.url');
        $s3Endpoint = config('filesystems.disks.s3.endpoint');

        return response()->json([
            'driver'     => $driver,
            'disks'      => $disks,
            's3Bucket'   => $s3Bucket,
            's3Url'      => $s3Url,
            's3Endpoint' => $s3Endpoint,
        ]);
    }

    /**
     * Saves filesystem configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveFilesystemConfig(Request $request)
    {
        $driver = $request->input('driver', 'local');
        $s3     = $request->input('s3', config('filesystems.disks.s3'));

        Setting::configure('system.filesystem.driver', $driver);
        Setting::configure('system.filesystem.s3', array_merge(config('filesystems.disks.s3', []), $s3));

        return response()->json(['status' => 'OK']);
    }

    /**
     * Creates a file and uploads it to the users default disks.
     *
     * @param \Illuminate\Http\Request $request the incoming HTTP request containing the authenticated user
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response with a success message and HTTP status 200
     */
    public function testFilesystemConfig(Request $request)
    {
        $disk    = $request->input('disk', config('filesystems.default'));
        $message = 'Filesystem configuration is successful, test file uploaded.';
        $status  = 'success';

        // set config values from input
        config(['filesystem.default' => $disk]);

        try {
            \Illuminate\Support\Facades\Storage::disk($disk)->put('testfile.txt', 'Hello World');
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $message = $e->getMessage();
            $status  = 'error';
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status  = 'error';
        }

        return response()->json(['status' => $status, 'message' => $message]);
    }

    /**
     * Loads and sends the mail configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMailConfig()
    {
        $mailer     = config('mail.default');
        $from       = config('mail.from');
        $mailers    = array_keys(config('mail.mailers', []));
        $smtpConfig = config('mail.mailers.smtp');

        $config = [
            'mailer'      => $mailer,
            'mailers'     => $mailers,
            'fromAddress' => data_get($from, 'address'),
            'fromName'    => data_get($from, 'name'),
        ];

        foreach ($smtpConfig as $key => $value) {
            if ($key === 'transport') {
                continue;
            }

            $config['smtp' . ucfirst($key)] = $value;
        }

        return response()->json($config);
    }

    /**
     * Saves mail configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveMailConfig(Request $request)
    {
        $mailer = $request->input('mailer', 'smtp');
        $from   = $request->input('from', []);
        $smtp   = $request->input('smtp', []);

        Setting::configure('system.mail.mailer', $mailer);
        Setting::configure('system.mail.from', $from);
        Setting::configure('system.mail.smtp', array_merge(['transport' => 'smtp'], $smtp));

        return response()->json(['status' => 'OK']);
    }

    /**
     * Sends a test email to the authenticated user.
     *
     * This function retrieves the authenticated user from the given request and sends a
     * test email to the user's email address. It returns a JSON response indicating whether
     * the email was sent successfully.
     *
     * @param \Illuminate\Http\Request $request the incoming HTTP request containing the authenticated user
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response with a success message and HTTP status 200
     */
    public function testMailConfig(Request $request)
    {
        $mailer = $request->input('mailer', 'smtp');
        $from   = $request->input(
            'from',
            [
                'address' => Utils::getDefaultMailFromAddress(),
                'name'    => 'Fleetbase',
            ]
        );
        $smtp    = $request->input('smtp', []);
        $user    = $request->user();
        $message = 'Mail configuration is successful, check your inbox for the test email to confirm.';
        $status  = 'success';

        // set config values from input
        config(['mail.default' => $mailer, 'mail.from' => $from, 'mail.mailers.smtp' => array_merge(['transport' => 'smtp'], $smtp)]);

        try {
            Mail::to($user)->send(new \Fleetbase\Mail\TestEmail());
        } catch (\Aws\Ses\Exception\SesException $e) {
            $message = $e->getMessage();
            $status  = 'error';
        } catch (\Swift_TransportException $e) {
            $message = $e->getMessage();
            $status  = 'error';
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status  = 'error';
        }

        return response()->json(['status' => $status, 'message' => $message]);
    }

    /**
     * Loads and sends the queue configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getQueueConfig()
    {
        $driver      = config('queue.default');
        $connections = array_keys(config('queue.connections', []));

        // additional configurables
        $beanstalkdHost  = config('queue.connections.beanstalkd.host');
        $beanstalkdQueue = config('queue.connections.beanstalkd.queue');
        $sqsPrefix       = config('queue.connections.sqs.prefix');
        $sqsQueue        = config('queue.connections.sqs.queue');
        $sqsSuffix       = config('queue.connections.sqs.suffix');

        return response()->json([
            'driver'          => $driver,
            'connections'     => $connections,
            'beanstalkdHost'  => $beanstalkdHost,
            'beanstalkdQueue' => $beanstalkdQueue,
            'sqsPrefix'       => $sqsPrefix,
            'sqsQueue'        => $sqsQueue,
            'sqsSuffix'       => $sqsSuffix,
        ]);
    }

    /**
     * Saves queue configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveQueueConfig(Request $request)
    {
        $driver     = $request->input('driver', 'sync');
        $sqs        = $request->input('sqs', config('queue.connections.sqs'));
        $beanstalkd = $request->input('beanstalkd', config('queue.connections.beanstalkd'));

        Setting::configure('system.queue.driver', $driver);
        Setting::configure('system.queue.sqs', array_merge(config('queue.connections.sqs'), $sqs));
        Setting::configure('system.queue.beanstalkd', array_merge(config('queue.connections.beanstalkd'), $beanstalkd));

        return response()->json(['status' => 'OK']);
    }

    /**
     * Sends a test message to the queue .
     *
     * @param \Illuminate\Http\Request $request the incoming HTTP request containing the authenticated user
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response with a success message and HTTP status 200
     */
    public function testQueueConfig(Request $request)
    {
        $queue   = $request->input('queue', config('queue.connections.sqs.queue'));
        $message = 'Queue configuration is successful, message sent to queue.';
        $status  = 'success';

        // set config values from input
        config(['queue.default' => $queue]);

        try {
            \Illuminate\Support\Facades\Queue::pushRaw(new \Illuminate\Support\MessageBag(['Hello World']));
        } catch (\Aws\Sqs\Exception\SqsException $e) {
            $message = $e->getMessage();
            $status  = 'error';
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status  = 'error';
        } catch (\Error $e) {
            $message = $e->getMessage();
            $status  = 'error';
        } catch (\ErrorException $e) {
            $message = $e->getMessage();
            $status  = 'error';
        }

        return response()->json(['status' => $status, 'message' => $message]);
    }

    /**
     * Loads and sends the services configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getServicesConfig()
    {
        /** aws service */
        $awsKey    = config('services.aws.key', env('AWS_ACCESS_KEY_ID'));
        $awsSecret = config('services.aws.secret', env('AWS_SECRET_ACCESS_KEY'));
        $awsRegion = config('services.aws.region', env('AWS_DEFAULT_REGION', 'us-east-1'));

        /** ipinfo service */
        $ipinfoApiKey = config('services.ipinfo.api_key', env('IPINFO_API_KEY'));

        /** google maps service */
        $googleMapsApiKey = config('services.google_maps.api_key', env('GOOGLE_MAPS_API_KEY'));
        $googleMapsLocale = config('services.google_maps.locale', env('GOOGLE_MAPS_LOCALE', 'us'));

        /** twilio service */
        $twilioSid   = config('services.twilio.sid', env('TWILIO_SID'));
        $twilioToken = config('services.twilio.token', env('TWILIO_TOKEN'));
        $twilioFrom  = config('services.twilio.from', env('TWILIO_FROM'));

        /** sentry service */
        $sentryDsn = config('sentry.dsn', env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')));

        return response()->json([
            'awsKey'           => $awsKey,
            'awsSecret'        => $awsSecret,
            'awsRegion'        => $awsRegion,
            'ipinfoApiKey'     => $ipinfoApiKey,
            'googleMapsApiKey' => $googleMapsApiKey,
            'googleMapsLocale' => $googleMapsLocale,
            'twilioSid'        => $twilioSid,
            'twilioToken'      => $twilioToken,
            'twilioFrom'       => $twilioFrom,
            'sentryDsn'        => $sentryDsn,
        ]);
    }

    /**
     * Saves services configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveServicesConfig(Request $request)
    {
        $aws        = $request->input('aws', config('services.aws'));
        $ipinfo     = $request->input('ipinfo', config('services.ipinfo'));
        $googleMaps = $request->input('googleMaps', config('services.google_maps'));
        $twilio     = $request->input('twilio', config('services.twilio'));
        $sentry     = $request->input('sentry', config('sentry.dsn'));

        Setting::configure('system.services.aws', array_merge(config('services.aws', []), $aws));
        Setting::configure('system.services.ipinfo', array_merge(config('services.ipinfo', []), $ipinfo));
        Setting::configure('system.services.google_maps', array_merge(config('services.google_maps', []), $googleMaps));
        Setting::configure('system.services.twilio', array_merge(config('services.twilio', []), $twilio));
        Setting::configure('system.services.sentry', array_merge(config('sentry', []), $sentry));

        return response()->json(['status' => 'OK']);
    }

    /**
     * Loads and sends the notification channel configurations.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNotificationChannelsConfig()
    {
        // get apn config
        $apn = config('broadcasting.connections.apn');

        if (is_array($apn) && isset($apn['private_key_file_id'])) {
            $apn['private_key_file'] = File::where('uuid', $apn['private_key_file_id'])->first();
        }

        return response()->json([
            'apn' => $apn,
        ]);
    }

    /**
     * Saves notification channels configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveNotificationChannelsConfig(Request $request)
    {
        $apn = $request->array('apn', config('broadcasting.connections.apn'));

        if (is_array($apn) && isset($apn['private_key_content'])) {
            $apn['private_key_content'] = str_replace('\\n', "\n", trim($apn['private_key_content']));
        }

        if (is_array($apn) && isset($apn['private_key_path']) && is_string($apn['private_key_path'])) {
            $apn['private_key_path'] = storage_path('app/' . $apn['private_key_path']);
        }

        Setting::configure('system.broadcasting.apn', array_merge(config('broadcasting.connections.apn', []), $apn));

        return response()->json(['status' => 'OK']);
    }

    /**
     * Test notification channels configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testNotificationChannelsConfig(Request $request)
    {
        $title    = $request->input('title', 'Hello World from Fleetbase 🚀');
        $message  = $request->input('message', 'This is a test push notification!');
        $apnToken = $request->input('apnToken');
        $fcmToken = $request->input('fcmToken');
        $apn      = $request->array('apn', config('broadcasting.connections.apn'));

        // temporarily set apn config here
        config(['broadcasting.connections.apn' => $apn]);

        // trigger test notification
        $notifiable = (new AnonymousNotifiable());

        if ($apnToken) {
            $notifiable->route('apn', $apnToken);
        }

        if ($fcmToken) {
            $notifiable->route('fcm', $fcmToken);
        }

        $status          = 'success';
        $responseMessage = 'Notification sent successfully.';

        try {
            $notifiable->notify(new TestPushNotification($title, $message));
        } catch (\NotificationChannels\Fcm\Exceptions\CouldNotSendNotification $e) {
            $responseMessage = $e->getMessage();
            $status          = 'error';
        } catch (\Throwable $e) {
            dd($e);
            $responseMessage = $e->getMessage();
            $status          = 'error';
        }

        return response()->json(['status' => $status, 'message' => $responseMessage]);
    }

    /**
     * Get branding settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBrandingSettings()
    {
        $brandingSettings = Setting::getBranding();

        return response()->json(['brand' => $brandingSettings]);
    }

    /**
     * Saves branding settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveBrandingSettings(Request $request)
    {
        $iconUuid     = $request->input('brand.icon_uuid');
        $logoUuid     = $request->input('brand.logo_uuid');
        $defaultTheme = $request->input('brand.default_theme');

        if ($defaultTheme) {
            Setting::configure('branding.default_theme', $defaultTheme);
        }

        if ($iconUuid) {
            Setting::configure('branding.icon_uuid', $iconUuid);
        }

        if ($logoUuid) {
            Setting::configure('branding.logo_uuid', $logoUuid);
        }

        $brandingSettings = Setting::getBranding();

        return response()->json(['brand' => $brandingSettings]);
    }

    /**
     * Sends a test SMS message using Twilio.
     *
     * @param \Illuminate\Http\Request $request the incoming HTTP request containing the authenticated user
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response with a success message and HTTP status 200
     */
    public function testTwilioConfig(Request $request)
    {
        $sid   = $request->input('sid');
        $token = $request->input('token');
        $from  = $request->input('from');
        $phone = $request->input('phone');

        if (!$phone) {
            return response()->json(['status' => 'error', 'message' => 'No test phone number provided!']);
        }

        // Set config from request
        config(['twilio.twilio.connections.twilio.sid' => $sid, 'twilio.twilio.connections.twilio.token' => $token, 'twilio.twilio.connections.twilio.from' => $from]);

        $message = 'Twilio configuration is successful, SMS sent to ' . $phone . '.';
        $status  = 'success';

        try {
            \Aloha\Twilio\Support\Laravel\Facade::message($phone, 'This is a Twilio test from Fleetbase');
        } catch (\Twilio\Exceptions\RestException $e) {
            $message = $e->getMessage();
            $status  = 'error';
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status  = 'error';
        } catch (\Error $e) {
            $message = $e->getMessage();
            $status  = 'error';
        } catch (\ErrorException $e) {
            $message = $e->getMessage();
            $status  = 'error';
        }

        return response()->json(['status' => $status, 'message' => $message]);
    }

    /**
     * Sends a test exception to Sentry.
     *
     * @param \Illuminate\Http\Request $request the incoming HTTP request containing the authenticated user
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response with a success message and HTTP status 200
     */
    public function testSentryConfig(Request $request)
    {
        $dsn = $request->input('dsn');

        // Set config from request
        config(['sentry.dsn' => $dsn]);

        $message = 'Sentry configuration is successful, test Exception sent.';
        $status  = 'success';

        try {
            $clientBuilder = \Sentry\ClientBuilder::create([
                'dsn'                => $dsn,
                'release'            => env('SENTRY_RELEASE'),
                'environment'        => app()->environment(),
                'traces_sample_rate' => 1.0,
            ]);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status  = 'error';
        }

        if ($clientBuilder) {
            // Set the Laravel SDK identifier and version
            $clientBuilder->setSdkIdentifier(\Sentry\Laravel\Version::SDK_IDENTIFIER);
            $clientBuilder->setSdkVersion(\Sentry\Laravel\Version::SDK_VERSION);

            // Create hub
            $hub = new \Sentry\State\Hub($clientBuilder->getClient());

            // Create test exception
            $testException = null;

            try {
                throw new \Exception('This is a test exception sent from the Sentry Laravel SDK.');
            } catch (\Exception $exception) {
                $testException = $exception;
            }

            try {
                // Capture test exception
                $hub->captureException($testException);
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $status  = 'error';
            }
        }

        return response()->json(['status' => $status, 'message' => $message]);
    }

    /**
     * Test SocketCluster Configuration.
     *
     * @param \Illuminate\Http\Request $request the incoming HTTP request containing the authenticated user
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response with a success message and HTTP status 200
     */
    public function testSocketcluster(Request $request)
    {
        // Get the channel to publish to
        $channel  = $request->input('channel', 'test');
        $message  = 'Socket broadcasted message successfully.';
        $status   = 'success';
        $sent     = false;
        $response = null;

        $socketClusterClient = new \Fleetbase\Support\SocketCluster\SocketClusterService();

        try {
            $sent = $socketClusterClient->send($channel, [
                'message' => 'Hello World',
                'sender'  => 'Fleetbase',
            ]);
            $response = $socketClusterClient->response();
        } catch (\WebSocket\ConnectionException $e) {
            $message = $e->getMessage();
        } catch (\WebSocket\TimeoutException $e) {
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }

        if (!$sent) {
            $status = 'error';
        }

        return response()->json(
            [
                'status'   => $status,
                'message'  => $message,
                'channel'  => $channel,
                'response' => $response,
            ]
        );
    }
}
