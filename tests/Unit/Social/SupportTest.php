<?php

declare(strict_types=1);

use App\Modules\Social\Actions\CsvImporter;
use App\Modules\Social\Support\CsvSafe;
use App\Modules\Social\Support\HashtagNormalizer;
use Illuminate\Validation\ValidationException;

it('neutralises spreadsheet formulas in every CSV cell it writes', function () {
    foreach (['=SUM(A1)', '+1', '-2', '@cmd', "\tTAB", "\rCR"] as $dangerous) {
        expect(CsvSafe::cell($dangerous))->toStartWith("'");
    }

    expect(CsvSafe::cell('Normal text'))->toBe('Normal text')->and(CsvSafe::cell(null))->toBe('');

    $csv = CsvSafe::render(['title', 'body'], [['=HYPERLINK("http://x")', "line one\nline \"two\""]]);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain("'=HYPERLINK(")
        ->and($csv)->not->toContain(',=HYPERLINK');
});

it('ships a template that only uses the accepted columns', function () {
    $template = CsvImporter::template();
    $header = str_getcsv(explode("\n", ltrim($template, "\xEF\xBB\xBF"))[0], ',', '"', '');

    expect($header)->toBe(CsvImporter::COLUMNS);
});

it('normalises hashtags typed as text and rejects what is not a hashtag', function () {
    expect(HashtagNormalizer::normalize('#One two,#THREE; one'))->toBe(['#One', '#two', '#THREE'])
        ->and(HashtagNormalizer::normalize(['#বাংলা', 'tag_2']))->toBe(['#বাংলা', '#tag_2']);

    expect(fn () => HashtagNormalizer::normalize('bad-tag!'))->toThrow(ValidationException::class);
    expect(fn () => HashtagNormalizer::normalize('   '))->toThrow(ValidationException::class);
    expect(fn () => HashtagNormalizer::normalize(array_map(fn (int $i): string => 'tag'.$i, range(1, 31))))->toThrow(ValidationException::class);
});
