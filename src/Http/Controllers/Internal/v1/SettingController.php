<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SettingController extends Controller
{
	/**
	 * Simple admin overview metrics -- v1
	 *
	 * @return void
	 */
	public function adminOverview() {
		$metrics = [];
		$metrics['total_users'] = \Fleetbase\Models\User::all()->count();
		$metrics['total_organizations'] = \Fleetbase\Models\Company::all()->count();
		$metrics['total_transactions'] = \Fleetbase\Models\Transaction::all()->count();

		return response()->json($metrics);
	}

	/**
	 * Loads and sends the filesystem configuration.
	 *
	 *  @return \Illuminate\Http\JsonResponse
	 */
	public function getFilesystemConfig()
	{
		$driver = config('filesystems.default');
		$disks = array_keys(config('filesystems.disks', []));

		// additional configurables
		$s3Bucket = config('filesystems.disks.s3.bucket');
		$s3Url = config('filesystems.disks.s3.url');
		$s3Endpoint = config('filesystems.disks.s3.endpoint');

		return response()->json([
			'driver' => $driver,
			'disks' => $disks,
			's3Bucket' => $s3Bucket,
			's3Url' => $s3Url,
			's3Endpoint' => $s3Endpoint,
		]);
	}

	/**
	 * Saves filesystem configuration.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function saveFilesystemConfig(Request $request)
	{
		$driver = $request->input('driver', 'local');
		$s3 = $request->input('s3', config('filesystems.disks.s3'));

		Setting::configure('system.filesystem.driver', $driver);
		Setting::configure('system.filesystem.s3', array_merge(config('filesystems.disks.s3', []), $s3));

		return response()->json(['status' => 'OK']);
	}

	/**
	 * Loads and sends the mail configuration.
	 *
	 *  @return \Illuminate\Http\JsonResponse
	 */
	public function getMailConfig()
	{
		$mailer = config('mail.default');
		$from = config('mail.from');
		$mailers = array_keys(config('mail.mailers', []));
		$smtpConfig = config('mail.mailers.smtp');

		$config = [
			'mailer' => $mailer,
			'mailers' => $mailers,
			'fromAddress' => data_get($from, 'address'),
			'fromName' => data_get($from, 'name'),
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
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function saveMailConfig(Request $request)
	{
		$mailer = $request->input('mailer', 'smtp');
		$from = $request->input('from', []);
		$smtp = $request->input('smtp', []);

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
	 * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the authenticated user.
	 * @return \Illuminate\Http\JsonResponse Returns a JSON response with a success message and HTTP status 200.
	 *
	 * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the authenticated user is not found.
	 * @throws \Illuminate\Mail\MailerException If the email fails to send.
	 */
	public function testMailConfig(Request $request)
	{
		$mailer = $request->input('mailer', 'smtp');
		$from = $request->input('from', []);
		$smtp = $request->input('smtp', []);
		$user = $request->user();
		$message = 'Mail configuration is successful, check your inbox for the test email to confirm.';
		$status = 'success';

		// set config values from input
		config(['mail.default' => $mailer, 'mail.from' => $from, 'mail.mailers.smtp' => array_merge(['transport' => 'smtp'], $smtp)]);

		try {
			Mail::to($user)->send(new \Fleetbase\Mail\TestEmail());
		} catch (\Exception $e) {
			$message = $e->getMessage();
			$status = 'error';
		}

		return response()->json(['status' => $status, 'message' => $message]);
	}

	/**
	 * Loads and sends the queue configuration.
	 *
	 *  @return \Illuminate\Http\JsonResponse
	 */
	public function getQueueConfig()
	{
		$driver = config('queue.default');
		$connections = array_keys(config('queue.connections', []));

		// additional configurables
		$beanstalkdHost = config('queue.connections.beanstalkd.host');
		$beanstalkdQueue = config('queue.connections.beanstalkd.queue');
		$sqsPrefix = config('queue.connections.sqs.prefix');
		$sqsQueue = config('queue.connections.sqs.queue');
		$sqsSuffix = config('queue.connections.sqs.suffix');

		return response()->json([
			'driver' => $driver,
			'connections' => $connections,
			'beanstalkdHost' => $beanstalkdHost,
			'beanstalkdQueue' => $beanstalkdQueue,
			'sqsPrefix' => $sqsPrefix,
			'sqsQueue' => $sqsQueue,
			'sqsSuffix' => $sqsSuffix,
		]);
	}

	/**
	 * Saves queue configuration.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function saveQueueConfig(Request $request)
	{
		$driver = $request->input('driver', 'sync');
		$sqs = $request->input('sqs', config('queue.connections.sqs'));
		$beanstalkd = $request->input('beanstalkd', config('queue.connections.beanstalkd'));

		Setting::configure('system.queue.driver', $driver);
		Setting::configure('system.queue.sqs', array_merge(config('queue.connections.sqs'), $sqs));
		Setting::configure('system.queue.beanstalkd', array_merge(config('queue.connections.beanstalkd'), $beanstalkd));

		return response()->json(['status' => 'OK']);
	}

	/**
	 * Loads and sends the services configuration.
	 *
	 *  @return \Illuminate\Http\JsonResponse
	 */
	public function getServicesConfig()
	{
		/** aws service */
		$awsKey = config('services.aws.key', env('AWS_ACCESS_KEY_ID'));
		$awsSecret = config('services.aws.secret', env('AWS_SECRET_ACCESS_KEY'));
		$awsRegion = config('services.aws.region', env('AWS_DEFAULT_REGION', 'us-east-1'));
	
		/** ipinfo service */
		$ipinfoApiKey = config('services.ipinfo.api_key', env('IPINFO_API_KEY'));
	
		/** google maps service */
		$googleMapsApiKey = config('services.google_maps.api_key', env('GOOGLE_MAPS_API_KEY'));
		$googleMapsLocale = config('services.google_maps.locale', env('GOOGLE_MAPS_LOCALE', 'us'));
	
		/** twilio service */
		$twilioSid = config('services.twilio.sid', env('TWILIO_SID'));
		$twilioToken = config('services.twilio.token', env('TWILIO_TOKEN'));
		$twilioFrom = config('services.twilio.from', env('TWILIO_FROM'));

		return response()->json([
			'awsKey' => $awsKey,
			'awsSecret' => $awsSecret,
			'awsRegion' => $awsRegion,
			'ipinfoApiKey' => $ipinfoApiKey,
			'googleMapsApiKey' => $googleMapsApiKey,
			'googleMapsLocale' => $googleMapsLocale,
			'twilioSid' => $twilioSid,
			'twilioToken' => $twilioToken,
			'twilioFrom' => $twilioFrom,
		]);
	}

	/**
	 * Saves services configuration.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function saveServicesConfig(Request $request)
	{
		$aws = $request->input('aws', config('services.aws'));
		$ipinfo = $request->input('ipinfo', config('services.ipinfo'));
		$googleMaps = $request->input('googleMaps', config('services.google_maps'));
		$twilio = $request->input('twilio', config('services.twilio'));

		Setting::configure('system.services.aws', array_merge(config('services.aws', []), $aws));
		Setting::configure('system.services.ipinfo', array_merge(config('services.ipinfo', []), $ipinfo));
		Setting::configure('system.services.google_maps', array_merge(config('services.google_maps', []), $googleMaps));
		Setting::configure('system.services.twilio', array_merge(config('services.twilio', []), $twilio));

		return response()->json(['status' => 'OK']);
	}

	/**
	 * Get branding settings.
	 *
	 * @param  \Illuminate\Http\Request  $request
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
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function saveBrandingSettings(Request $request)
	{
		$iconUrl = $request->input('brand.icon_url');
		$logoUrl = $request->input('brand.logo_url');

		if ($iconUrl) {
			Setting::configure('branding.icon_url', $iconUrl);
		}

		if ($logoUrl) {
			Setting::configure('branding.logo_url', $logoUrl);
		}

		$brandingSettings = Setting::getBranding();
		
		return response()->json(['brand' => $brandingSettings]);
	}
}
