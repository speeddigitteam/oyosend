<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Every platform this build knows. Adapters come from `config('social.adapters')`; the
 * platforms below them are listed only to say honestly why they cannot be connected.
 * Tests (and local development) may {@see extend()} with a fake bound only there.
 */
final class SocialPlatforms
{
    /** Platforms that are not driven by this build, with the reason shown in the UI. */
    private const array UNAVAILABLE = [
        'instagram' => ['Instagram', 'Publishing needs Instagram content-publishing approval from Meta and a publicly reachable media URL; workspace media is private, so it is not built yet.'],
        'youtube' => ['YouTube', 'Video upload needs a Google API audit and a resumable-upload adapter; it is not built yet.'],
        'tiktok' => ['TikTok', 'Posting needs TikTok Content Posting API approval and an audited app; it is not built yet.'],
        'pinterest' => ['Pinterest', 'Posting needs Pinterest API approval; it is not built yet.'],
    ];

    /** @var array<string, SocialPlatform>|null */
    private ?array $platforms = null;

    /** @var array<string, SocialPlatform> */
    private array $extra = [];

    public function __construct(private readonly Container $container) {}

    /** Add or replace a platform (tests / local fakes). */
    public function extend(SocialPlatform $platform): void
    {
        $this->extra[$platform->key()] = $platform;
        $this->platforms = null;
    }

    /** Forget extensions (test isolation). */
    public function reset(): void
    {
        $this->extra = [];
        $this->platforms = null;
    }

    /** @return array<string, SocialPlatform> */
    public function all(): array
    {
        if ($this->platforms !== null) {
            return $this->platforms;
        }

        $platforms = [];

        foreach ((array) config('social.adapters', []) as $class) {
            $adapter = $this->container->make($class);

            if ($adapter instanceof SocialPlatform) {
                $platforms[$adapter->key()] = $adapter;
            }
        }

        foreach (self::UNAVAILABLE as $key => [$label, $reason]) {
            $platforms[$key] ??= new UnavailablePlatform($key, $label, __($reason));
        }

        return $this->platforms = [...$platforms, ...$this->extra];
    }

    public function find(string $key): ?SocialPlatform
    {
        return $this->all()[$key] ?? null;
    }

    public function get(string $key): SocialPlatform
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown social platform [{$key}].");
    }
}
