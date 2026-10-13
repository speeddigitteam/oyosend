<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/**
 * The permissions a token holds and the platform tasks (Meta: CREATE_CONTENT, MODERATE, ANALYZE...)
 * of the person who authorised it. `tasks` is null when the platform did not say, never an empty
 * list standing in for "none".
 */
final readonly class PageAccessReport
{
    public const string OK = 'ok';

    public const string PERMISSION_MISSING = 'permission_missing';

    public const string NOT_REPORTED = 'not_reported';

    public const string UNAVAILABLE = 'unavailable';

    /**
     * @param  list<string>|null  $scopes
     * @param  list<string>|null  $tasks
     */
    public function __construct(
        public ?array $scopes,
        public ?array $tasks,
        public string $status,
    ) {}
}
