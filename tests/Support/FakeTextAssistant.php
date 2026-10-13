<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Ai\Text\Sentiment;
use App\Core\Ai\Text\TextKind;
use App\Core\Ai\Text\TextResult;
use App\Core\Ai\Text\TextState;
use App\Core\Ai\Text\TextTask;
use Closure;

/**
 * A scripted TextAssistant for tests of modules that CONSUME the contract (WhatsApp reply suggestions, Social
 * captions). It lives in tests/Support and is never bound outside the test suite. Records every task it got.
 *
 * Results are served in order; with an empty queue it answers an ok result with `Fake text.`
 */
final class FakeTextAssistant implements TextAssistant
{
    /** @var list<TextResult|Closure(TextTask): TextResult> */
    private array $queue = [];

    /** @var list<TextTask> */
    public array $tasks = [];

    public function generate(TextTask $task): TextResult
    {
        $this->tasks[] = $task;
        $next = array_shift($this->queue);

        if ($next instanceof Closure) {
            return $next($task);
        }

        return $next ?? self::ok($task->kind, 'Fake text.');
    }

    /** @param TextResult|Closure(TextTask): TextResult $result */
    public function push(TextResult|Closure $result): self
    {
        $this->queue[] = $result;

        return $this;
    }

    public static function ok(TextKind $kind, string $text): TextResult
    {
        return new TextResult(TextState::Ok, $kind, $text, promptVersion: 'fake', inputTokens: 10, outputTokens: 5);
    }

    /** @param list<string> $tags without `#` */
    public static function hashtags(array $tags): TextResult
    {
        return new TextResult(TextState::Ok, TextKind::Hashtags, implode(' ', array_map(static fn (string $t): string => '#'.$t, $tags)), $tags, promptVersion: 'fake');
    }

    public static function sentiment(Sentiment $sentiment, int $basisPoints = 0, ?string $language = 'en'): TextResult
    {
        return new TextResult(TextState::Ok, TextKind::Sentiment, $sentiment->value, [], $sentiment, $basisPoints, $language, promptVersion: 'fake');
    }

    public static function refused(TextState $state, TextKind $kind = TextKind::Caption): TextResult
    {
        return TextResult::unavailable($state, $kind);
    }
}
