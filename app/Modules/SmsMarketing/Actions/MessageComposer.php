<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use App\Modules\SmsMarketing\Support\SegmentCount;
use App\Modules\SmsMarketing\Support\SegmentCounter;
use App\Modules\SmsMarketing\Support\TemplateVariables;
use Illuminate\Validation\ValidationException;

/**
 * Turns campaign text into the message that is sent, and checks text before it is saved.
 *
 * Every marketing SMS ends with the workspace's opt-out wording and a one-click unsubscribe link issued by the
 * communication platform. The link is part of the text, so it is counted in the segments.
 */
final class MessageComposer
{
    public function __construct(
        private readonly SmsSettings $settings,
        private readonly TenantContext $context,
        private readonly TenantHost $hosts,
    ) {}

    /**
     * Refuse a text with an unknown variable, a one-time code in marketing, broken braces, or more than the
     * maximum number of segments (measured with sample values and the opt-out line).
     *
     * @throws ValidationException
     */
    public function assertValid(string $body, TemplatePurpose $purpose, string $field = 'body'): SegmentCount
    {
        $body = trim($body);
        $max = (int) config('sms_marketing.max_body_length', 1000);

        if ($body === '') {
            throw ValidationException::withMessages([$field => __('Write the message text.')]);
        }

        if (mb_strlen($body) > $max) {
            throw ValidationException::withMessages([$field => __('The message can be at most :max characters.', ['max' => $max])]);
        }

        $report = TemplateVariables::inspect($body, $purpose);

        if ($report['malformed']) {
            throw ValidationException::withMessages([$field => __('A variable is not closed or has stray braces. Write variables as {{first_name}}.')]);
        }

        if ($report['unknown'] !== []) {
            throw ValidationException::withMessages([$field => __('Unknown variable: :names.', ['names' => implode(', ', $report['unknown'])])]);
        }

        if ($report['oneTimeInMarketing']) {
            throw ValidationException::withMessages([$field => __('One-time code variables are for transactional templates only.')]);
        }

        $count = $this->preview($body, $purpose === TemplatePurpose::Marketing);
        $limit = (int) config('sms_marketing.max_segments', 10);

        if ($count->segments > $limit) {
            throw ValidationException::withMessages([$field => __('The message takes :segments segments; the most allowed is :max. Shorten it.', [
                'segments' => $count->segments,
                'max' => $limit,
            ])]);
        }

        return $count;
    }

    /** Segments of the text with sample values, including the opt-out line when `$withOptOut`. */
    public function preview(string $body, bool $withOptOut = true): SegmentCount
    {
        $text = TemplateVariables::render($body, TemplateVariables::sampleValues());

        return SegmentCounter::count($withOptOut ? $text.$this->sampleSuffix() : $text);
    }

    /**
     * The text of a message to one contact, once the unsubscribe link exists.
     *
     * @param  array<string, string|null>  $values
     */
    public function compose(string $body, array $values, string $unsubscribeUrl): string
    {
        return TemplateVariables::render($body, $values).$this->suffix($unsubscribeUrl);
    }

    public function suffix(string $url): string
    {
        return "\n".$this->settings->optOutWording().' '.$url;
    }

    /** The opt-out line with a link of the length a real one has (40 random characters on the workspace host). */
    public function sampleSuffix(): string
    {
        return $this->suffix($this->hosts->url($this->context->require(), 'communication/unsubscribe/'.str_repeat('x', 40)));
    }
}
