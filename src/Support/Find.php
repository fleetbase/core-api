<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Find
{
    /**
     * Dynamically determines the HTTP resource class for a given Eloquent model based on the model type,
     * optionally considering the model's namespace and API version.
     * It attempts to find a resource from a custom or default namespace and handles internal requests specifically by adjusting namespaces.
     *
     * @param Model       $model     the Eloquent model instance for which the resource class is to be found
     * @param string|null $namespace Optional. The namespace to search within, defaults to Fleetbase's HTTP resource namespace.
     * @param int         $version   Optional. API version number, defaults to 1, to support versioning in API resources.
     *
     * @return string the fully qualified class name of the resource, or a default resource class if none found
     */
    public static function httpResourceForModel(Model $model, ?string $namespace = null, ?int $version = 1): ?string
    {
        static $cache = [];

        // A model may name its resource explicitly; otherwise it is resolved by convention.
        $explicit = method_exists($model, 'getResource') ? $model->getResource() : null;
        $cacheKey = md5(get_class($model) . '|' . ($namespace ?? '') . '|' . $version . '|' . (is_string($explicit) ? $explicit : ''));

        if (isset($cache[$cacheKey])) {
            $cached = $cache[$cacheKey];

            // Convention-resolved models with a dedicated `Internal\` resource depend on the request audience,
            // so both candidates are cached and the request decides per call. Everything else never touches the request.
            if (is_array($cached)) {
                return Http::isInternalRequest() ? $cached['internal'] : $cached['public'];
            }

            return $cached;
        }

        $defaultResourceNS = $coreResourceNS = '\\Fleetbase\\Http\\Resources\\';
        $packageName       = static::getModelPackage($model);
        if ($packageName) {
            $defaultResourceNS = '\\Fleetbase\\' . $packageName . '\\Http\\Resources\\';
        }

        $fallback = $coreResourceNS . 'FleetbaseResource';

        if ($explicit !== null) {
            $resolved = is_string($explicit) && Utils::classExists($explicit) ? $explicit : $fallback;

            $cache[$cacheKey] = $resolved;

            return $resolved;
        }

        $baseNamespace = $namespace ? $namespace . '\\Http\\Resources\\' : $defaultResourceNS;
        $modelName     = (string) Utils::classBasename($model);

        $public   = static::httpResourceByConvention($baseNamespace, $modelName, $version, false, $fallback);
        $internal = static::httpResourceByConvention($baseNamespace, $modelName, $version, true, $fallback);

        if ($public === $internal) {
            $cache[$cacheKey] = $public;

            return $public;
        }

        $cache[$cacheKey] = ['internal' => $internal, 'public' => $public];

        return Http::isInternalRequest() ? $internal : $public;
    }

    /**
     * Resolve a resource class by naming convention: `{base}[Internal\]v{version}\{Model}`, falling back to the
     * public resource, then the unversioned resource, then the given default.
     */
    protected static function httpResourceByConvention(string $baseNamespace, string $modelName, ?int $version, bool $internal, string $fallback): string
    {
        $candidate = $baseNamespace . ($internal ? 'Internal\\' : '') . "v{$version}\\" . $modelName;

        // Fallback to public resource if internal version isn't found.
        if (!Utils::classExists($candidate)) {
            $candidate = str_replace('Internal\\', '', $candidate);
        }

        // Fallback to non-versioned namespace.
        if (!Utils::classExists($candidate)) {
            $candidate = str_replace("v{$version}\\", '', $candidate);
        }

        return Utils::classExists($candidate) ? $candidate : $fallback;
    }

    /**
     * Resolves the HTTP request class for a specific Eloquent model, taking into account the model's namespace and API version.
     * This method provides support for finding request classes tailored to specific actions (like Create, Update) on models.
     * It handles internal requests by adapting the namespace to include internal paths and supports versioning.
     *
     * @param Model       $model     the Eloquent model instance for which the request class is to be determined
     * @param string|null $namespace Optional. The base namespace for locating the request classes, defaults to Fleetbase's HTTP requests namespace.
     * @param int         $version   Optional. Specifies the API version to support structured versioning in requests.
     *
     * @return string the fully qualified class name of the request, or a default request class if none applicable
     */
    public static function httpRequestForModel(Model $model, ?string $namespace = null, ?int $version = 1): ?string
    {
        // Create a unique cache key based on parameters.
        $cacheKey = md5(get_class($model) . '|' . ($namespace ?? '') . '|' . $version . '|' . Http::action());

        static $cache = [];
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $requestNamespace = null;
        $defaultRequestNS = '\\Fleetbase\\Http\\Requests\\';
        $requestNS        = $baseNamespace = $namespace ? $namespace . '\\Http\\Requests\\' : $defaultRequestNS;
        $modelName        = Utils::classBasename($model);

        if (method_exists($model, 'getRequest')) {
            $requestNamespace = $model->getRequest();
        }

        if ($requestNamespace === null) {
            $requestNamespace = $requestNS . Str::studly(ucfirst(Http::action()) . ucfirst($modelName) . 'Request');
        }

        if (!Utils::classExists($requestNamespace)) {
            $internal = Http::isInternalRequest();
            if ($internal) {
                $baseNamespace .= 'Internal\\';
            }
            $requestNamespace = $baseNamespace . "v{$version}\\" . $modelName;

            if (!Utils::classExists($requestNamespace)) {
                $requestNamespace = str_replace('Internal\\', '', $requestNamespace);
            }
            if (!Utils::classExists($requestNamespace)) {
                $requestNamespace = str_replace("v{$version}\\", '', $requestNamespace);
            }
        }

        try {
            if (!Utils::classExists($requestNamespace)) {
                throw new \Exception('Missing resource');
            }
        } catch (\Error|\Exception $e) {
            $requestNamespace = $defaultRequestNS . 'FleetbaseRequest';
        }

        // Store the resolved value in the cache.
        $cache[$cacheKey] = $requestNamespace;

        return $requestNamespace;
    }

    /**
     * Retrieves the HTTP filter class associated with a specific Eloquent model. This function considers the model's namespace,
     * versioning, and whether the request is internal to decide the appropriate filter class.
     * The method uses a default or provided namespace and adapts it based on internal request checks and versioning needs.
     *
     * @param Model       $model     the Eloquent model instance whose filter class is being determined
     * @param string|null $namespace Optional. A custom base namespace for filter classes, otherwise defaulting to a calculated namespace based on the model's path.
     * @param int         $version   Optional. The version of the API for which the filter is being sought, affecting the namespace structure.
     *
     * @return string|null the fully qualified class name of the filter, or null if no appropriate class exists
     */
    public static function httpFilterForModel(Model $model, ?string $namespace = null, ?int $version = 1): ?string
    {
        // Create a unique cache key based on the model, namespace, version, and a filter flag.
        $cacheKey     = md5(get_class($model) . '|' . ($namespace ?? '') . '|' . $version . '|filter');
        static $cache = [];
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $namespaceSegments = explode('Models', get_class($model));
        $baseNS            = '\\' . rtrim($namespaceSegments[0], '\\');
        $filterNamespace   = null;
        $defaultFilterNS   = $baseNS . '\\Http\\Filter\\';
        $filterNs          = $namespace ? $namespace . '\\Http\\Filter\\' : $defaultFilterNS;
        $modelName         = Utils::classBasename($model);

        if (method_exists($model, 'getFilter')) {
            $filterNamespace = $model->getFilter();
        }

        if ($filterNamespace === null) {
            $filterNamespace = $filterNs . Str::studly(ucfirst($modelName) . 'Filter');
        }

        if (!Utils::classExists($filterNamespace)) {
            $internal      = Http::isInternalRequest();
            $baseNamespace = $filterNs;
            if ($internal) {
                $baseNamespace = $filterNs . 'Internal\\';
            }
            $filterNamespace = $baseNamespace . "v{$version}\\" . $modelName;

            if (!Utils::classExists($filterNamespace)) {
                $filterNamespace = str_replace('Internal\\', '', $filterNamespace);
            }
            if (!Utils::classExists($filterNamespace)) {
                $filterNamespace = str_replace("v{$version}\\", '', $filterNamespace);
            }
        }

        $cache[$cacheKey] = Utils::classExists($filterNamespace) ? $filterNamespace : null;

        return $cache[$cacheKey];
    }

    public static function getModelPackage(Model $model): ?string
    {
        $fullClassName         = get_class($model);
        $fullClassNameSegments = explode('\\', $fullClassName);
        if (!isset($fullClassNameSegments[1])) {
            return null;
        }

        if ($fullClassNameSegments[1] !== 'Models') {
            return $fullClassNameSegments[1];
        }

        return null;
    }
}
