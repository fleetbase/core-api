<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Find
{
    public static function httpResourceForModel(Model $model, string $namespace = null, ?int $version = 1)
    {
        $resourceNamespace = null;
        $defaultResourceNS = '\\Fleetbase\\Http\\Resources\\';
        $baseNamespace     = $namespace ? $namespace . '\\Http\\Resources\\' : $defaultResourceNS;
        $modelName         = Utils::classBasename($model);

        if (method_exists($model, 'getResource')) {
            $resourceNamespace = $model->getResource();
        }

        if ($resourceNamespace === null) {
            $internal = Http::isInternalRequest();

            if ($internal) {
                $baseNamespace .= 'Internal\\';
            }

            $resourceNamespace = $baseNamespace . "v{$version}\\" . $modelName;

            // if internal request but no internal resource has been declared
            // fallback to the public resource
            if (!class_exists($resourceNamespace)) {
                $resourceNamespace = str_replace('Internal\\', '', $resourceNamespace);
            }

            // if no versioned base resource fallback to base namespace for resource
            if (!class_exists($resourceNamespace)) {
                $resourceNamespace = str_replace("v{$version}\\", '', $resourceNamespace);
            }
        }

        try {
            if (!class_exists($resourceNamespace)) {
                throw new \Exception('Missing resource');
            }
        } catch (\Error|\Exception $e) {
            $resourceNamespace = $defaultResourceNS . 'FleetbaseResource';
        }

        return $resourceNamespace;
    }

    public static function httpRequestForModel(Model $model, string $namespace = null, ?int $version = 1)
    {
        $requestNamespace = null;
        $defaultRequestNS = '\\Fleetbase\\Http\\Requests\\';
        $requestNS        = $baseNamespace = $namespace ? $namespace . '\\Http\\Requests\\' : $defaultRequestNS;
        $modelName        = Utils::classBasename($model);

        if (method_exists($model, 'getRequest')) {
            $requestNamespace = $model->getRequest();
        }

        if ($requestNamespace === null) {
            $requestNamespace = $requestNS . '\\' . Str::studly(ucfirst(Http::action()) . ucfirst($modelName) . 'Request');
        }

        if (!class_exists($requestNamespace)) {
            $internal = Http::isInternalRequest();

            if ($internal) {
                $baseNamespace .= 'Internal\\';
            }

            $requestNamespace = $baseNamespace . "v{$version}\\" . $modelName;

            // if internal request but no internal resource has been declared
            // fallback to the public resource
            if (!class_exists($requestNamespace)) {
                $requestNamespace = str_replace('Internal\\', '', $requestNamespace);
            }

            // if no versioned base resource fallback to base namespace for resource
            if (!class_exists($requestNamespace)) {
                $requestNamespace = str_replace("v{$version}\\", '', $requestNamespace);
            }
        }

        try {
            if (!class_exists($requestNamespace)) {
                throw new \Exception('Missing resource');
            }
        } catch (\Error|\Exception $e) {
            $requestNamespace = $defaultRequestNS . 'FleetbaseRequest';
        }

        return $requestNamespace;
    }

    public static function httpFilterForModel(Model $model, string $namespace = null, ?int $version = 1)
    {
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

        if (class_exists($filterNamespace)) {
            return $filterNamespace;
        } else {
            $internal = Http::isInternalRequest();

            if ($internal) {
                $baseNamespace = $filterNs . 'Internal\\';
            }

            $filterNamespace = $baseNamespace . "v{$version}\\" . $modelName;

            // if internal request but no internal resource has been declared
            // fallback to the public resource
            if (!class_exists($filterNamespace)) {
                $filterNamespace = str_replace('Internal\\', '', $filterNamespace);
            }

            // if no versioned base resource fallback to base namespace for resource
            if (!class_exists($filterNamespace)) {
                $filterNamespace = str_replace("v{$version}\\", '', $filterNamespace);
            }
        }

        if (class_exists($filterNamespace)) {
            return $filterNamespace;
        }

        return null;
    }
}
