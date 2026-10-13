<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/**
 * What an adapter really does. A flag is true only for something implemented and mapped to a
 * documented API call; the interface hides or rejects everything else.
 */
final readonly class PlatformCapabilities
{
    /**
     * @param  bool  $publish  Can create a post through the API.
     * @param  bool  $schedule  Posts can be scheduled for this platform. Scheduling is done by this
     *                          application (publish at the due minute), so it follows `publish`.
     * @param  bool  $analytics  Can read engagement figures for published posts.
     * @param  bool  $comments  Can read or answer audience comments (no adapter does yet).
     * @param  bool  $images  Can publish image media.
     * @param  bool  $video  Can publish video media.
     * @param  int  $maxText  Longest text the platform accepts, in characters.
     * @param  int  $maxMedia  Most media items per post.
     * @param  bool  $insights  Can read Page-level (account) insights through a {@see PageInsightsProvider}.
     */
    public function __construct(
        public bool $publish = false,
        public bool $schedule = false,
        public bool $analytics = false,
        public bool $comments = false,
        public bool $images = false,
        public bool $video = false,
        public int $maxText = 0,
        public int $maxMedia = 0,
        public bool $insights = false,
    ) {}

    /** @return array<string, bool|int> */
    public function toArray(): array
    {
        return [
            'publish' => $this->publish,
            'schedule' => $this->schedule,
            'analytics' => $this->analytics,
            'comments' => $this->comments,
            'images' => $this->images,
            'video' => $this->video,
            'maxText' => $this->maxText,
            'maxMedia' => $this->maxMedia,
            'insights' => $this->insights,
        ];
    }
}
