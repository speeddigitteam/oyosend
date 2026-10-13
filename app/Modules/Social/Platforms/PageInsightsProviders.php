<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use Illuminate\Contracts\Container\Container;

/** The insights providers listed in `config('social.insights.providers')`, by platform key. */
final class PageInsightsProviders
{
    /** @var array<string, PageInsightsProvider|null> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function for(string $platform): ?PageInsightsProvider
    {
        if (array_key_exists($platform, $this->resolved)) {
            return $this->resolved[$platform];
        }

        $class = config('social.insights.providers.'.$platform);
        $provider = is_string($class) && class_exists($class) ? $this->container->make($class) : null;

        return $this->resolved[$platform] = $provider instanceof PageInsightsProvider ? $provider : null;
    }

    /** @return list<string> Platform keys that have a provider. */
    public function platforms(): array
    {
        $keys = array_keys((array) config('social.insights.providers', []));

        return array_values(array_filter($keys, fn ($key): bool => is_string($key) && $this->for($key) !== null));
    }
}
