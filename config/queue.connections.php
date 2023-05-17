<?php

// sqs config for jobs queue
$sqs_jobs_prefix = env('SQS_PREFIX');
$sqs_jobs_queue = env('SQS_JOBS_QUEUE', 'jobs');
if ($queueUrl = getenv('QUEUE_URL_JOBS')) {
    $url = parse_url($queueUrl);

    $sqs_jobs_prefix = $url['scheme'] . '://' . $url['host'] . dirname($url['path']);
    $sqs_jobs_queue = basename($url['path']);
}

// sqs config for events queue
$sqs_events_prefix = env('SQS_PREFIX');
$sqs_events_queue = env('SQS_EVENTS_QUEUE', 'events');
if ($queueUrl = getenv('QUEUE_URL_EVENTS')) {
    $url = parse_url($queueUrl);

    $sqs_events_prefix = $url['scheme'] . '://' . $url['host'] . dirname($url['path']);
    $sqs_events_queue = basename($url['path']);
}

/*
|--------------------------------------------------------------------------
| Queue Connections
|--------------------------------------------------------------------------
|
| Here you may configure the connection information for each server that
| is used by your application. A default configuration has been added
| for each back-end shipped with Laravel. You are free to add more.
|
| Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
|
*/
return [
    'sync' => [
        'driver' => 'sync',
    ],

    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ],

    'beanstalkd' => [
        'driver' => 'beanstalkd',
        'host' => 'localhost',
        'queue' => 'default',
        'retry_after' => 90,
        'block_for' => 0,
        'after_commit' => false,
    ],

    'sqs' => [
        'driver' => 'sqs',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'prefix' => $sqs_jobs_prefix,
        'queue' => $sqs_jobs_queue,
        'suffix' => env('SQS_SUFFIX'),
        'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
    ],

    'events' => [
        'driver' => 'sqs',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'prefix' => $sqs_events_prefix,
        'queue' => $sqs_events_queue,
        'suffix' => env('SQS_SUFFIX'),
        'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
    ],

    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
        'after_commit' => false,
    ],
];
