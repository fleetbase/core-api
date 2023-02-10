<?php

namespace Fleetbase\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mysql:createdb {--schemaName=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new mysql database schema based on the database config file';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $_schemaName = $this->option('schemaName');
        $connections = ['mysql', 'sandbox'];

        foreach($connections as $connection) {
            $schemaName = config("database.connections.$connection.database");

            if ($_schemaName) {
                $schemaName = $connection === 'mysql' ? $_schemaName : $_schemaName . '_' . $connection;
            }

            $charset = config("database.connections.$connection.charset", 'utf8mb4');
            $collation = config("database.connections.$connection.collation", 'utf8mb4_unicode_ci');

            config(["database.connections.mysql.database" => null]);

            $query = "CREATE DATABASE IF NOT EXISTS $schemaName CHARACTER SET \"$charset\" COLLATE \"$collation\";";
            DB::statement($query);

            config(["database.connections.mysql.database" => $schemaName]);
        }
    }
}
