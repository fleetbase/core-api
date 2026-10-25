<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Models\OAuthClient;
use Illuminate\Console\Command;

/**
 * Registers or rotates an app for "Continue with Fleetbase" from the command line, for scripted
 * setup (e.g. AppHelm on Fleetbase Cloud). Admin → Auth Config → OAuth Apps does the same.
 */
class OAuthServerClient extends Command
{
    protected $signature = 'oauth-server:client
                            {action : create, rotate or list}
                            {id? : The client id, for rotate}
                            {--name= : The app name shown on the consent screen}
                            {--redirect=* : A redirect URI (repeat for more than one)}
                            {--scope=* : An allowed scope (repeat; default: every registered scope)}
                            {--website= : The app\'s website}
                            {--logo= : The app\'s logo URL}
                            {--privacy= : The app\'s privacy policy URL}
                            {--terms= : The app\'s terms URL}
                            {--official : Built by Fleetbase; shows "Made by Fleetbase"}
                            {--public : A public client (native or single-page app) with no secret}
                            {--development : Allow http://127.0.0.1 redirect URIs on any port}';

    protected $description = 'Register, rotate or list apps that sign people in with Fleetbase';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'create' => $this->create(),
            'rotate' => $this->rotate(),
            'list'   => $this->list(),
            default  => $this->refuse('Unknown action; use create, rotate or list.'),
        };
    }

    protected function create(): int
    {
        $name      = $this->option('name');
        $redirects = $this->option('redirect');
        $scopes    = $this->option('scope') ?: array_keys(OAuthScopes::all());
        if (!$name || !$redirects) {
            return $this->refuse('--name and at least one --redirect are required.');
        }

        foreach ($redirects as $uri) {
            if (!OAuthClient::isAcceptableRedirectUri($uri, (bool) $this->option('development'))) {
                return $this->refuse("`{$uri}` must be https (http only for loopback on a --development app), without a fragment.");
            }
        }
        foreach ($scopes as $scope) {
            if (!OAuthScopes::has($scope)) {
                return $this->refuse("`{$scope}` is not a registered scope.");
            }
        }

        $client = new OAuthClient([
            'name'           => $name,
            'redirect_uris'  => array_values(array_unique($redirects)),
            'allowed_scopes' => array_values(array_unique($scopes)),
            'website_url'    => $this->option('website'),
            'logo_url'       => $this->option('logo'),
            'privacy_url'    => $this->option('privacy'),
            'terms_url'      => $this->option('terms'),
            'official'       => (bool) $this->option('official'),
            'development'    => (bool) $this->option('development'),
        ]);
        $secret = $this->option('public') ? null : $client->issueSecret();
        $client->save();

        $this->info('Registered ' . $client->name . '.');
        $this->line('client_id:     ' . $client->id);
        if ($secret) {
            $this->line('client_secret: ' . $secret);
            $this->warn('Store the secret now; it is not shown again.');
        }

        return self::SUCCESS;
    }

    protected function rotate(): int
    {
        $client = OAuthClient::find($this->argument('id'));
        if (!$client || !$client->isConfidential()) {
            return $this->refuse('No confidential app with that client id.');
        }

        $secret = $client->issueSecret();
        $client->save();

        $this->line('client_secret: ' . $secret);
        $this->warn('The old secret stopped working. Store the new one now; it is not shown again.');

        return self::SUCCESS;
    }

    protected function list(): int
    {
        $this->table(['client_id', 'name', 'type', 'redirect URIs', 'status'], OAuthClient::orderBy('name')->get()->map(fn (OAuthClient $client) => [
            $client->id,
            $client->name,
            $client->isConfidential() ? 'confidential' : 'public',
            implode("\n", $client->redirect_uris ?? []),
            $client->isDisabled() ? 'disabled' : 'active',
        ])->all());

        return self::SUCCESS;
    }

    protected function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
