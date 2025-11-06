<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Setting;
use Illuminate\Support\Str;

/**
 * The EnvironmentMapper class is responsible for mapping system settings stored in the database
 * to environment variables and configuration settings within the Fleetbase application. This allows
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
    protected static array $environmentVariables = [
        'AWS_ACCESS_KEY_ID'                    => 'services.aws.key',
        'AWS_SECRET_ACCESS_KEY'                => 'services.aws.secret',
        'AWS_DEFAULT_REGION'                   => 'services.aws.region',
        'AWS_BUCKET'                           => 'filesystems.disks.s3',
        'FILESYSTEM_DRIVER'                    => 'filesystems.default',
        'QUEUE_CONNECTION'                     => 'queue.default',
        'SQS_PREFIX'                           => 'queue.connections.sqs',
        'MAIL_MAILER'                          => 'mail.default',
        'MAIL_FROM_ADDRESS'                    => 'mail.from',
        'MAIL_HOST'                            => 'mail.mailers.smtp.host',
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
        'MICROSOFT_GRAPH_CLIENT_ID'            => 'mail.mailers.microsoft-graph.client_id',
        'MICROSOFT_GRAPH_CLIENT_SECRET'        => 'mail.mailers.microsoft-graph.client_secret',
        'MICROSOFT_GRAPH_TENANT_ID'            => 'mail.mailers.microsoft-graph.tenant_id',
        'MAIL_SAVE_TO_SENT_ITEMS'              => 'mail.mailers.microsoft-graph.save_to_sent_items',
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
        ['settingsKey' => 'mail.mailers.smtp', 'configKey' => 'mail.mailers.smtp'],
        ['settingsKey' => 'mail.mailers.microsoft-graph', 'configKey' => 'mail.mailers.microsoft-graph'],
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
     * @return array An associative array where the keys are the environment variable names and
     *               the values are the corresponding configuration paths
     */
    protected static function getSettableEnvironmentVariables(): array
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
        $envDbKeys = array_map(
            fn ($path) => Str::startsWith($path, 'system.') ? $path : 'system.' . $path,
            array_values(static::$environmentVariables)
        );

        // Build DB keys for settings including parent keys for nested lookups
        $settingDbKeys = collect(static::$settings)
            ->flatMap(function ($map) {
                $key     = $map['settingsKey'];
                $fullKey = Str::startsWith($key, 'system.') ? $key : 'system.' . $key;

                $segments = explode('.', $key);
                $keys     = [$fullKey];

                // Add parent keys for nested lookups (e.g., 'system.services.aws' for 'system.services.aws.region')
                if (count($segments) >= 2) {
                    for ($i = 1; $i < count($segments); $i++) {
                        $keys[] = 'system.' . implode('.', array_slice($segments, 0, $i));
                    }
                }

                return $keys;
            })
            ->unique()
            ->values()
            ->toArray();

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

            // Determine the DB key we're looking for
            $dbKey = 'system.' . $settingsKey;
            $value = static::getDbSetting($dbSettings, $dbKey);

            // Skip if nothing was found
            if ($value === null) {
                continue;
            }

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
        $environmentVariables = static::getSettableEnvironmentVariables();

        foreach ($environmentVariables as $envVar => $configPath) {
            $dbKey = Str::startsWith($configPath, 'system.') ? $configPath : 'system.' . $configPath;
            if (empty(env($envVar))) {
                $value = static::getDbSetting($dbSettings, $dbKey);
                if (is_string($value) && !empty($value)) {
                    putenv(sprintf('%s="%s"', $envVar, addcslashes($value, '"')));
                }
            }
        }
    }

    /**
     * Get a value from the database settings array, with fallback to parent keys.
     *
     * This method implements a progressive fallback strategy for nested configuration keys.
     * If the full key doesn't exist directly, it tries progressively shorter parent keys
     * and traverses into their values using the remaining segments.
     *
     * Example:
     * If looking for 'system.services.aws.region' and it doesn't exist directly,
     * but 'system.services.aws' exists with value ['region' => 'ap-southeast-1', 'key' => '...'],
     * this method will return 'ap-southeast-1'.
     *
     * @param array  $dbSettings The flat array of database settings (key => value)
     * @param string $lookupKey  The dot-separated key to look up (e.g., 'system.services.aws.region')
     * @param mixed  $default    The default value to return if not found
     *
     * @return mixed The found value, or the default if not found
     */
    private static function getDbSetting(array $dbSettings, string $lookupKey, $default = null)
    {
        // First, check if the full key exists directly in the flat array
        if (isset($dbSettings[$lookupKey])) {
            return $dbSettings[$lookupKey];
        }

        // Split the lookup key into segments
        $segments = explode('.', $lookupKey);

        // Try progressively shorter keys, starting from the full key
        for ($i = count($segments) - 1; $i > 0; $i--) {
            // Build a parent key (e.g., 'system.services.aws' from 'system.services.aws.region')
            $parentKey = implode('.', array_slice($segments, 0, $i));

            // Check if this parent key exists in the database settings
            if (isset($dbSettings[$parentKey])) {
                $value = $dbSettings[$parentKey];

                // Get the remaining segments to traverse into the value
                $remainingSegments = array_slice($segments, $i);

                // Traverse into the value using the remaining segments
                foreach ($remainingSegments as $segment) {
                    if (is_array($value) && array_key_exists($segment, $value)) {
                        $value = $value[$segment];
                    } else {
                        // Path doesn't exist in the nested structure
                        return $default;
                    }
                }

                return $value;
            }
        }

        // Nothing found at any level
        return $default;
    }
}
