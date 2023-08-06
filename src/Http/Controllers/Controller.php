<?php

namespace Fleetbase\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Welcome message only
     */
    public function hello()
    {
        return response()->json(
            [
                'message' => 'Fleetbase API',
                'version' => config('fleetbase.api.version'),
            ]
        );
    }

    /**
     * Response time only
     */
    public function time()
    {
        return response()->json(
            [
                'ms' => microtime(true) - LARAVEL_START,
            ]
        );
    }

    /**
     * Use this route for arbitrary testing.
     */
    public function test()
    {
        $file = storage_path('app/public/testfile.txt');
        $response = [];

        $response[] = 'AWS: ' . json_encode([
            'AWS_DEFAULT_REGION' => env('AWS_DEFAULT_REGION'),
            'AWS_BUCKET' => env('AWS_BUCKET'),
            'AWS_BUCKET_ENDPOINT' => env('AWS_BUCKET_ENDPOINT'),
        ], JSON_PRETTY_PRINT);
        $response[] = 'S3 Config: ' . json_encode(config('filesystems.disks.s3'), JSON_PRETTY_PRINT);

        try {
            $path = \Illuminate\Support\Facades\Storage::disk('s3')->put('testfile.txt', file_get_contents($file));
            $response[] = 'S3: Successful';
            $response[] = 'S3 Uploaded: ' . $path;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $response[] = 'S3: ' . $e->getMessage();
        } catch (\Exception $e) {
            $response[] = 'S3: ' . $e->getMessage();
        }

        $response[] = 'SES Config: ' . json_encode(config('mail.mailers.ses'), JSON_PRETTY_PRINT);

        try {
            \Illuminate\Support\Facades\Mail::raw('This is a test email from Laravel SES.', function ($message) {
                $message->from('hello@fleetbase.io', 'Fleetbase')->to('ron@fleetbase.io', 'Ron')->subject('SES Test Email');
            });
            $response[] = 'SES: Successful';
        } catch (\Aws\Ses\Exception\SesException $e) {
            $response[] = 'SES: ' . $e->getMessage();
        } catch (\Swift_TransportException $e) {
            dd($e);
            $response[] = 'SES: ' . $e->getMessage();
        } catch (\Exception $e) {
            $response[] = 'SES: ' . $e->getMessage();
        }

        $response[] = 'SQS Config: ' . json_encode(config('queue.connections.sqs'), JSON_PRETTY_PRINT);
        $queue = config('queue.connections.sqs.queue');

        try {
            \Illuminate\Support\Facades\Queue::pushRaw(['data' => 'This is a test message.'], $queue);
            $response[] = 'SQS: Successful';
        } catch (\Aws\Sqs\Exception\SqsException $e) {
            $response[] = 'SQS: ' . $e->getMessage();
        } catch (\Exception $e) {
            $response[] = 'SQS: ' . $e->getMessage();
        }

        return response(implode('<br />------<br />', $response), 200)->header('Content-Type', 'text/html');
    }
}
