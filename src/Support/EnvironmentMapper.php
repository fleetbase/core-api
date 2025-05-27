<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The EnvironmentMapper class is responsible for mapping system settings stored in the database
 * to environment variables and configuration settings within the Laravel application. This allows
 * dynamic configuration of the application based on database-stored settings.
 *
 * The class includes methods to set environment variables, merge configuration settings from the database,
 * and handle specific configurations such as the default mail address.
 */
class EnvironmentMapper
{
    /**
     * @var array
     *
     * An associative array mapping environment variable names to their corresponding
     * configuration paths within the application. This array is used to set environment
     * variables based on system settings stored in the database.
     */
    protected static array $environmentVariables         = [
        'AWS_ACCESS_KEY_ID'                    => 'services.aws.key',
        'AWS_SECRET_ACCESS_KEY'                => 'services.aws.secret',
        'AWS_DEFAULT_REGION'                   => 'services.aws.region',
        'AWS_BUCKET'                           => 'filesystems.disks.s3',
        'FILESYSTEM_DRIVER'                    => 'filesystems.default',
        'QUEUE_CONNECTION'                     => 'queue.default',
        'SQS_PREFIX'                           => 'queue.connections.sqs',
        'MAIL_MAILER'                          => 'mail.default',
        'MAIL_FROM_ADDRESS'                    => 'mail.from',
        'MAIL_HOST'                            => 'mail.mailers.smtp',
        'TWILIO_SID'                           => 'services.twilio.sid',
        'TWILIO_TOKEN'                         => 'services.twilio.token',
        'TWILIO_FROM'                          => 'services.twilio.from',
        'GOOGLE_MAPS_API_KEY'                  => 'services.google_maps.api_key',
        'GOOGLE_MAPS_LOCALE'                   => 'services.google_maps.locale',
        'GOOGLE_CLOUD_PROJECT_ID'              => 'filesystem.gcs.project_id',
        'GOOGLE_CLOUD_KEY_FILE'                => 'filesystem.gcs.key_file',
        'GOOGLE_CLOUD_KEY_FILE_ID'             => 'filesystem.gcs.key_file_id',
        'GOOGLE_CLOUD_STORAGE_BUCKET'          => 'filesystem.gcs.bucket',
        'GOOGLE_CLOUD_STORAGE_PATH_PREFIX'     => 'filesystem.gcs.path_prefix',
        'GOOGLE_CLOUD_STORAGE_API_URI'         => 'filesystem.gcs.storage_api_uri',
        'SENTRY_DSN'                           => 'services.sentry.dsn',
        'IPINFO_API_KEY'                       => 'services.ipinfo.api_key',
        'MAILGUN_DOMAIN'                       => 'services.mailgun.domain',
        'MAILGUN_SECRET'                       => 'services.mailgun.secret',
        'MAILGUN_ENDPOINT'                     => 'services.mailgun.endpoint',
        'POSTMARK_TOKEN'                       => 'services.postmark.token',
        'SENDGRID_API_KEY'                     => 'services.sendgrid.api_key',
        'RESEND_KEY'                           => 'services.resend.key',
    ];

    /**
     * @var array
     *
     * An array of associative arrays where each entry maps a system setting key to a
     * corresponding configuration key in the application. This array is used to merge
     * system settings into the application's configuration.
     */
    protected static array $settings = [
        ['settingsKey' => 'filesystem.driver', 'configKey' => 'filesystems.default'],
        ['settingsKey' => 'filesystem.s3', 'configKey' => 'filesystems.disks.s3'],
        ['settingsKey' => 'filesystem.gcs', 'configKey' => 'filesystems.disks.gcs'],
        ['settingsKey' => 'mail.mailer', 'configKey' => 'mail.default'],
        ['settingsKey' => 'mail.from', 'configKey' => 'mail.from'],
        ['settingsKey' => 'mail.smtp', 'configKey' => 'mail.mailers.smtp'],
        ['settingsKey' => 'queue.driver', 'configKey' => 'queue.default'],
        ['settingsKey' => 'queue.sqs', 'configKey' => 'queue.connections.sqs'],
        ['settingsKey' => 'queue.beanstalkd', 'configKey' => 'queue.connections.beanstalkd'],
        ['settingsKey' => 'services.aws', 'configKey' => 'services.aws'],
        ['settingsKey' => 'services.aws.key', 'configKey' => 'queue.connections.sqs.key'],
        ['settingsKey' => 'services.aws.secret', 'configKey' => 'queue.connections.sqs.secret'],
        ['settingsKey' => 'services.aws.region', 'configKey' => 'queue.connections.sqs.region'],
        ['settingsKey' => 'services.aws.key', 'configKey' => 'cache.stores.dynamodb.key'],
        ['settingsKey' => 'services.aws.secret', 'configKey' => 'cache.stores.dynamodb.secret'],
        ['settingsKey' => 'services.aws.region', 'configKey' => 'cache.stores.dynamodb.region'],
        ['settingsKey' => 'services.aws.key', 'configKey' => 'filesystems.disks.s3.key'],
        ['settingsKey' => 'services.aws.secret', 'configKey' => 'filesystems.disks.s3.secret'],
        ['settingsKey' => 'services.aws.region', 'configKey' => 'filesystems.disks.s3.region'],
        ['settingsKey' => 'services.aws.key', 'configKey' => 'mail.mailers.ses.key'],
        ['settingsKey' => 'services.aws.secret', 'configKey' => 'mail.mailers.ses.secret'],
        ['settingsKey' => 'services.aws.region', 'configKey' => 'mail.mailers.ses.region'],
        ['settingsKey' => 'services.aws.key', 'configKey' => 'services.ses.key'],
        ['settingsKey' => 'services.aws.secret', 'configKey' => 'services.ses.secret'],
        ['settingsKey' => 'services.aws.region', 'configKey' => 'services.ses.region'],
        ['settingsKey' => 'services.google_maps', 'configKey' => 'services.google_maps'],
        ['settingsKey' => 'services.twilio', 'configKey' => 'services.twilio'],
        ['settingsKey' => 'services.twilio', 'configKey' => 'twilio.twilio.connections.twilio'],
        ['settingsKey' => 'services.ipinfo', 'configKey' => 'services.ipinfo'],
        ['settingsKey' => 'services.ipinfo', 'configKey' => 'fleetbase.services.ipinfo'],
        ['settingsKey' => 'services.mailgun', 'configKey' => 'services.mailgun'],
        ['settingsKey' => 'services.postmark', 'configKey' => 'services.postmark'],
        ['settingsKey' => 'services.sendgrid', 'configKey' => 'services.sendgrid'],
        ['settingsKey' => 'services.resend', 'configKey' => 'services.resend'],
        ['settingsKey' => 'services.sentry.dsn', 'configKey' => 'sentry.dsn'],
        ['settingsKey' => 'broadcasting.apn', 'configKey' => 'broadcasting.connections.apn'],
        ['settingsKey' => 'firebase.app', 'configKey' => 'firebase.projects.app'],
    ];

    /**
     * Retrieves environment variables that are not already set in the current environment
     * but are defined in the system settings. This method returns an array of environment
     * variables that can be set based on the system's configuration.
     *
     * @return array
     *               An associative array where the keys are the environment variable names and
     *               the values are the corresponding configuration paths
     */
    protected static function getSettableEnvironmentVairables(): array
    {
        $settableEnvironmentVariables = [];
        foreach (static::$environmentVariables as $variable => $configPath) {
            if (Utils::isEmpty(env($variable))) {
                $settableEnvironmentVariables[$variable] = $configPath;
            }
        }

        return $settableEnvironmentVariables;
    }

    /**
     * Sets environment variables for the application based on the system settings stored
     * in the database. This method checks if the environment variable is not already set
     * and then sets it using the corresponding value from the system settings.
     *
     * If the database connection is not available, the method exits early.
     */
    public static function setEnvironmentVariables(): void
    {
        if (Setting::doesntHaveConnection() || env('CI')) {
            return;
        }

        $environmentVariables = static::getSettableEnvironmentVairables();
        foreach ($environmentVariables as $variable => $configPath) {
            $value = Setting::system($configPath);
            if ($value && is_string($value) && !empty($value)) {
                putenv($variable . '="' . $value . '"');
            }
        }
    }

    /**
     * Merges system settings from the database into the application's configuration.
     * This method first sets any environment variables that can be derived from the
     * system settings and then merges specific settings into the configuration.
     *
     * It also handles special configuration logic, such as setting a default mail
     * address if none is specified.
     *
     * If the database connection is not available, the method exits early.
     */
    public static function mergeConfigFromSettings(): void
    {
        if (Setting::doesntHaveConnection()) {
            return;
        }

        static::setEnvironmentVariables();
        foreach (static::$settings as $setting) {
            $settingsKey = $setting['settingsKey'];
            $configKey   = $setting['configKey'];

            static::mergeConfig($settingsKey, $configKey);
        }

        static::setDefaultMailFrom();
    }

    /**
     * Merges a single system setting from the database into the application's configuration.
     * The method fetches the setting value and updates the application's configuration at
     * the specified config key.
     *
     * If the database connection is not available, the method exits early.
     *
     * @param string $settingsKey
     *                            The key in the system settings that contains the value to be merged into the configuration
     * @param string $configKey
     *                            The key in the application's configuration that should be updated with the value from the system settings
     */
    protected static function mergeConfig(string $settingsKey, string $configKey): void
    {
        if (Setting::doesntHaveConnection()) {
            return;
        }

        $value = Setting::system($settingsKey);
        // Merge config values for array instead of complete overwrite
        if (is_array($value) && is_array(config($configKey))) {
            $value = array_merge(config($configKey), array_filter($value));
        }

        if ($value) {
            // Fetch the current config array
            $config = config()->all();
            // Update the specific value in the config array
            Arr::set($config, $configKey, $value);
            // Set the entire config array
            config($config);
        }
    }

    /**
     * Sets the default "from" email address for the application if it is not already
     * specified in the configuration. The method uses a utility function to retrieve
     * a default value and updates the mail configuration accordingly.
     */
    protected static function setDefaultMailFrom(): void
    {
        if (empty(config('mail.from.address'))) {
            config()->set('mail.from.address', Utils::getDefaultMailFromAddress());
        }
    }

    /**
     * Optimized merge of database settings into application config.
     *
     * Batch-fetches all relevant setting records in one query, builds
     * a combined config payload, and applies it with a single config() call.
     */
    public static function mergeConfigFromSettingsOptimized(): void
    {
        if (Setting::doesntHaveConnection()) {
            return;
        }

        // Build DB keys for env vars (prefixed with 'system.')
        $envDbKeys = array_map(fn ($path) => Str::startsWith($path, 'system.')
            ? $path
            : 'system.' . $path, array_values(static::$environmentVariables)
        );

        // Build DB keys for settings (use first two segments for nested keys)
        $settingDbKeys = array_map(function ($map) {
            $key      = $map['settingsKey'];
            $segments = explode('.', $key);
            if (count($segments) >= 3) {
                return 'system.' . $segments[0] . '.' . $segments[1];
            }

            return Str::startsWith($key, 'system.')
                ? $key
                : 'system.' . $key;
        }, static::$settings);

        // Unique list of keys to fetch
        $fetchKeys = array_unique(array_merge($envDbKeys, $settingDbKeys));

        // Fetch all values in one query
        $dbSettings = Setting::whereIn('key', $fetchKeys)
            ->pluck('value', 'key')
            ->all();

        // Apply environment variables
        static::setEnvironmentVariablesOptimized($dbSettings);

        // Build new config payload
        $newConfig = [];
        foreach (static::$settings as $map) {
            $settingsKey = $map['settingsKey'];
            $configKey   = $map['configKey'];
            $segments    = explode('.', $settingsKey);

            // Determine the DB key we fetched
            $dbKey = count($segments) >= 3
                ? 'system.' . $segments[0] . '.' . $segments[1]
                : 'system.' . $settingsKey;

            if (!isset($dbSettings[$dbKey])) {
                continue;
            }

            $rawValue = $dbSettings[$dbKey];

            // Extract nested subkey if needed
            $value = count($segments) >= 3
                ? data_get($rawValue, implode('.', array_slice($segments, 2)))
                : $rawValue;

            // Merge arrays rather than overwrite
            if (is_array($value) && is_array(config($configKey))) {
                $value = array_merge(config($configKey), array_filter($value));
            }

            $newConfig[$configKey] = $value;
        }

        // Apply all config changes at once
        if (!empty($newConfig)) {
            config($newConfig);
        }

        // Ensure default mail 'from' fallback
        static::setDefaultMailFrom();
    }

    /**
     * Sets environment variables using pre-fetched DB settings.
     *
     * @param array<string,mixed> $dbSettings Map of DB key => raw value
     */
    protected static function setEnvironmentVariablesOptimized(array $dbSettings): void
    {
        foreach (static::$environmentVariables as $envVar => $configPath) {
            $dbKey = Str::startsWith($configPath, 'system.')
                ? $configPath
                : 'system.' . $configPath;

            if (empty(env($envVar)) && isset($dbSettings[$dbKey])) {
                $value = $dbSettings[$dbKey];
                if (is_string($value) && $value !== '') {
                    putenv(sprintf('%s="%s"', $envVar, addcslashes($value, '"')));
                }
            }
        }
    }
}
