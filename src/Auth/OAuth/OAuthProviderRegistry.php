<?php

namespace Fleetbase\Auth\OAuth;

use Fleetbase\Auth\OAuth\Contracts\OAuthProviderDriver;
use Fleetbase\Auth\OAuth\Exceptions\UnknownOAuthProviderException;
use Fleetbase\Services\OAuth\OAuthConfigRepository;
use Illuminate\Http\Request;

/**
 * Resolves provider ids to configured driver instances.
 *
 * This is the only place drivers are constructed, so it is also the seam that makes
 * "add a provider" a config-only change: the {provider} route segment, the public
 * provider-discovery endpoint and the admin settings form are all generated from
 * whatever this returns.
 */
class OAuthProviderRegistry
{
    public function __construct(
        private OAuthConfigRepository $config,
        private Request $request,
        private IdTokenVerifier $idTokenVerifier,
    ) {
    }

    /**
     * Every provider id this installation defines, configured or not.
     *
     * @return array<int, string>
     */
    public function ids(): array
    {
        return $this->config->providerIds();
    }

    public function has(string $provider): bool
    {
        return $this->driverClass($provider) !== null;
    }

    /**
     * @throws UnknownOAuthProviderException
     */
    public function driver(string $provider): OAuthProviderDriver
    {
        $class = $this->driverClass($provider);

        if ($class === null) {
            throw new UnknownOAuthProviderException('unknown_provider');
        }

        return new $class(
            $this->config->forProvider($provider),
            $this->request,
            $this->idTokenVerifier
        );
    }

    /**
     * Only the drivers an administrator has switched on AND fully configured.
     *
     * An enabled-but-misconfigured provider is excluded rather than surfaced as a
     * broken button: from the console's point of view it simply does not exist.
     *
     * @return array<string, OAuthProviderDriver>
     */
    public function enabled(): array
    {
        if (!$this->config->isEnabled()) {
            return [];
        }

        $enabled = [];

        foreach ($this->ids() as $id) {
            try {
                $driver = $this->driver($id);
            } catch (UnknownOAuthProviderException $e) {
                continue;
            }

            if ($driver->isEnabled()) {
                $enabled[$id] = $driver;
            }
        }

        return $enabled;
    }

    /**
     * The payload the console needs to render sign-in buttons.
     *
     * Contains no credentials — only what is required to draw a button.
     *
     * @return array<int, array{id: string, label: string, icon: string}>
     */
    public function toDiscoveryArray(): array
    {
        $providers = [];

        foreach ($this->enabled() as $id => $driver) {
            $providers[] = [
                'id'    => $id,
                'label' => $driver::label(),
                'icon'  => $driver::icon(),
            ];
        }

        return $providers;
    }

    /**
     * The config schema for every defined provider, for the admin settings form.
     *
     * @return array<string, array<string, array{label: string, secret?: bool, required?: bool, help?: string}>>
     */
    public function schemas(): array
    {
        $schemas = [];

        foreach ($this->ids() as $id) {
            $class = $this->driverClass($id);

            if ($class === null) {
                continue;
            }

            $schemas[$id] = $class::configSchema();
        }

        return $schemas;
    }

    /**
     * @return class-string<OAuthProviderDriver>|null
     */
    protected function driverClass(string $provider): ?string
    {
        $class = $this->config->driverClass($provider);

        if ($class === null || !class_exists($class) || !is_subclass_of($class, OAuthProviderDriver::class)) {
            return null;
        }

        return $class;
    }
}
