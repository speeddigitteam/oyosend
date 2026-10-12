<?php

declare(strict_types=1);

use App\Modules\EmailMarketing\Support\ContentBlocks;
use App\Modules\EmailMarketing\Support\CsvSafe;
use App\Modules\EmailMarketing\Support\EmailRenderer;
use App\Modules\EmailMarketing\Support\SafeUrl;
use App\Modules\EmailMarketing\Support\SegmentRules;
use App\Modules\EmailMarketing\Support\Variables;
use App\Modules\EmailMarketing\Support\VariantSplitter;
use Illuminate\Validation\ValidationException;

$render = fn (array $blocks, array $values = [], ?Closure $track = null) => (new EmailRenderer)->render(
    $blocks, 'Subject', null, ['first_name' => 'Amina', 'unsubscribe_url' => 'https://acme.localhost/communication/unsubscribe/abc', ...$values], $track, '1 Road, Dhaka',
);

it('escapes everything: script tags, event handlers and attribute breakouts are inert text', function () use ($render) {
    $email = $render([
        ['type' => 'heading', 'text' => '<script>alert(1)</script>', 'level' => 1, 'align' => 'left'],
        ['type' => 'text', 'text' => '<img src=x onerror=alert(1)> "quoted" \' and <b>bold</b>', 'align' => 'left'],
    ], ['first_name' => '"><script>alert(2)</script>']);

    expect($email->html)->not->toContain('<script')
        ->and($email->html)->not->toContain('<img src=x')
        ->and($email->html)->not->toContain('<b>bold</b>')
        ->and($email->html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($email->html)->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('puts a contact name that looks like markup or formatting through as plain text', function () use ($render) {
    $email = $render([['type' => 'text', 'text' => 'Dear {{first_name}}, welcome', 'align' => 'left']], ['first_name' => '**x** [a](javascript:alert(1)) <i>']);

    expect($email->html)->not->toContain('<strong>')
        ->and($email->html)->not->toContain('href="javascript')
        ->and($email->html)->toContain('**x** [a](javascript:alert(1)) &lt;i&gt;');
});

it('drops javascript, data and credentialed links and keeps only the label', function () use ($render) {
    $email = $render([
        ['type' => 'text', 'text' => '[bad](javascript:alert(1)) and [data](data:text/html;base64,AAAA) and [creds](https://user:pw@example.com/) and [ok](https://example.com/a?b=1&c=2)', 'align' => 'left'],
        ['type' => 'button', 'label' => 'Click', 'url' => 'javascript:alert(1)', 'align' => 'left', 'style' => 'primary'],
        ['type' => 'image', 'url' => 'http://example.com/a.png', 'alt' => 'plain http', 'link_url' => null, 'width' => 600],
    ]);

    expect($email->html)->not->toContain('javascript:')
        ->and($email->html)->not->toContain('data:text')
        ->and($email->html)->not->toContain('user:pw')
        ->and($email->html)->not->toContain('<img')
        ->and($email->html)->toContain('href="https://example.com/a?b=1&amp;c=2"');
});

it('rejects unsafe urls, unknown block types and unknown variables when content is saved', function () {
    $bad = fn (array $block) => fn () => ContentBlocks::normalize([$block]);

    expect($bad(['type' => 'button', 'label' => 'x', 'url' => 'javascript:alert(1)']))->toThrow(ValidationException::class)
        ->and($bad(['type' => 'image', 'url' => 'http://example.com/a.png', 'alt' => 'x']))->toThrow(ValidationException::class)
        ->and($bad(['type' => 'html', 'html' => '<script>']))->toThrow(ValidationException::class)
        ->and($bad(['type' => 'text', 'text' => 'Hi {{password}}']))->toThrow(ValidationException::class)
        ->and($bad(['type' => 'text', 'text' => 'Hi {{ first_name | there }} {{']))->not->toThrow(ValidationException::class)
        ->and($bad(['type' => 'text', 'text' => '[x](javascript:alert(1))']))->toThrow(ValidationException::class)
        ->and(fn () => ContentBlocks::normalize([]))->toThrow(ValidationException::class)
        ->and(fn () => ContentBlocks::normalize(array_fill(0, 51, ['type' => 'divider'])))->toThrow(ValidationException::class)
        ->and(fn () => ContentBlocks::subject('Hi {{unsubscribe_url}}'))->toThrow(ValidationException::class)
        ->and(fn () => ContentBlocks::subject("Line one\nBcc: x@example.com"))->not->toThrow(ValidationException::class);

    expect(ContentBlocks::subject("Line one\r\nBcc: x@example.com"))->toBe('Line one Bcc: x@example.com');
});

it('renders the same content as html and plain text, with fallbacks, tracking and the unsubscribe footer', function () use ($render) {
    $email = $render([
        ['type' => 'heading', 'text' => 'Hello {{first_name|friend}}', 'level' => 2, 'align' => 'left'],
        ['type' => 'text', 'text' => "**Big** news.\n\nSee [the site](https://example.com/news).", 'align' => 'left'],
        ['type' => 'button', 'label' => 'Unsubscribe', 'url' => '{{unsubscribe_url}}', 'align' => 'left', 'style' => 'dark'],
        ['type' => 'divider'],
    ], ['first_name' => ''], fn (string $url): string => 'https://acme.localhost/t/'.base64_encode($url));

    expect($email->text)->toContain('Hello friend')
        ->and($email->text)->toContain('Big news.')
        ->and($email->text)->toContain('the site (https://acme.localhost/t/'.base64_encode('https://example.com/news').')')
        // The unsubscribe link is never click-tracked.
        ->and($email->text)->toContain('Unsubscribe: https://acme.localhost/communication/unsubscribe/abc')
        ->and($email->text)->not->toContain(base64_encode('https://acme.localhost/communication/unsubscribe/abc'))
        ->and($email->text)->toContain('1 Road, Dhaka')
        ->and($email->html)->toContain('<strong>Big</strong>')
        ->and($email->html)->toContain('href="https://acme.localhost/communication/unsubscribe/abc"');
});

it('lists unknown and malformed variables', function () {
    expect(Variables::unknown('Hi {{first_name}} {{ nope }} {{x|y}} {{FIRST_NAME}}'))->toBe(['{{ nope }}', '{{x|y}}', '{{FIRST_NAME}}'])
        ->and(Variables::replace('Hi {{first_name|there}}!', ['first_name' => null]))->toBe('Hi there!')
        ->and(Variables::replace('Hi {{first_name|there}}!', ['first_name' => 'Amina']))->toBe('Hi Amina!');
});

it('accepts only http, https and mailto links in the right places', function () {
    expect(SafeUrl::isAllowed('https://example.com/path?q=1'))->toBeTrue()
        ->and(SafeUrl::isAllowed('http://example.com'))->toBeTrue()
        ->and(SafeUrl::isAllowed('mailto:a@example.com'))->toBeFalse()
        ->and(SafeUrl::isAllowed('mailto:a@example.com', allowMailto: true))->toBeTrue()
        ->and(SafeUrl::isAllowed('JaVaScRiPt:alert(1)'))->toBeFalse()
        ->and(SafeUrl::isAllowed('//example.com'))->toBeFalse()
        ->and(SafeUrl::isAllowed("https://exa\tmple.com"))->toBeFalse()
        ->and(SafeUrl::isAllowed('https://example.com/<x>'))->toBeFalse()
        ->and(SafeUrl::isAllowed('https://'.str_repeat('a', 1001).'.com'))->toBeFalse()
        ->and(SafeUrl::isAllowed('{{unsubscribe_url}}', allowUnsubscribe: true))->toBeTrue()
        ->and(SafeUrl::isAllowed('{{unsubscribe_url}}'))->toBeFalse();
});

it('makes a CSV cell that a spreadsheet cannot execute', function () {
    expect(CsvSafe::cell('=HYPERLINK("http://evil")'))->toBe("'=HYPERLINK(\"http://evil\")")
        ->and(CsvSafe::cell('+1'))->toBe("'+1")
        ->and(CsvSafe::cell('-1'))->toBe("'-1")
        ->and(CsvSafe::cell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(CsvSafe::cell("\tx"))->toBe("'\tx")
        ->and(CsvSafe::cell('plain'))->toBe('plain');

    $csv = CsvSafe::render(['a'], [['=1+1'], ['x,"y"']]);
    expect($csv)->toContain("'=1+1")->and($csv)->toContain('"x,""y"""');
});

it('splits an audience deterministically', function () {
    $a = VariantSplitter::variant('campaign-1', 'contact-1');

    expect(VariantSplitter::variant('campaign-1', 'contact-1'))->toBe($a)
        ->and(VariantSplitter::bucket('campaign-1', 'contact-1'))->toBe(VariantSplitter::bucket('campaign-1', 'contact-1'));

    $counts = ['a' => 0, 'b' => 0];
    $test = 0;

    for ($i = 0; $i < 2000; $i++) {
        $counts[VariantSplitter::variant('c', 'contact-'.$i)]++;
        $test += VariantSplitter::inTestCohort('c', 'contact-'.$i, 20) ? 1 : 0;
    }

    expect($counts['a'])->toBeBetween(850, 1150)
        ->and($test)->toBeBetween(300, 500)
        ->and(VariantSplitter::inTestCohort('c', 'contact-1', 100))->toBeTrue();
});

it('validates declarative segment rules from fixed allow-lists only', function () {
    $ok = SegmentRules::normalize([
        ['field' => 'city', 'operator' => 'equals', 'value' => 'Dhaka'],
        ['field' => 'property:plan', 'operator' => 'contains', 'value' => 'pro'],
        ['field' => 'has_company', 'operator' => 'is_true', 'value' => null],
        ['field' => 'subscribed_after', 'operator' => 'on_or_after', 'value' => '2026-01-01'],
    ]);

    expect($ok)->toHaveCount(4);

    foreach ([
        [['field' => 'password', 'operator' => 'equals', 'value' => 'x']],
        [['field' => 'city', 'operator' => 'matches', 'value' => '.*']],
        [['field' => 'property:Bad Key', 'operator' => 'equals', 'value' => 'x']],
        [['field' => 'property:a`b', 'operator' => 'equals', 'value' => 'x']],
        [['field' => 'subscribed_after', 'operator' => 'on_or_after', 'value' => 'yesterday']],
        [],
    ] as $rules) {
        expect(fn () => SegmentRules::normalize($rules))->toThrow(ValidationException::class);
    }
});
