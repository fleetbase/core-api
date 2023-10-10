<?php

namespace Fleetbase\Routing;

use Illuminate\Routing\ResourceRegistrar;

class RESTRegistrar extends ResourceRegistrar
{
    /**
     * The default actions for a restful controller.
     *
     * @var string[]
     */
    protected $resourceDefaults = ['query', 'find', 'create', 'update', 'delete', 'options'];

    /**
     * Build a set of prefixed resource routes.
     *
     * @param string $name
     * @param string $controller
     *
     * @return void
     */
    protected function prefixedResource($name, $controller = null, array $options)
    {
        [$name, $prefix] = $this->getResourcePrefix($name);

        // We need to extract the base resource from the resource name. Nested resources
        // are supported in the framework, but we need to know what name to use for a
        // place-holder on the route parameters, which should be the base resources.
        $callback = function ($me) use ($name, $controller, $options) {
            $me->rest($name, $controller, $options);
        };

        return $this->router->group(compact('prefix'), $callback);
    }

    /**
     * Add the query method for a resourceful route.
     *
     * @param string $name
     * @param string $id
     * @param string $controller
     * @param array  $options
     *
     * @return \Illuminate\Routing\Route
     */
    protected function addResourceQuery($name, $id, $controller, $options)
    {
        $uri = $this->getResourceUri($name);

        $action = $this->getResourceAction($name, $controller, 'queryRecord', $options);

        return $this->router->get($uri, $action);
    }

    /**
     * Add the find method for a resourceful route.
     *
     * Example: /resource/{id}
     *
     * @param string $name
     * @param string $id
     * @param string $controller
     * @param array  $options
     *
     * @return \Illuminate\Routing\Route
     */
    protected function addResourceFind($name, $id, $controller, $options)
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name) . '/{' . $id . '}';

        $action = $this->getResourceAction($name, $controller, 'findRecord', $options);

        return $this->router->get($uri, $action);
    }

    /**
     * Add the create method for a resourceful route.
     *
     * POST /resource
     *
     * @param string $name
     * @param string $id
     * @param string $controller
     * @param array  $options
     *
     * @return \Illuminate\Routing\Route
     */
    protected function addResourceCreate($name, $id, $controller, $options)
    {
        $uri = $this->getResourceUri($name);

        $action = $this->getResourceAction($name, $controller, 'createRecord', $options);

        return $this->router->post($uri, $action);
    }

    /**
     * Add the update method for a resourceful route.
     *
     * PUT|PATCH /resource/{id}
     *
     * @param string $name
     * @param string $id
     * @param string $controller
     * @param array  $options
     *
     * @return \Illuminate\Routing\Route
     */
    protected function addResourceUpdate($name, $id, $controller, $options)
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name) . '/{' . $id . '}';

        $action = $this->getResourceAction($name, $controller, 'updateRecord', $options);

        return $this->router->match(['PUT', 'PATCH'], $uri, $action);
    }

    /**
     * Add the delete method for a resourceful route.
     *
     * DELETE /resource/{id}
     *
     * @param string $name
     * @param string $id
     * @param string $controller
     * @param array  $options
     *
     * @return \Illuminate\Routing\Route
     */
    protected function addResourceDelete($name, $id, $controller, $options)
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name) . '/{' . $id . '}';

        $action = $this->getResourceAction($name, $controller, 'deleteRecord', $options);

        return $this->router->delete($uri, $action);
    }

    /**
     * Add the query method for a resourceful route.
     *
     * OPTIONS /resource
     *
     * @param string $name
     * @param string $id
     * @param string $controller
     * @param array  $options
     *
     * @return \Illuminate\Routing\Route
     */
    protected function addResourceOptions($name, $id, $controller, $options)
    {
        $uri         = $this->getResourceUri($name);
        $resourceUri = $this->getResourceUri($name) . '/{' . $id . '}';

        $action = $this->getResourceAction($name, $controller, 'options', $options);

        $this->router->options($resourceUri, $action);

        return $this->router->options($uri, $action);
    }
}
