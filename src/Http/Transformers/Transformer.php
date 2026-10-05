<?php

namespace Fleetbase\Http\Transformers;

use Fleetbase\Contracts\ResourceTransformer;

/**
 * Convenience base class for resource transformers.
 *
 * Declare the target(s) and tuning options as static properties and implement `transform()`:
 *
 *     class UserBadgeTransformer extends Transformer
 *     {
 *         protected static $target   = \Fleetbase\Models\User::class;
 *         protected static $priority = 10;
 *         protected static $contexts = ['http'];
 *         protected static $only     = 'internal';
 *
 *         public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
 *         {
 *             return array_merge($data, ['badge' => '…']);
 *         }
 *     }
 */
abstract class Transformer implements ResourceTransformer
{
    /**
     * Resource class, model class, interface, or '*' this transformer applies to.
     *
     * @var string|array<int, string>|null
     */
    protected static $target;

    /**
     * Order relative to other transformers for the same resource. Lower runs first;
     * higher runs later and can override earlier output.
     *
     * @var int
     */
    protected static $priority = 0;

    /**
     * Channels this transformer applies to (`http`, `webhook`, `broadcast`). Null for all.
     *
     * @var array<int, string>|null
     */
    protected static $contexts;

    /**
     * Restrict to `internal` (console) or `public` (API) requests. Null for both.
     *
     * @var string|null
     */
    protected static $only;

    public static function target(): string|array
    {
        $target = static::$target;

        if ($target === null || $target === '' || $target === []) {
            throw new \LogicException(static::class . ' must define a static $target property or override target().');
        }

        return $target;
    }

    /**
     * Registration options, overridable by options passed to `ResourceTransformerRegistry::register()`.
     *
     * @return array{priority: int, contexts: array<int, string>|null, only: string|null}
     */
    public static function options(): array
    {
        return [
            'priority' => (int) static::$priority,
            'contexts' => static::$contexts,
            'only'     => static::$only,
        ];
    }
}
