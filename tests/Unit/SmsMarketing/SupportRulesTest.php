<?php

declare(strict_types=1);

use App\Modules\SmsMarketing\Enums\SenderIdType;
use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use App\Modules\SmsMarketing\Support\CsvSafe;
use App\Modules\SmsMarketing\Support\SenderIdRules;
use App\Modules\SmsMarketing\Support\TemplateVariables;

it('validates masking sender ids as 3 to 11 alphanumeric characters with a letter', function (string $value, bool $valid) {
    expect(SenderIdRules::isValid(SenderIdType::Masking, $value))->toBe($valid);
})->with([
    'brand' => ['ACME', true],
    'eleven characters' => ['ABCDEFGHIJK', true],
    'twelve characters' => ['ABCDEFGHIJKL', false],
    'three characters' => ['ABC', true],
    'two characters' => ['AB', false],
    'letters and digits' => ['Shop24', true],
    'digits only is not a masking id' => ['123456', false],
    'space' => ['AC ME', false],
    'punctuation' => ['ACME-BD', false],
    'bengali letters' => ['এসিএমই', false],
    'empty' => ['', false],
]);

it('validates non-masking sender ids as 3 to 15 digits with an optional plus', function (string $value, bool $valid) {
    expect(SenderIdRules::isValid(SenderIdType::NonMasking, $value))->toBe($valid);
})->with([
    'a short code' => ['16216', true],
    'a mobile number' => ['8801712345678', true],
    'with plus' => ['+8801712345678', true],
    'sixteen digits' => ['1234567890123456', false],
    'two digits' => ['12', false],
    'letters' => ['ACME1', false],
    'plus in the middle' => ['88+0171', false],
]);

it('guesses the type of an id from its shape', function () {
    expect(SenderIdRules::guess('ACME'))->toBe(SenderIdType::Masking)
        ->and(SenderIdRules::guess('16216'))->toBe(SenderIdType::NonMasking)
        ->and(SenderIdRules::guess('no way!'))->toBeNull();
});

it('renders variables with a fallback and never evaluates anything', function () {
    $values = ['first_name' => 'Rahim', 'last_name' => null, 'full_name' => 'Rahim Uddin'];

    expect(TemplateVariables::render('Hi {{first_name}} {{last_name|friend}}!', $values))->toBe('Hi Rahim friend!')
        ->and(TemplateVariables::render('Hi {{ first_name }}', $values))->toBe('Hi Rahim')
        ->and(TemplateVariables::render('Hi {{last_name}}.', $values))->toBe('Hi .')
        // Not a variable: left exactly as written, nothing is executed.
        ->and(TemplateVariables::render('{{ 1+1 }} {{constant("PHP_VERSION")}}', $values))->toBe('{{ 1+1 }} {{constant("PHP_VERSION")}}');
});

it('cleans values: control characters are removed and long names are cut', function () {
    $rendered = TemplateVariables::render('{{first_name}}', ['first_name' => "Ra\nhim\u{200B}".str_repeat('x', 100)]);

    expect($rendered)->not->toContain("\n")
        ->and(mb_strlen($rendered))->toBeLessThanOrEqual(TemplateVariables::VALUE_MAX);
});

it('inspects a text against the allow-list for each purpose', function () {
    $marketing = TemplateVariables::inspect('Hi {{first_name}} {{code}} {{salary}} {{', TemplatePurpose::Marketing);
    $transactional = TemplateVariables::inspect('Code {{code}} for {{first_name}}', TemplatePurpose::Transactional);

    expect($marketing['unknown'])->toBe(['salary'])
        ->and($marketing['oneTimeInMarketing'])->toBeTrue()
        ->and($marketing['malformed'])->toBeTrue()
        ->and($transactional)->toBe(['unknown' => [], 'oneTimeInMarketing' => false, 'malformed' => false])
        ->and(TemplateVariables::allowed(TemplatePurpose::Marketing))->toBe(['first_name', 'last_name', 'full_name', 'company_name']);
});

it('makes CSV cells safe to open in a spreadsheet', function (string $cell, string $expected) {
    expect(CsvSafe::cell($cell))->toBe($expected);
})->with([
    'formula' => ['=SUM(A1)', "'=SUM(A1)"],
    'plus' => ['+8801712345678', "'+8801712345678"],
    'minus' => ['-1', "'-1"],
    'at' => ['@cmd', "'@cmd"],
    'tab' => ["\tx", "'\tx"],
    'carriage return' => ["\rx", "'\rx"],
    'plain' => ['Rahim', 'Rahim'],
    'bengali' => ['রহিম', 'রহিম'],
    'empty' => ['', ''],
]);

it('quotes and prefixes every cell of a rendered file', function () {
    $csv = CsvSafe::render(['name', '=bad'], [['a,b', '@x'], ['say "hi"', "line\nbreak"]]);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain("'=bad")
        ->and($csv)->toContain('"a,b"')
        ->and($csv)->toContain("'@x")
        ->and($csv)->toContain('"say ""hi"""');
});
