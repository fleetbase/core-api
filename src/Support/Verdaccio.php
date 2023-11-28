<?php

namespace Fleetbase\Support;

use Illuminate\Support\Facades\Http;

/**
 * Verdaccio class provides a set of static methods to interact with Verdaccio server.
 */
class Verdaccio
{
    /**
     * Retrieves the server URL from the configuration.
     *
     * @return string|null The Verdaccio server URL or null if not configured.
     */
    protected static function getServerUrl(): ?string
    {
        return config('fleetbase.extensions.repository');
    }

    /**
     * Makes an HTTP request to the Verdaccio server.
     *
     * @param string $method The HTTP method to use for the request.
     * @param string $endpoint The API endpoint to call.
     * @param array $data The data to send with the request.
     * @param array $headers The headers to send with the request.
     * @param array $adapterOptions Options for handling responses and errors.
     * @return array|Response The response from the Verdaccio server.
     * @throws \Exception If the server URL is not configured or if the request fails.
     */
    protected static function request(string $method, string $endpoint, array $data = [], array $headers = [], array $adapterOptions = [])
    {
        $baseUrl = static::getServerUrl();

        if (!$baseUrl) {
            throw new \Exception('Fleetbase extension repository host is not configured.');
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $response = Http::withHeaders($headers)->$method($url, $data);

        if ($response->successful() || $response->clientError()) {
            $json = $response->json();

            if ($response->clientError() && isset($adapterOptions['onError']) && is_callable($adapterOptions['onError'])) {
                $adapterOptions['onError']($response);
            }

            if (isset($adapterOptions['onResponse']) && is_callable($adapterOptions['onResponse'])) {
                $adapterOptions['onResponse']($json);
            }

            return $json;
        }

        if (isset($adapterOptions['onServerError']) && is_callable($adapterOptions['onServerError'])) {
            $adapterOptions['onServerError']($response);
        }

        throw $response->toException();
    }

    /**
     * Authenticates a user with the Verdaccio server.
     *
     * @param string|null $username The username for authentication.
     * @param string|null $password The password for authentication.
     * @return array|Response The response from the Verdaccio server.
     * @throws \Exception If username or password is not provided.
     */
    public static function authenticate(?string $username = null, ?string $password = null)
    {
        if (!$username || !$password) {
            throw new \Exception('A username and password is required to authenticate.');
        }

        return static::request('put', '/-/user/org.couchdb.user:' . $username, [
            'name' => $username,
            'password' => $password
        ]);
    }

    /**
     * Adds a new user to the Verdaccio server.
     *
     * @param string $username The username for the new user.
     * @param string $password The password for the new user.
     * @param string $email The email address for the new user.
     * @return array|Response The response from the Verdaccio server.
     * @throws \Exception If any required parameter is missing.
     */
    public static function addUser(string $username, string $password, string $email)
    {
        if (!$username || !$password || !$email) {
            throw new \Exception('A username, password, and email is required to create a user.');
        }

        return static::request('post', '/-/user/org.couchdb.user:' . $username, [
            'name' => $username,
            'password' => $password,
            'email' => $email,
        ]);
    }

    /**
     * Retrieves information about a specific user from the Verdaccio server.
     *
     * @param string $username The username of the user to retrieve information for.
     * @return array|Response The response from the Verdaccio server.
     */
    public static function getUserInfo(string $username)
    {
        return static::request('get', '/-/user/org.couchdb.user:' . $username);
    }

    /**
     * Lists all packages available in the Verdaccio registry, optionally filtered by scope.
     *
     * @param string|null $scope The scope to filter packages by, if any.
     * @return array|Response The response from the Verdaccio server.
     */
    public static function listPackages(?string $scope = null)
    {
        $endpoint = $scope ? '/-/verdaccio/packages/' . $scope : '/-/verdaccio/packages';
        return static::request('get', $endpoint);
    }

    /**
     * Searches for packages in the Verdaccio registry based on a query string.
     *
     * @param string|null $query The query string to search for.
     * @return array|Response The response from the Verdaccio server.
     */
    public static function searchPackages(?string $query = '')
    {
        return static::request('get', '/-/v1/search', ['text' => $query]);
    }

    /**
     * Retrieves detailed information about a specific package from the Verdaccio server.
     *
     * @param string $packageName The name of the package to retrieve details for.
     * @return array|Response The response from the Verdaccio server.
     */
    public static function getPackageDetails(string $packageName)
    {
        return static::request('get', $packageName);
    }

    /**
     * Updates a specific package in the Verdaccio registry.
     *
     * @param string $packageName The name of the package to update.
     * @param array $data The data to update the package with.
     * @return array|Response The response from the Verdaccio server.
     */
    public static function updatePackage(string $packageName, array $data = [])
    {
        return static::request('post', $packageName, $data);
    }

    /**
     * Retrieves the access control list (ACL) for a specific package from the Verdaccio server.
     *
     * @param string $packageName The name of the package to retrieve ACL for.
     * @return array|Response The response from the Verdaccio server.
     */
    public static function getPackageACL(string $packageName)
    {
        return static::request('get', '/-/package/' . $packageName . '/access');
    }

    /**
     * Sets the access control list (ACL) for a specific package in the Verdaccio registry.
     *
     * @param string $packageName The name of the package to set ACL for.
     * @param array $aclSettings The ACL settings to apply to the package.
     * @return array|Response The response from the Verdaccio server.
     */
    public static function setPackageACL(string $packageName, array $aclSettings = [])
    {
        return static::request('put', '/-/package/' . $packageName . '/access', $aclSettings);
    }

    /**
     * Publishes a new package to the Verdaccio registry.
     *
     * @param string $packageName The name of the package to publish.
     * @param array $data The data of the package to publish.
     * @return array|Response The response from the Verdaccio server.
     */
    public static function publishPackage(string $packageName, array $data = [])
    {
        return static::request('put', $packageName, $data);
    }

    /**
     * Unpublishes a package from the Verdaccio registry.
     *
     * @param string $packageName The name of the package to unpublish.
     * @return array|Response The response from the Verdaccio server.
     */
    public static function unpublishPackage(string $packageName)
    {
        return static::request('delete', $packageName);
    }
}
