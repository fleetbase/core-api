<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Auth\OAuthServer\OAuthKeys;
use Illuminate\Console\Command;

/**
 * Generates the RSA key pair "Continue with Fleetbase" signs tokens with.
 */
class OAuthServerKeys extends Command
{
    protected $signature = 'oauth-server:keys
                            {--force : Overwrite existing key files}
                            {--show : Print the keys as OAUTH_SERVER_PRIVATE_KEY / OAUTH_SERVER_PUBLIC_KEY instead of writing files}';

    protected $description = 'Generate the key pair the OAuth provider signs access tokens and ID tokens with';

    public function handle(): int
    {
        $keys = OAuthKeys::generate();

        if ($this->option('show')) {
            $this->line('OAUTH_SERVER_PRIVATE_KEY="' . str_replace("\n", '\\n', trim($keys['private'])) . '"');
            $this->line('OAUTH_SERVER_PUBLIC_KEY="' . str_replace("\n", '\\n', trim($keys['public'])) . '"');

            return self::SUCCESS;
        }

        $private = OAuthKeys::keyPath('private');
        $public  = OAuthKeys::keyPath('public');
        if ((file_exists($private) || file_exists($public)) && !$this->option('force')) {
            $this->error('Keys already exist. Rotating them signs everyone out of every app; use --force to do it anyway.');

            return self::FAILURE;
        }

        file_put_contents($private, $keys['private']);
        file_put_contents($public, $keys['public']);
        @chmod($private, 0600);
        @chmod($public, 0644);

        $this->info('Wrote ' . $private . ' and ' . $public . '.');

        return self::SUCCESS;
    }
}
