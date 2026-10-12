<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

use Closure;

/**
 * Turns validated blocks into the HTML and plain-text versions of one email for one recipient.
 *
 * Everything that reaches the HTML goes through `e()`; no input is ever emitted raw, so a script tag, an event
 * handler or a `javascript:` link typed into a block can only appear as inert text or be dropped. Links are
 * re-checked here ({@see SafeUrl}) even though {@see ContentBlocks} validated them on save. Variables are
 * substituted per segment after the formatting is parsed, so a contact's name cannot inject formatting.
 */
final class EmailRenderer
{
    private const string PRIMARY = '#1d4ed8';

    private const string DARK = '#111827';

    private const string FONT = "-apple-system,'Segoe UI',Roboto,Arial,sans-serif";

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, string|null>  $values  Variable values (raw text).
     * @param  (Closure(string): string)|null  $track  Wraps an outbound link in a click-tracking link.
     */
    public function render(
        array $blocks,
        string $subject,
        ?string $preheader,
        array $values,
        ?Closure $track = null,
        ?string $footerAddress = null,
        ?string $webVersionUrl = null,
    ): RenderedEmail {
        $values = array_map(static fn (?string $value): ?string => $value === null ? null : ContentBlocks::line($value), $values);
        $html = [];
        $text = [];

        foreach ($blocks as $block) {
            [$blockHtml, $blockText] = $this->block($block, $values, $track);
            $html[] = $blockHtml;
            $text[] = $blockText;
        }

        $subjectLine = ContentBlocks::line(Variables::replace($subject, $values));
        $unsubscribeUrl = $this->safe((string) ($values[Variables::UNSUBSCRIBE] ?? ''), false);

        return new RenderedEmail(
            $subjectLine,
            $this->document($subjectLine, $preheader === null ? null : Variables::replace($preheader, $values), implode('', $html), $footerAddress, $unsubscribeUrl, $webVersionUrl),
            $this->plain($text, $footerAddress, $unsubscribeUrl, $webVersionUrl),
        );
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, string|null>  $values
     * @param  (Closure(string): string)|null  $track
     * @return array{0: string, 1: string} HTML and plain text
     */
    private function block(array $block, array $values, ?Closure $track): array
    {
        $align = ($block['align'] ?? 'left') === 'center' ? 'center' : 'left';

        switch ($block['type'] ?? '') {
            case 'heading':
                $size = [1 => 28, 2 => 22, 3 => 18][(int) ($block['level'] ?? 2)] ?? 22;
                $value = Variables::replace((string) ($block['text'] ?? ''), $values);

                return [
                    '<h'.(int) $block['level'].' style="margin:0 0 16px;font-family:'.self::FONT.';font-size:'.$size.'px;line-height:1.3;color:'.self::DARK.';text-align:'.$align.';">'.e($value).'</h'.(int) $block['level'].'>',
                    $value."\n",
                ];

            case 'text':
                $paragraphs = preg_split('/\n{2,}/', (string) ($block['text'] ?? '')) ?: [];
                $html = '';
                $plain = [];

                foreach ($paragraphs as $paragraph) {
                    $html .= '<p style="margin:0 0 16px;font-family:'.self::FONT.';font-size:16px;line-height:1.6;color:#374151;text-align:'.$align.';">'
                        .str_replace("\n", '<br>', $this->inline($paragraph, $values, $track, true)).'</p>';
                    $plain[] = $this->inline($paragraph, $values, $track, false);
                }

                return [$html, implode("\n\n", $plain)."\n"];

            case 'image':
                $url = $this->safe((string) ($block['url'] ?? ''), false);

                if ($url === null || ! str_starts_with($url, 'https://')) {
                    return ['', ''];
                }

                $alt = Variables::replace((string) ($block['alt'] ?? ''), $values);
                $img = '<img src="'.e($url).'" alt="'.e($alt).'" width="'.(int) ($block['width'] ?? 600).'" style="display:block;max-width:100%;height:auto;border:0;margin:0 0 16px;">';
                $link = $this->link((string) ($block['link_url'] ?? ''), $values, $track);

                return [
                    $link === null ? $img : '<a href="'.e($link).'">'.$img.'</a>',
                    '['.$alt.']'.($link === null ? '' : ' '.$link)."\n",
                ];

            case 'button':
                $label = Variables::replace((string) ($block['label'] ?? ''), $values);
                $url = $this->link((string) ($block['url'] ?? ''), $values, $track);

                if ($url === null) {
                    return ['', $label."\n"];
                }

                $color = ($block['style'] ?? 'primary') === 'dark' ? self::DARK : self::PRIMARY;

                return [
                    '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="'.$align.'" style="margin:0 0 16px;"><tr><td style="background:'.$color.';border-radius:6px;">'
                        .'<a href="'.e($url).'" style="display:inline-block;padding:12px 24px;font-family:'.self::FONT.';font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;">'.e($label).'</a>'
                        .'</td></tr></table>',
                    $label.': '.$url."\n",
                ];

            case 'divider':
                return ['<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0;">', "----------\n"];

            case 'spacer':
                $height = min(96, max(8, (int) ($block['height'] ?? 24)));

                return ['<div style="height:'.$height.'px;line-height:'.$height.'px;font-size:0;">&nbsp;</div>', "\n"];
        }

        return ['', ''];
    }

    /**
     * Parse `[label](url)`, `**bold**` and `*italic*`, then substitute variables into each piece separately.
     *
     * @param  array<string, string|null>  $values
     * @param  (Closure(string): string)|null  $track
     */
    private function inline(string $raw, array $values, ?Closure $track, bool $html): string
    {
        $out = '';
        $offset = 0;

        preg_match_all(ContentBlocks::LINK_PATTERN, $raw, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($found as $match) {
            [$whole, $position] = $match[0];
            $out .= $this->emphasis(substr($raw, $offset, $position - $offset), $values, $html);
            $offset = $position + strlen($whole);

            $label = Variables::replace($match[1][0], $values);
            $url = $this->link($match[2][0], $values, $track);

            if ($url === null) {
                $out .= $html ? e($label) : $label;
            } elseif ($html) {
                $out .= '<a href="'.e($url).'" style="color:'.self::PRIMARY.';text-decoration:underline;">'.e($label).'</a>';
            } else {
                $out .= $label.' ('.$url.')';
            }
        }

        return $out.$this->emphasis(substr($raw, $offset), $values, $html);
    }

    /** @param  array<string, string|null>  $values */
    private function emphasis(string $raw, array $values, bool $html): string
    {
        $parts = preg_split('/(\*\*[^*\n]{1,300}\*\*|\*[^*\s][^*\n]{0,300}\*)/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';

        foreach ($parts as $part) {
            if (preg_match('/^\*\*(.+)\*\*$/s', $part, $strong) === 1) {
                $value = Variables::replace($strong[1], $values);
                $out .= $html ? '<strong>'.e($value).'</strong>' : $value;
            } elseif (preg_match('/^\*(.+)\*$/s', $part, $em) === 1) {
                $value = Variables::replace($em[1], $values);
                $out .= $html ? '<em>'.e($value).'</em>' : $value;
            } else {
                $value = Variables::replace($part, $values);
                $out .= $html ? e($value) : $value;
            }
        }

        return $out;
    }

    /**
     * A link target ready to print, or null when it is not allowed (or an unsubscribe link that is unavailable).
     *
     * @param  array<string, string|null>  $values
     * @param  (Closure(string): string)|null  $track
     */
    private function link(string $url, array $values, ?Closure $track): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $isUnsubscribe = preg_match('/^\{\{\s*'.Variables::UNSUBSCRIBE.'\s*\}\}$/', $url) === 1;
        $resolved = $isUnsubscribe ? trim((string) ($values[Variables::UNSUBSCRIBE] ?? '')) : $url;

        if (! SafeUrl::isAllowed($resolved, true)) {
            return null;
        }

        if ($isUnsubscribe || $track === null || str_starts_with(strtolower($resolved), 'mailto:')) {
            return $resolved;
        }

        return $track($resolved);
    }

    private function safe(string $url, bool $allowMailto): ?string
    {
        return $url !== '' && SafeUrl::isAllowed($url, $allowMailto) ? $url : null;
    }

    private function document(string $subject, ?string $preheader, string $body, ?string $address, ?string $unsubscribeUrl, ?string $webVersionUrl): string
    {
        $footer = '';

        if ($address !== null && trim($address) !== '') {
            $footer .= '<p style="margin:0 0 8px;">'.nl2br(e(ContentBlocks::multiline($address))).'</p>';
        }

        if ($webVersionUrl !== null) {
            $footer .= '<p style="margin:0 0 8px;"><a href="'.e($webVersionUrl).'" style="color:#6b7280;">'.e(__('View this email in your browser')).'</a></p>';
        }

        $footer .= $unsubscribeUrl === null
            ? '<p style="margin:0;">'.e(__('Use the unsubscribe link in your email to stop receiving these messages.')).'</p>'
            : '<p style="margin:0;"><a href="'.e($unsubscribeUrl).'" style="color:#6b7280;">'.e(__('Unsubscribe')).'</a></p>';

        $hidden = $preheader === null || trim($preheader) === ''
            ? ''
            : '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'.e(ContentBlocks::line($preheader)).'</div>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e($subject).'</title></head>'
            .'<body style="margin:0;padding:0;background:#f4f4f5;">'.$hidden
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:24px 12px;">'
            .'<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;">'
            .'<tr><td style="padding:32px;">'.$body.'</td></tr>'
            .'<tr><td style="padding:0 32px 32px;font-family:'.self::FONT.';font-size:12px;line-height:1.5;color:#6b7280;"><hr style="border:0;border-top:1px solid #e5e7eb;margin:0 0 16px;">'.$footer.'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    /** @param  list<string>  $blocks */
    private function plain(array $blocks, ?string $address, ?string $unsubscribeUrl, ?string $webVersionUrl): string
    {
        $text = trim(implode("\n", array_filter($blocks, static fn (string $block): bool => $block !== '')));
        $footer = [];

        if ($address !== null && trim($address) !== '') {
            $footer[] = ContentBlocks::multiline($address);
        }

        if ($webVersionUrl !== null) {
            $footer[] = __('View this email in your browser').': '.$webVersionUrl;
        }

        $footer[] = $unsubscribeUrl === null
            ? __('Use the unsubscribe link in your email to stop receiving these messages.')
            : __('Unsubscribe').': '.$unsubscribeUrl;

        return $text."\n\n--\n".implode("\n", $footer);
    }
}
