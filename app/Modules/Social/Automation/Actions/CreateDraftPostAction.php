<?php

declare(strict_types=1);

namespace App\Modules\Social\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Automation\ActionReceiptStore;

/**
 * Creates a draft post only. It never submits, approves or schedules: those stay human
 * decisions, so an automation cannot bypass the approval step or publish on its own.
 */
final class CreateDraftPostAction implements ActionDefinition
{
    public function __construct(
        private readonly PostManager $posts,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'social.post.create_draft';
    }

    public static function label(): string
    {
        return 'automation.actions.social.post.create_draft';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function requiredPermission(): string
    {
        return 'social.posts.create';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['body', 'account_ids'],
            'properties' => [
                'title' => ['type' => ['string', 'null'], 'maxLength' => 160],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                'link_url' => ['type' => ['string', 'null'], 'maxLength' => 2048],
                'account_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['type' => 'string']],
                'campaign_id' => ['type' => ['string', 'null']],
                'category_id' => ['type' => ['string', 'null']],
                'hashtag_set_id' => ['type' => ['string', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['post_id', 'status'],
            'properties' => ['post_id' => ['type' => 'string'], 'status' => ['type' => 'string']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $link = $input['link_url'] ?? null;

            if ($link !== null && ! preg_match('~^https?://~i', (string) $link)) {
                throw new \InvalidArgumentException('The link must start with http:// or https://.');
            }

            $post = $this->posts->create(
                [
                    'title' => $input['title'] ?? null,
                    'body' => $input['body'] ?? null,
                    'link_url' => $link,
                    'campaign_id' => $input['campaign_id'] ?? null,
                    'category_id' => $input['category_id'] ?? null,
                    'hashtag_set_id' => $input['hashtag_set_id'] ?? null,
                ],
                array_map(static fn (mixed $id): array => ['account_id' => (string) $id], array_values((array) ($input['account_ids'] ?? []))),
                [],
            );

            return ['post_id' => $post->id, 'status' => $post->status->value];
        });
    }
}
