<?php

namespace Fleetbase\Events;

use Fleetbase\Models\Model;
use Fleetbase\Support\Resolve;
use Fleetbase\Support\Utils;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
// use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ResourceLifecycleEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $modelUuid;
    public $modelClassNamespace;
    public $modelClassName;
    public $modelHumanName;
    public $modelRecordName;
    public $modelName;
    public $namespace;
    public $version;
    public $userSession;
    public $companySession;
    public $eventId;
    public $apiVersion;
    public $requestMethod;
    public $apiCredential;
    public $apiSecret;
    public $apiKey;
    public $apiEnvironment;
    public $isSandbox;

    /**
     * The lifecycle event name.
     *
     * @var string
     */
    public $eventName;

    /**
     * The datetime instance the broadcast ws triggered.
     *
     * @var string
     */
    public $sentAt;

    /**
     * The data sent for this event.
     */
    public array $data = [];

    /**
     * Create a new lifecycle event instance.
     *
     * @param string $eventName
     * @param int    $version
     *
     * @return void
     */
    public function __construct(Model $model, $eventName = null, $version = 1)
    {
        $this->modelUuid           = $model->uuid;
        $this->modelClassNamespace = get_class($model);
        $this->modelClassName      = Utils::classBasename($model);
        $this->modelHumanName      = Str::humanize($this->modelClassName, false);
        $this->modelRecordName     = Utils::or($model, ['name', 'email', 'public_id']);
        $this->modelName           = Str::snake($this->modelClassName);
        $this->namespace           = $this->getNamespaceFromModel($model);
        $this->userSession         = session('user');
        $this->companySession      = session('company');
        $this->eventName           = $eventName ?? $this->eventName;
        $this->sentAt              = Carbon::now()->toDateTimeString();
        $this->version             = $version;
        $this->requestMethod       = request()->method();
        $this->eventId             = uniqid('event_');
        $this->apiVersion          = config('api.version');
        $this->apiCredential       = session('api_credential');
        $this->apiSecret           = session('api_secret');
        $this->apiKey              = session('api_key');
        $this->apiEnvironment      = session('api_environment', 'live');
        $this->isSandbox           = session('is_sandbox', false);
        $this->data                = $this->getEventData();
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return $this->modelName . '.' . $this->eventName;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        $model          = $this->getModelRecord();
        $companySession = session('company', $model->company_uuid);
        $channels       = [new Channel('company.' . $companySession)];

        if ($model && isset($model->company)) {
            $channels[] = new Channel('company.' . $model->company->public_id);
        }

        if (isset($model->public_id)) {
            $channels[] = new Channel($this->modelName . '.' . $model->public_id);
        }

        if (isset($model->uuid)) {
            $channels[] = new Channel($this->modelName . '.' . $model->uuid);
        }

        if (session()->has('api_credential')) {
            $channels[] = new Channel('api.' . session('api_credential'));
        }

        if ($model && isset($model->driver_assigned_uuid)) {
            $channels[] = new Channel('driver.' . $model->driver_assigned_uuid);
        }

        if ($model && isset($model->driverAssigned)) {
            $channels[] = new Channel('driver.' . $model->driverAssigned->public_id);
        }

        if ($model && isset($model->customer_uuid)) {
            $channels[] = new Channel('customer.' . $model->customer_uuid);
        }

        if ($model && isset($model->customer)) {
            $channels[] = new Channel('customer.' . $model->customer->public_id);
        }

        if ($model && isset($model->facilitator_uuid)) {
            $channels[] = new Channel('facilitator.' . $model->facilitator_uuid);
        }

        if ($model && isset($model->facilitator)) {
            $channels[] = new Channel('facilitator.' . $model->facilitator->public_id);
        }

        if ($model && isset($model->vendor_uuid)) {
            $channels[] = new Channel('vendor.' . $model->vendor_uuid);
        }

        if ($model && isset($model->vendor)) {
            $channels[] = new Channel('vendor.' . $model->vendor->public_id);
        }

        // In future versions allow extensions to define lifecycle hook channels using kv
        // For now define storefront channel manually here in core
        if ($model && data_get($model, 'meta.storefront_id')) {
            $channels[] = new Channel('storefront.' . data_get($model, 'meta.storefront_id'));
        }

        return $channels;
    }

    public function getNamespaceFromModel(Model $model): string
    {
        $namespaceSegments = explode('\Models\\', get_class($model));
        $modelNamespace    = '\\' . Arr::first($namespaceSegments);

        return $modelNamespace;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return $this->getEventData();
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function getEventData()
    {
        $model        = $this->getModelRecord();
        $resource     = $this->getModelResource($model, $this->namespace, $this->version);
        $resourceData = [];

        if ($resource) {
            if (method_exists($resource, 'toWebhookPayload')) {
                $resourceData = $resource->toWebhookPayload();
            } elseif (method_exists($resource, 'toArray')) {
                $resourceData = $resource->toArray(request());
            }
        }

        $resourceData = static::transformResourceChildrenToId($resourceData);

        $data = [
            'id'          => $this->eventId,
            'api_version' => $this->apiVersion,
            'event'       => $this->broadcastAs(),
            'created_at'  => $this->sentAt,
            'data'        => $resourceData,
        ];

        return $data;
    }

    /**
     * Return the models json resource instance.
     *
     * @return \Fleetbase\Model\Model
     */
    public function getModelRecord()
    {
        $namespace = $this->modelClassNamespace;

        return (new $namespace())->where('uuid', $this->modelUuid)->withoutGlobalScopes()->first();
    }

    /**
     * Return the models json resource instance.
     *
     * @param \Fleetbase\Model\Model $model
     *
     * @return \Illuminate\Http\Resources\Json\JsonResource
     */
    public function getModelResource($model, string $namespace = null, int $version = null)
    {
        return Resolve::httpResourceForModel($model, $namespace, $version);
    }

    /**
     * Return the models json resource instance.
     *
     * @return array
     */
    public static function transformResourceChildrenToId(array $data = [])
    {
        foreach ($data as $key => $value) {
            if ($value instanceof JsonResource) {
                if (!$value->resource) {
                    $data[$key] = null;
                    continue;
                }

                $id         = Utils::or($value->resource, ['public_id', 'internal_id', 'uuid']);
                $data[$key] = $id;
            }

            if ($value instanceof Carbon) {
                $data[$key] = $value->toDateTimeString();
            }
        }

        return $data;
    }
}
