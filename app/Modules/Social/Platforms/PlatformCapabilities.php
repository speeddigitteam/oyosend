<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use App\Modules\Social\Actions\PostContentRules;

/**
 * What an adapter really does. A flag is true only for something implemented and mapped to a
 * documented API call; the interface hides or rejects everything else.
 *
 * The media requirement fields (`requiresMedia` and after) describe what a post must carry for the
 * platform to accept it. Their defaults ask for nothing, so a text-capable platform is unaffected.
 * {@see PostContentRules} turns them into per-account issues
 * before a post can be submitted, approved or scheduled.
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
     * @param  bool  $requiresMedia  A text-only post is refused: at least one photo or video is needed.
     * @param  bool  $requiresVideo  Only video is published: a post needs exactly a video and no photos.
     *                               Implies `requiresMedia`.
     * @param  int  $maxVideoBytes  Largest video file the platform accepts, in bytes (0: no limit declared).
     * @param  int  $maxVideoSeconds  Longest video, in seconds (0: no limit declared).
     * @param  int  $minVideoSeconds  Shortest video, in seconds (0: no limit declared).
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
        public bool $requiresMedia = false,
        public bool $requiresVideo = false,
        public int $maxVideoBytes = 0,
        public int $maxVideoSeconds = 0,
        public int $minVideoSeconds = 0,
    ) {}

    /** Whether a post for this platform must carry media (a video-only platform does too). */
    public function needsMedia(): bool
    {
        return $this->requiresMedia || $this->requiresVideo;
    }

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
            'requiresMedia' => $this->needsMedia(),
            'requiresVideo' => $this->requiresVideo,
            'maxVideoBytes' => $this->maxVideoBytes,
            'maxVideoSeconds' => $this->maxVideoSeconds,
            'minVideoSeconds' => $this->minVideoSeconds,
        ];
    }
}
