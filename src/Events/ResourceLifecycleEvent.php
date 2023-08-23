<?php

namespace Fleetbase\Events;

use Fleetbase\Models\Model;
use Fleetbase\Support\Resolve;
use Fleetbase\Support\Utils;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceLifecycleEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $modelUuid;
    public $modelClassNamespace;
    public $modelClassName;
    public $modelHumanName;
    public $modelRecordName;
    public $modelName;
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
     * The lifecycle event name
     *
     * @var string
     */
    public $eventName;

    /**
     * The datetime instance the broadcast ws triggered
     *
     * @var string
     */
    public $sentAt;

    /**
     * The data sent for this event
     *
     * @var array
     */
    public array $data = [];

    /**
     * Create a new lifecycle event instance.
     *
     * @param \Fleetbase\Models\Model $model
     * @param string $eventName
     * @param integer $version
     *
     * @return void
     */
    public function __construct(Model $model, $eventName = null, $version = 1)
    {
        $this->modelUuid = $model->uuid;
        $this->modelClassNamespace = get_class($model);
        $this->modelClassName = Utils::classBasename($model);
        $this->modelHumanName = Str::humanize($this->modelClassName, false);
        $this->modelRecordName = Utils::or($model, ['name', 'email', 'public_id']);
        $this->modelName = Str::snake($this->modelClassName);
        $this->userSession = session('user');
        $this->companySession = session('company');
        $this->eventName = $eventName ?? $this->eventName;
        $this->sentAt = Carbon::now()->toDateTimeString();
        $this->version = $version;
        $this->requestMethod = request()->method();
        $this->eventId = uniqid('event_');
        $this->apiVersion = config('api.version');
        $this->apiCredential = session('api_credential');
        $this->apiSecret = session('api_secret');
        $this->apiKey = session('api_key');
        $this->apiEnvironment = session('api_environment', 'live');
        $this->isSandbox = session('is_sandbox', false);
        $this->data = $this->getEventData();
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
        $model = $this->getModelRecord();
        $companySession = session('company', $model->company_uuid);
        $channels = [new Channel('company.' . $companySession)];

        if ($model && isset($model->company)) {
            $channels[] = new Channel('company.' . $model->company->public_id);
        }

        if (isset($model->public_id)) {
            $channels[] = new Channel($this->modelName . '.' . $model->public_id);
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

        if ($model && data_get($model, 'meta.storefront_id')) {
            $channels[] = new Channel('storefront.' . data_get($model, 'meta.storefront_id'));
        }

        return $channels;
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
        $model = $this->getModelRecord();
        $resource = $this->getModelResource($model, $this->version);
        $resourceData = [];

        if ($resource) {
            if (method_exists($resource, 'toWebhookPayload')) {
                $resourceData = $resource->toWebhookPayload();
            } else if (method_exists($resource, 'toArray')) {
                $resourceData = $resource->toArray(request());
            }
        }

        $resourceData = static::transformResourceChildrenToId($resourceData);

        $data = [
            'id' => $this->eventId,
            'api_version' => $this->apiVersion,
            'event' => $this->broadcastAs(),
            'created_at' => $this->sentAt,
            'data' => $resourceData,
        ];

        return $data;
    }

    /**
     * Return the models json resource instance
     *
     * @return  \Fleetbase\Model\Model
     */
    public function getModelRecord()
    {
        $namespace = $this->modelClassNamespace;

        return (new $namespace)->where('uuid', $this->modelUuid)->withoutGlobalScopes()->first();
    }

    /**
     * Return the models json resource instance
     *
     * @param  \Fleetbase\Model\Model $model
     * @param  int|null $version
     * @return  \Illuminate\Http\Resources\Json\JsonResource
     */
    public function getModelResource($model, ?int $version = null)
    {
        return Resolve::httpResourceForModel($model, $version);
    }

    /**
     * Return the models json resource instance
     *
     * @param array $data
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

                $id = Utils::or($value->resource, ['public_id', 'internal_id', 'uuid']);
                $data[$key] = $id;
            }

            if ($value instanceof Carbon) {
                $data[$key] = $value->toDateTimeString();
            }
        }

        return $data;
    }
}
