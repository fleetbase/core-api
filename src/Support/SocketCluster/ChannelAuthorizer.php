<?php

namespace Fleetbase\Support\SocketCluster;

use Fleetbase\Contracts\SocketChannelResolver;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a socket principal may subscribe to a channel.
 *
 * Order: anonymous connections may only follow `fleetbase.install` before the first user
 * exists; expired principals are denied; a scoped principal (scp) gets exactly its listed
 * channels; the system principal gets everything; the principal's own channels are allowed
 * locally; anything else goes to the resolver registered for the channel's prefix.
 */
class ChannelAuthorizer
{
    public const INSTALL_CHANNEL = 'fleetbase.install';

    public const MAX_CHANNEL_LENGTH = 255;

    public const CACHE_PREFIX = 'socket-auth:';

    public function __construct(protected SocketChannelRegistry $registry)
    {
    }

    public function authorize(?SocketPrincipal $principal, string $channel): ChannelDecision
    {
        if (!static::isValidChannel($channel)) {
            return ChannelDecision::denied('invalid_channel');
        }

        if ($principal === null) {
            return $channel === self::INSTALL_CHANNEL && !$this->instanceHasUsers()
                ? ChannelDecision::allowed('install_pending', ChannelDecision::DENY_TTL)
                : ChannelDecision::denied('no_token');
        }

        $remaining = $principal->secondsRemaining();

        if ($remaining !== null && $remaining <= 0) {
            return ChannelDecision::denied('expired');
        }

        $cacheKey = $principal->jti === null ? null : self::CACHE_PREFIX . sha1($principal->jti . '|' . $channel);
        $cached   = $cacheKey === null ? null : Cache::get($cacheKey);

        if (is_array($cached)) {
            return ChannelDecision::fromArray($cached);
        }

        $decision = $this->decide($principal, $channel);

        if ($decision->allow && $remaining !== null) {
            $decision = $decision->capTtl($remaining);
        }

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $decision->toArray(), $decision->ttl);
        }

        return $decision;
    }

    /**
     * A channel name the socket server accepts: non-empty, at most 255 characters, no whitespace.
     */
    public static function isValidChannel(string $channel): bool
    {
        return $channel !== '' && strlen($channel) <= self::MAX_CHANNEL_LENGTH && !preg_match('/\s/', $channel);
    }

    /**
     * The channels a principal may always follow without a lookup.
     */
    public static function isSelfChannel(SocketPrincipal $principal, string $channel): bool
    {
        $own = [];

        if ($principal->isCompanyScoped()) {
            $own[] = 'company.' . $principal->cid;
            $own[] = 'company.' . $principal->cpid;
        }

        if ($principal->kind === 'api') {
            $own[] = 'api.' . $principal->sub;
        }

        foreach ($principal->ids as $id) {
            $own[] = 'user.' . $id;
            $own[] = 'driver.' . $id;
        }

        // Empty ids would yield names like "company." which no real channel has.
        if (in_array($channel, array_filter($own, fn ($name) => !str_ends_with($name, '.')), true)) {
            return true;
        }

        return $principal->kind === 'user'
            && $principal->cid !== null
            && (str_starts_with($channel, 'install.' . $principal->cid . '.') || str_starts_with($channel, 'uninstall.' . $principal->cid . '.'));
    }

    protected function decide(SocketPrincipal $principal, string $channel): ChannelDecision
    {
        if ($principal->scp !== null) {
            return in_array($channel, $principal->scp, true) ? ChannelDecision::allowed('scope') : ChannelDecision::denied('out_of_scope');
        }

        if ($principal->isSystem()) {
            return ChannelDecision::allowed('system');
        }

        if (static::isSelfChannel($principal, $channel)) {
            return ChannelDecision::allowed('self');
        }

        $separator = strpos($channel, '.');
        $prefix    = $separator === false ? $channel : substr($channel, 0, $separator);
        $id        = $separator === false ? '' : substr($channel, $separator + 1);
        $resolver  = $this->registry->resolve($prefix);

        if ($resolver === null || $id === '') {
            return ChannelDecision::denied('unknown_prefix');
        }

        try {
            $allowed = $resolver instanceof SocketChannelResolver
                ? $resolver->authorize($principal, $id, $channel)
                : $resolver($principal, $id, $channel);
        } catch (\Throwable $e) {
            Log::warning('Socket channel resolver failed.', ['prefix' => $prefix, 'error' => $e->getMessage()]);

            return ChannelDecision::denied('resolver_error');
        }

        return $allowed ? ChannelDecision::allowed('resolver') : ChannelDecision::denied('forbidden');
    }

    /**
     * Whether setup has created a user yet. An unreachable or unmigrated database counts as not yet.
     */
    protected function instanceHasUsers(): bool
    {
        try {
            return User::query()->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
