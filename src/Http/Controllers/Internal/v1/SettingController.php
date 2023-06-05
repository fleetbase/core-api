<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Illuminate\Http\Request;

class SettingController extends FleetbaseController
{
	/**
	 * The resource to query
	 *
	 * @var string
	 */
	public $resource = 'setting';

	/**
	 * Test a MySQL database connection.
	 *
	 * This function will attempt to establish a connection to a MySQL database using either a set of 
	 * database connection parameters (host, port, database, username, and password), or a database URL. 
	 * It will then return a JSON response indicating whether the connection was successful or not.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function testMysqlConnection(Request $request)
	{
		$host = $request->input('DB_HOST');
		$port = $request->input('DB_PORT');
		$database = $request->input('DB_DATABASE', 'fleetbase');
		$username = $request->input('DB_USERNAME', 'fleetbase');
		$password = $request->input('DB_PASSWORD', '');
		$databaseUrl = $request->input('DATABASE_URL');

		if ($databaseUrl) {
			$url = parse_url($databaseUrl);
			$host = $url['host'];
			$port = $url['port'] ?? null; // Default to null if not set
			$username = $url['user'];
			if (isset($url['pass'])) {
				$password = $url['pass'];
			}
			$database = isset($url['path']) ? substr($url['path'], 1) : $database;
		}

		try {
			// Make sure the configuration works
			new \PDO(
				"mysql:host=$host;port=$port;dbname=$database",
				$username,
				$password,
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);

			// If we got here, it means the connection is successful. Return a positive result
			return response()->json([
				'status' => 'success',
				'message' => 'Connection successful.'
			]);
		} catch (\Exception $e) {
			// If we got an error, it means that the connection details were incorrect.
			return response()->json([
				'status' => 'error',
				'message' => 'Could not connect to the database. Please check your configuration. Error: ' . $e->getMessage()
			]);
		}
	}
}
