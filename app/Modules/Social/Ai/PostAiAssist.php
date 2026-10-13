<?php

declare(strict_types=1);

namespace App\Modules\Social\Ai;

use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Ai\Text\ContextBlock;
use App\Core\Ai\Text\TextKind;
use App\Core\Ai\Text\TextResult;
use App\Core\Ai\Text\TextState;
use App\Core\Ai\Text\TextTask;
use App\Core\Ai\Text\TextTone;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\SocialPlatforms;

/**
 * Suggestions for the post composer: a caption, hashtags, and a rewritten, shortened or translated text.
 *
 * Everything goes through the core TextAssistant contract (ADR 0024). The result is text for a person to
 * accept, edit or ignore: nothing is saved, applied, scheduled or published here. The caller checks the
 * permission first; the contract applies the per-member and per-workspace rate limits and the plan allowance.
 */
final class PostAiAssist
{
    public const string OP_CAPTION = 'caption';

    public const string OP_HASHTAGS = 'hashtags';

    public const string OP_REWRITE = 'rewrite';

    public const string OP_SHORTEN = 'shorten';

    public const string OP_TRANSLATE = 'translate';

    /** Tones the composer offers (a subset of the contract's closed list). */
    public const array TONES = ['friendly', 'professional', 'playful', 'persuasive', 'formal'];

    /** Output language choices: `same` keeps the language of the input. */
    public const array LANGUAGES = ['en', 'bn', 'same'];

    /** Used when no account is chosen yet: a safe length that fits every supported platform text limit. */
    public const int DEFAULT_LENGTH = 280;

    public function __construct(
        private readonly TextAssistant $assistant,
        private readonly SocialPlatforms $platforms,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request data (see AiAssistRequest)
     * @return array<string, mixed>
     */
    public function run(string $operation, string $membershipId, array $input): array
    {
        $limit = $this->targetLength($input);
        $platform = $this->platformKey((array) ($input['account_ids'] ?? []));

        $task = match ($operation) {
            self::OP_CAPTION => $this->captionTask($input, $membershipId, $limit, $platform),
            self::OP_HASHTAGS => $this->hashtagTask($input, $membershipId, $platform),
            self::OP_REWRITE => $this->rewriteTask($input, $membershipId, $limit, $platform),
            self::OP_SHORTEN => $this->shortenTask($input, $membershipId, $limit, $platform),
            self::OP_TRANSLATE => $this->translateTask($input, $membershipId, $limit, $platform),
            default => null,
        };

        if ($task === null) {
            return $this->payload(TextResult::unavailable(TextState::Failed, TextKind::Caption), $operation, $limit);
        }

        return $this->payload($this->assistant->generate($task), $operation, $limit);
    }

    /**
     * The longest text that fits every chosen account (smallest platform limit), else a conservative default.
     *
     * @param  array<string, mixed>  $input
     */
    public function targetLength(array $input): int
    {
        $limits = [];

        foreach ($this->accounts((array) ($input['account_ids'] ?? [])) as $account) {
            $max = $this->platforms->find($account->platform)?->capabilities()->maxText ?? 0;

            if ($max > 0) {
                $limits[] = $max;
            }
        }

        $limit = $limits === [] ? self::DEFAULT_LENGTH : min($limits);

        return max(1, min($limit, TextTask::MAX_LENGTH));
    }

    /** @param array<string, mixed> $input */
    private function captionTask(array $input, string $membershipId, int $limit, ?string $platform): TextTask
    {
        [$source, $text] = $this->pick($input, ['notes', 'body', 'title']);

        return new TextTask(
            kind: TextKind::Caption,
            input: $text,
            purpose: $this->purpose(self::OP_CAPTION, $membershipId, $input),
            locale: $this->locale($input),
            tone: $this->tone($input),
            maxLength: $limit,
            platform: $platform,
            context: $this->context($input, skip: $source),
            actorMembershipId: $membershipId,
        );
    }

    /** @param array<string, mixed> $input */
    private function hashtagTask(array $input, string $membershipId, ?string $platform): TextTask
    {
        $count = max(1, min((int) ($input['count'] ?? 8), 15));
        [$source, $text] = $this->pick($input, ['body', 'notes', 'title']);

        return new TextTask(
            kind: TextKind::Hashtags,
            input: $text,
            purpose: $this->purpose(self::OP_HASHTAGS, $membershipId, $input),
            locale: $this->locale($input),
            maxItems: $count,
            platform: $platform,
            context: $this->context($input, skip: $source),
            actorMembershipId: $membershipId,
        );
    }

    /** @param array<string, mixed> $input */
    private function rewriteTask(array $input, string $membershipId, int $limit, ?string $platform): TextTask
    {
        return new TextTask(
            kind: TextKind::Caption,
            input: $this->firstFilled($input, ['body']),
            purpose: $this->purpose(self::OP_REWRITE, $membershipId, $input),
            locale: $this->locale($input),
            tone: $this->tone($input),
            maxLength: $limit,
            platform: $platform,
            actorMembershipId: $membershipId,
        );
    }

    /** @param array<string, mixed> $input */
    private function shortenTask(array $input, string $membershipId, int $limit, ?string $platform): TextTask
    {
        $text = $this->firstFilled($input, ['body']);
        // Shorter than what is there, and always inside the platform limit.
        $length = max(20, min($limit, (int) floor(mb_strlen($text) * 0.6)));

        return new TextTask(
            kind: TextKind::Summary,
            input: $text,
            purpose: $this->purpose(self::OP_SHORTEN, $membershipId, $input),
            locale: $this->locale($input),
            maxLength: min($length, TextTask::MAX_LENGTH),
            platform: $platform,
            actorMembershipId: $membershipId,
        );
    }

    /** @param array<string, mixed> $input */
    private function translateTask(array $input, string $membershipId, int $limit, ?string $platform): TextTask
    {
        $locale = in_array($input['language'] ?? null, ['en', 'bn'], true) ? (string) $input['language'] : 'en';

        return new TextTask(
            kind: TextKind::Translate,
            input: $this->firstFilled($input, ['body']),
            purpose: $this->purpose(self::OP_TRANSLATE, $membershipId, $input),
            locale: $locale,
            maxLength: $limit,
            platform: $platform,
            actorMembershipId: $membershipId,
        );
    }

    /**
     * A new `attempt` is a new purpose, so "regenerate" asks the provider again; the same attempt of the same
     * request is metered once and replayed.
     *
     * @param  array<string, mixed>  $input
     */
    private function purpose(string $operation, string $membershipId, array $input): string
    {
        $attempt = max(1, min((int) ($input['attempt'] ?? 1), 50));

        return 'social:'.$operation.':'.substr(hash('sha256', $membershipId.'|'.$operation.'|'.$attempt), 0, 40);
    }

    /** @param array<string, mixed> $input */
    private function locale(array $input): ?string
    {
        $language = (string) ($input['language'] ?? 'same');

        return in_array($language, ['en', 'bn'], true) ? $language : null;
    }

    /** @param array<string, mixed> $input */
    private function tone(array $input): TextTone
    {
        $tone = TextTone::tryFrom((string) ($input['tone'] ?? ''));

        return $tone !== null && in_array($tone->value, self::TONES, true) ? $tone : TextTone::Neutral;
    }

    /**
     * The first non-empty field and its key: the text the model works on. The request validated that one exists.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     * @return array{0: string, 1: string}
     */
    private function pick(array $input, array $keys): array
    {
        foreach ($keys as $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value !== '') {
                return [$key, $value];
            }
        }

        return ['', ''];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     */
    private function firstFilled(array $input, array $keys): string
    {
        return $this->pick($input, $keys)[1];
    }

    /**
     * Title and link as quoted reference data (the link is never opened or fetched).
     *
     * @param  array<string, mixed>  $input
     * @return list<ContextBlock>
     */
    private function context(array $input, string $skip): array
    {
        $blocks = [];

        foreach (['title' => 'post_title', 'notes' => 'notes', 'link_url' => 'link', 'body' => 'current_text'] as $key => $label) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value === '' || $key === $skip) {
                continue;
            }

            $blocks[] = new ContextBlock($label, $value);
        }

        return $blocks;
    }

    /**
     * @param  list<mixed>  $ids
     * @return list<SocialAccount>
     */
    private function accounts(array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => $id !== ''));

        return $ids === [] ? [] : SocialAccount::query()->whereIn('id', array_slice($ids, 0, 20))->get()->all();
    }

    /**
     * One platform key when every chosen account is on the same platform, else null.
     *
     * @param  list<mixed>  $ids
     */
    private function platformKey(array $ids): ?string
    {
        $keys = array_values(array_unique(array_map(static fn (SocialAccount $a): string => (string) $a->platform, $this->accounts($ids))));

        return count($keys) === 1 && preg_match('/^[a-z0-9_]{1,32}$/', $keys[0]) === 1 ? $keys[0] : null;
    }

    /** @return array<string, mixed> */
    private function payload(TextResult $result, string $operation, int $limit): array
    {
        return [
            'operation' => $operation,
            'state' => $result->state->value,
            'message' => $result->message,
            'retryable' => $result->retryable,
            'aiGenerated' => $result->aiGenerated(),
            'text' => $result->ok() ? $result->text : '',
            'hashtags' => $result->ok() ? array_map(static fn (string $tag): string => '#'.ltrim($tag, '#'), $result->hashtags) : [],
            'maxLength' => $limit,
            'replayed' => $result->replayed,
        ];
    }
}
