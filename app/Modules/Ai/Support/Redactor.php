<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/**
 * Pattern based masking of personal data before text leaves for the provider. A safeguard, not a
 * guarantee: patterns come from `config('ai.redaction.patterns')` (name => regex, replaced by `[name]`).
 */
final class Redactor
{
    public function redact(string $text): string
    {
        $patterns = config('ai.redaction.patterns', []);

        if (! is_array($patterns)) {
            return $text;
        }

        foreach ($patterns as $name => $pattern) {
            if (! is_string($pattern) || @preg_match($pattern, '') === false) {
                continue;
            }

            $text = (string) preg_replace($pattern, '['.$name.']', $text);
        }

        return $text;
    }

    /**
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     * @return list<array{role: string, content: string|list<array<string, mixed>>}>
     */
    public function redactMessages(array $messages): array
    {
        return array_map(function (array $message): array {
            $content = $message['content'];

            if (is_string($content)) {
                $message['content'] = $this->redact($content);

                return $message;
            }

            $message['content'] = array_map(function (array $block): array {
                foreach (['text', 'content'] as $field) {
                    if (isset($block[$field]) && is_string($block[$field])) {
                        $block[$field] = $this->redact($block[$field]);
                    }
                }

                return $block;
            }, $content);

            return $message;
        }, $messages);
    }
}
