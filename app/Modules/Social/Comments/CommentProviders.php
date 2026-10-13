<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments;

use App\Modules\Social\Comments\Contracts\CommentProvider;
use Illuminate\Contracts\Container\Container;

/** The comment providers listed in `config('social_comments.providers')`, by platform key. */
final class CommentProviders
{
    /** @var array<string, CommentProvider|null> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /** The provider for a platform, or null when none exists or the feature is turned off. */
    public function for(string $platform): ?CommentProvider
    {
        if (! (bool) config('social_comments.enabled', true)) {
            return null;
        }

        if (array_key_exists($platform, $this->resolved)) {
            return $this->resolved[$platform];
        }

        $class = config('social_comments.providers.'.$platform);
        $provider = is_string($class) && class_exists($class) ? $this->container->make($class) : null;

        return $this->resolved[$platform] = $provider instanceof CommentProvider ? $provider : null;
    }

    /** @return list<string> */
    public function platforms(): array
    {
        $keys = array_keys((array) config('social_comments.providers', []));

        return array_values(array_filter($keys, fn ($key): bool => is_string($key) && $this->for($key) !== null));
    }
}
