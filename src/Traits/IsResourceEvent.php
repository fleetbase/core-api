<?php

namespace Fleetbase\Traits;

use Fleetbase\Models\BaseModel;
use Fleetbase\Support\Utils;
use Illuminate\Broadcasting\Channel;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

trait IsResourceEvent
{
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

    /**
     * The lifecycle event name
     *
     * @var string
     */
    public ?string $eventName = '';

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
    public $data = [];

    /**
     * The name of the queue connection to use when broadcasting the event.
     *
     * @var string
     */
    public $connection = 'events';

    // /**
    //  * Create a new lifecycle event instance.
    //  *
    //  * @param \Fleetbase\Models\BaseModel $model
    //  * @param string $eventName
    //  * @param integer $version
    //  *
    //  * @return void
    //  */
    // public function __construct(BaseModel $model, $eventName = null, $version = 1)
    // {
    //     $this->modelUuid = $model->uuid;
    //     $this->modelClassNamespace = get_class($model);
    //     $this->modelClassName = class_basename($model);
    //     $this->modelHumanName = Str::lower(Utils::humanizeClassName($model));
    //     $this->modelRecordName = Utils::or($model, ['name', 'email', 'public_id']);
    //     $this->modelName = Str::snake($this->modelClassName);
    //     $this->userSession = session('user');
    //     $this->companySession = session('company');
    //     $this->eventName = $eventName ?? $this->eventName;
    //     $this->sentAt = Carbon::now()->toDateTimeString();
    //     $this->version = $version;
    //     $this->requestMethod = request()->method();
    //     $this->eventId = uniqid('event_');
    //     $this->apiVersion = config('api.version');
    //     // $this->data = $this->getEventData();
    // }

    /**
     * Setup the event properties
     *
     * @param \Fleetbase\Models\BaseModel $model
     * @param string $eventName
     * @param integer $version
     *
     * @return void
     */
    public function setupEventProperties(BaseModel $model, $eventName = null, $version = 1)
    {
        $this->modelUuid = $model->uuid;
        $this->modelClassNamespace = get_class($model);
        $this->modelClassName = class_basename($model);
        $this->modelHumanName = Str::lower(Utils::humanizeClassName($model));
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
        // $this->data = $this->getEventData();
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
        $channels = [new Channel('company.' . session('company')), new Channel('api.' . session('api_credential'))];

        if ($model && isset($model->driver_assigned_uuid)) {
            $channels[] = new Channel('driver.' . $model->driver_assigned_uuid);
        }

        if ($model && isset($model->customer_uuid)) {
            $channels[] = new Channel('customer.' . $model->customer_uuid);
        }

        if ($model && isset($model->facilitator_uuid)) {
            $channels[] = new Channel('facilitator.' . $model->facilitator_uuid);
        }

        if ($model && isset($model->vendor_uuid)) {
            $channels[] = new Channel('vendor.' . $model->vendor_uuid);
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
        $resource = $this->getModelResource($model);
        $resourceData = [];

        if ($resource) {
            if (method_exists($resource, 'toWebhookPayload')) {
                $resourceData = $resource->toWebhookPayload();
            } else if (method_exists($resource, 'toArray')) {
                $resourceData = $resource->toArray(request());
            }
        }

        $resourceData = $this->transformResourceChildrenToId($resourceData);

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
     * @return  \Fleetbase\Model\BaseModel
     */
    public function getModelRecord()
    {
        $namespace = $this->modelClassNamespace;

        return (new $namespace)->where('uuid', $this->modelUuid)->withoutGlobalScopes()->first();
    }

    /**
     * Return the models json resource instance
     *
     * @param  \Fleetbase\Model\BaseModel
     * @param  string version
     * @return  \Illuminate\Http\Resources\Json\JsonResource
     */
    public function getModelResource($model = null, $version = null)
    {
        $version = $version ?? $this->version;
        $model = $model === null ? $this->getModelRecord() : $model;
        $resourceNamespace = "Fleetbase\\Http\\Resources\\v{$version}\\" . $this->modelClassName;

        if (!$model) {
            return false;
        }

        return new $resourceNamespace($model);
    }

    /**
     * Return the models json resource instance
     *
     * @param  \Fleetbase\Model\BaseModel
     * @param  string version
     * @return  \Illuminate\Http\Resources\Json\JsonResource
     */
    public function transformResourceChildrenToId(array $data = [])
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