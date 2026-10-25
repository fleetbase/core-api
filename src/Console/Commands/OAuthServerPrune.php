<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Models\OAuthAccessToken;
use Fleetbase\Models\OAuthAuthCode;
use Fleetbase\Models\OAuthRefreshToken;
use Illuminate\Console\Command;

/**
 * Deletes OAuth codes and tokens that expired more than a week ago.
 *
 * The week's grace keeps a replayed code or refresh token recognisable for a while, so a replay
 * still revokes what it should instead of looking like an unknown token.
 */
class OAuthServerPrune extends Command
{
    protected $signature = 'oauth-server:prune {--days=7 : Keep expired rows this many days}';

    protected $description = 'Delete expired OAuth authorization codes and tokens';

    public function handle(): int
    {
        $before = now()->subDays(max(0, (int) $this->option('days')));

        $codes   = OAuthAuthCode::where('expires_at', '<', $before)->delete();
        $refresh = OAuthRefreshToken::where('expires_at', '<', $before)->delete();
        $access  = OAuthAccessToken::where('expires_at', '<', $before)
            ->whereNotIn('id', OAuthRefreshToken::select('access_token_id'))
            ->delete();

        $this->info("Deleted {$codes} codes, {$access} access tokens and {$refresh} refresh tokens.");

        return self::SUCCESS;
    }
}
