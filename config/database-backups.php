<?php

/*
 * Environment defaults for database backups.
 *
 * A system administrator overrides any of these from the console (Admin → Database Backups);
 * the override is stored as the `system.database-backups` setting. See
 * Fleetbase\Support\DatabaseBackupSettings.
 */
return [
    /*
     * Whether scheduled backups run at all. Off by default so a fresh install does not
     * start dumping its database somewhere before anyone has chosen where.
     */
    'enabled' => env('DB_BACKUP_ENABLED', false),

    /*
     * hourly, every_six_hours, every_twelve_hours, daily or weekly. Times are UTC.
     */
    'frequency'   => env('DB_BACKUP_FREQUENCY', 'daily'),
    'time'        => env('DB_BACKUP_TIME', '00:00'),
    'day_of_week' => (int) env('DB_BACKUP_DAY_OF_WEEK', 0),

    /*
     * Where dumps go: a disk from config/filesystems.php, an optional bucket override for
     * s3 disks, and a key prefix.
     */
    'disk'   => env('DB_BACKUP_DISK', 's3'),
    'bucket' => env('DB_BACKUP_BUCKET'),
    'path'   => env('DB_BACKUP_PATH', ''),

    /*
     * The database connections to dump, by name.
     */
    'connections' => array_values(array_filter(array_map('trim', explode(',', (string) env('DB_BACKUP_CONNECTIONS', 'mysql,sandbox'))))),

    /*
     * Retention, applied after each fully successful run. Null disables that limit.
     */
    'retention_days'  => env('DB_BACKUP_RETENTION_DAYS', 30),
    'retention_count' => env('DB_BACKUP_RETENTION_COUNT'),

    /*
     * A compressed dump smaller than this many bytes fails the run. A gzip of nothing is
     * 20 bytes; even an empty schema dump compresses to several hundred.
     */
    'min_size_bytes' => (int) env('DB_BACKUP_MIN_SIZE_BYTES', 1024),

    /*
     * Who hears about a failed run.
     */
    'notify_on_failure' => env('DB_BACKUP_NOTIFY_ON_FAILURE', false),
    'notify_emails'     => array_values(array_filter(array_map('trim', explode(',', (string) env('DB_BACKUP_NOTIFY_EMAILS', ''))))),

    /*
     * The dump client and the arguments it always gets. --single-transaction gives a
     * consistent InnoDB snapshot without locking; --no-tablespaces avoids needing the
     * PROCESS privilege, which managed databases such as RDS do not grant.
     */
    'dump_binary' => env('DB_BACKUP_DUMP_BINARY', 'mysqldump'),
    'dump_args'   => [
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--hex-blob',
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
    ],
    'extra_dump_args' => array_values(array_filter(explode(' ', (string) env('DB_BACKUP_EXTRA_DUMP_ARGS', '')))),

    /*
     * Seconds a single database's dump may take.
     */
    'timeout' => (int) env('DB_BACKUP_TIMEOUT', 7200),

    /*
     * Where dumps are written before upload.
     */
    'tmp_dir' => env('DB_BACKUP_TMP_DIR', sys_get_temp_dir()),
];
