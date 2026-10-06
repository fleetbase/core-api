<?php

namespace Fleetbase\Support\SocketCluster;

use Fleetbase\Contracts\SocketChannelResolver;

/**
 * Authorizes channels named after a model's uuid or public_id.
 *
 * Created by SocketChannelRegistry::registerModel(); see there for the rules.
 */
class ModelChannelResolver implements SocketChannelResolver
{
    /**
     * @var callable|null
     */
    protected $narrow;

    public function __construct(protected string $modelClass, ?callable $narrow = null)
    {
        $this->narrow = $narrow;
    }

    public function authorize(SocketPrincipal $principal, string $id, string $channel): bool
    {
        $narrowed = $principal->kind === 'driver' || $principal->kind === 'customer';

        if (!$principal->isCompanyScoped() && !$narrowed) {
            return false;
        }

        $model = static::find($this->modelClass, $id, $principal);

        if ($model === null) {
            return false;
        }

        if ($principal->isCompanyScoped()) {
            return $principal->cid !== null && $model->company_uuid === $principal->cid;
        }

        return $this->narrow !== null && (bool) call_user_func($this->narrow, $principal, $model);
    }

    /**
     * Find a model by uuid or public_id in the principal's environment (sandbox for test).
     */
    public static function find(string $modelClass, string $id, SocketPrincipal $principal): ?object
    {
        return $modelClass::on(static::connection($principal))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->first();
    }

    /**
     * The database connection for the principal's environment; null means the default one.
     */
    public static function connection(SocketPrincipal $principal): ?string
    {
        return $principal->env === 'test' ? 'sandbox' : null;
    }
}
