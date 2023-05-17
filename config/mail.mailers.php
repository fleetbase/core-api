<?php

/*
|--------------------------------------------------------------------------
| Mailer Configurations
|--------------------------------------------------------------------------
|
| Here you may configure all of the mailers used by your application plus
| their respective settings. Several examples have been configured for
| you and you are free to add your own as your application requires.
|
| Laravel supports a variety of mail "transport" drivers to be used while
| sending an e-mail. You will specify which one you are using for your
| mailers below. You are free to add additional mailers as required.
|
| Supported: "smtp", "sendmail", "mailgun", "ses",
|            "postmark", "log", "array"
|
*/
return [
    'ses' => [
        'transport' => 'ses',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'version' => '2010-12-01'
    ],
];
