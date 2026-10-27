<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Models\OAuthAccessToken;
use Fleetbase\Models\OAuthAuthCode;
use Fleetbase\Models\OAuthClient;
use Fleetbase\Models\OAuthRefreshToken;
use Illuminate\Console\Command;

/**
 * Deletes OAuth codes and tokens that expired more than a week ago, and self-registered clients
 * that never got a grant.
 *
 * The week's grace keeps a replayed code or refresh token recognisable for a while, so a replay
 * still revokes what it should instead of looking like an unknown token. A client registered at
 * POST /oauth/register registers again the next time its site connects, so one left unused for
 * `oauth-server.registration.unused_client_hours` is safe to drop.
 */
class OAuthServerPrune extends Command
{
    protected $signature = 'oauth-server:prune {--days=7 : Keep expired rows this many days}';

    protected $description = 'Delete expired OAuth authorization codes and tokens, and unused self-registered clients';

    public function handle(): int
    {
        $before = now()->subDays(max(0, (int) $this->option('days')));

        $codes   = OAuthAuthCode::where('expires_at', '<', $before)->delete();
        $refresh = OAuthRefreshToken::where('expires_at', '<', $before)->delete();
        $access  = OAuthAccessToken::where('expires_at', '<', $before)
            ->whereNotIn('id', OAuthRefreshToken::select('access_token_id'))
            ->delete();

        $clients = OAuthClient::whereNotNull('software_id')
            ->where('created_at', '<', now()->subHours(max(1, (int) config('oauth-server.registration.unused_client_hours', 24))))
            ->whereDoesntHave('grants')
            ->delete();

        $this->info("Deleted {$codes} codes, {$access} access tokens, {$refresh} refresh tokens and {$clients} unused registered clients.");

        return self::SUCCESS;
    }
}
