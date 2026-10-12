<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Category;
use App\Modules\Social\Models\HashtagSet;
use App\Modules\Social\Models\Import;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Support\CsvSafe;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bulk scheduling from a CSV file.
 *
 * Safe parsing: size and row caps, UTF-8 only, no NUL/control characters, a fixed column list
 * (unknown columns are refused), strict date format, every reference resolved inside the
 * workspace by exact name, and nothing in the file is ever evaluated. The same file (by hash) is
 * not imported twice. Each row is its own transaction: bad rows are reported by line, good rows
 * are kept.
 */
final class CsvImporter
{
    public const array COLUMNS = ['body', 'accounts', 'scheduled_at', 'title', 'link_url', 'campaign', 'category', 'hashtag_set', 'media'];

    public const string MODE_DRAFT = 'draft';

    public const string MODE_SCHEDULE = 'schedule';

    private const int MAX_ERRORS = 100;

    public function __construct(
        private readonly PostManager $posts,
        private readonly PostWorkflow $workflow,
        private readonly SocialSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public static function template(): string
    {
        return CsvSafe::render(self::COLUMNS, [
            ['Our spring sale starts tomorrow! #sale', 'Company Page;Company LinkedIn', '2026-11-02 09:30', 'Spring sale', 'https://example.com/sale', 'Spring campaign', 'Promotions', 'Sale tags', ''],
        ]);
    }

    /**
     * @throws ValidationException when the file as a whole is unusable
     */
    public function import(UploadedFile $file, string $mode, TenantMembership $by): Import
    {
        $contents = $this->read($file);
        $hash = hash('sha256', $contents);

        if (Import::query()->where('file_sha256', $hash)->exists()) {
            throw ValidationException::withMessages(['file' => __('This file has already been imported.')]);
        }

        $rows = $this->parse($contents);
        $lookups = $this->lookups();
        $timezone = $this->settings->timezone();

        $created = 0;
        $errors = [];

        $import = Import::query()->create([
            'file_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 190, ''),
            'file_sha256' => $hash,
            'mode' => $mode,
            'total_rows' => count($rows),
            'created_by' => $by->id,
        ]);

        foreach ($rows as $line => $row) {
            try {
                $this->createRow($row, $mode, $by, $import, $lookups, $timezone);
                $created++;
            } catch (ValidationException $exception) {
                $errors[] = ['line' => $line, 'message' => (string) collect($exception->errors())->flatten()->first()];
            } catch (Throwable) {
                $errors[] = ['line' => $line, 'message' => __('This row could not be imported.')];
            }
        }

        $import->update([
            'created_rows' => $created,
            'failed_rows' => count($rows) - $created,
            'errors' => array_slice($errors, 0, self::MAX_ERRORS),
        ]);
        $this->audit->record('social.import.completed', null, [
            'import_id' => $import->id, 'mode' => $mode, 'total' => count($rows), 'created' => $created,
        ], subjectLabel: $import->file_name);

        return $import;
    }

    /** @throws ValidationException */
    private function read(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $max = (int) config('social.import.max_bytes', 1048576);

        if ($path === false || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => __('The file could not be uploaded.')]);
        }

        if ((int) $file->getSize() > $max) {
            throw ValidationException::withMessages(['file' => __('The file is too large.')]);
        }

        $contents = (string) file_get_contents($path);

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => __('The file must be a UTF-8 text CSV.')]);
        }

        return str_replace(["\r\n", "\r"], "\n", $contents);
    }

    /**
     * @return array<int, array<string, string>> rows keyed by their line number in the file
     *
     * @throws ValidationException
     */
    private function parse(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw ValidationException::withMessages(['file' => __('The file could not be read.')]);
        }

        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '');

        if ($header === false || $header === [null]) {
            throw ValidationException::withMessages(['file' => __('The file is empty.')]);
        }

        $header = array_map(static fn (?string $name): string => mb_strtolower(trim((string) $name)), $header);

        if (count($header) !== count(array_unique($header)) || array_diff($header, self::COLUMNS) !== []) {
            throw ValidationException::withMessages(['file' => __('The header must use only these columns: :columns.', ['columns' => implode(', ', self::COLUMNS)])]);
        }

        if (! in_array('body', $header, true) || ! in_array('accounts', $header, true)) {
            throw ValidationException::withMessages(['file' => __('The file needs at least the body and accounts columns.')]);
        }

        $max = (int) config('social.import.max_rows', 500);
        $rows = [];
        $line = 1;

        while (($record = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;

            if ($record === [null]) {
                continue;
            }

            if (count($rows) >= $max) {
                fclose($stream);

                throw ValidationException::withMessages(['file' => __('A file can have at most :max rows.', ['max' => $max])]);
            }

            $row = [];

            foreach ($header as $index => $name) {
                $row[$name] = trim((string) ($record[$index] ?? ''));
            }

            $rows[$line] = $row;
        }

        fclose($stream);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no rows.')]);
        }

        return $rows;
    }

    /**
     * @return array{accounts: array<string, list<string>>, campaigns: array<string, string>, categories: array<string, string>, hashtags: array<string, string>, media: array<string, list<string>>}
     */
    private function lookups(): array
    {
        $accounts = [];

        foreach (SocialAccount::query()->where('status', '!=', AccountStatus::Disconnected->value)->get(['id', 'name', 'handle']) as $account) {
            foreach (array_filter([$account->name, $account->handle]) as $label) {
                $accounts[mb_strtolower($label)][] = $account->id;
            }
        }

        $media = [];

        foreach (Media::query()->get(['id', 'name']) as $item) {
            $media[mb_strtolower($item->name)][] = $item->id;
        }

        return [
            'accounts' => $accounts,
            'campaigns' => Campaign::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name): array => [mb_strtolower((string) $name) => (string) $id])->all(),
            'categories' => Category::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name): array => [mb_strtolower((string) $name) => (string) $id])->all(),
            'hashtags' => HashtagSet::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name): array => [mb_strtolower((string) $name) => (string) $id])->all(),
            'media' => $media,
        ];
    }

    /** @throws ValidationException */
    private function parseTime(string $value, string $timezone): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i', $value, $timezone);
            $errors = CarbonImmutable::getLastErrors();
        } catch (Throwable) {
            $parsed = null;
            $errors = null;
        }

        if (! $parsed instanceof CarbonImmutable || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ValidationException::withMessages(['scheduled_at' => __('Use the format YYYY-MM-DD HH:MM for scheduled_at.')]);
        }

        return $parsed->utc();
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $lookups
     *
     * @throws ValidationException
     */
    private function createRow(array $row, string $mode, TenantMembership $by, Import $import, array $lookups, string $timezone): void
    {
        foreach ($row as $value) {
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw ValidationException::withMessages(['row' => __('The row contains control characters.')]);
            }
        }

        $body = $row['body'] ?? '';

        if ($body === '' || mb_strlen($body) > 5000) {
            throw ValidationException::withMessages(['body' => __('The body is required and at most 5000 characters.')]);
        }

        $title = $row['title'] ?? '';
        $link = $row['link_url'] ?? '';

        if (mb_strlen($title) > 160) {
            throw ValidationException::withMessages(['title' => __('The title is at most 160 characters.')]);
        }

        if ($link !== '' && (mb_strlen($link) > 2048 || filter_var($link, FILTER_VALIDATE_URL) === false || ! preg_match('~^https?://~i', $link))) {
            throw ValidationException::withMessages(['link_url' => __('The link must be a valid http:// or https:// address.')]);
        }

        $accountIds = [];

        foreach (preg_split('/[;|]/', $row['accounts'] ?? '') ?: [] as $label) {
            $label = mb_strtolower(trim($label));

            if ($label === '') {
                continue;
            }

            $matches = $lookups['accounts'][$label] ?? [];

            if (count($matches) !== 1) {
                throw ValidationException::withMessages(['accounts' => count($matches) === 0
                    ? __('No connected account is named ":name".', ['name' => $label])
                    : __('More than one account is named ":name".', ['name' => $label])]);
            }

            $accountIds[$matches[0]] = $matches[0];
        }

        if ($accountIds === []) {
            throw ValidationException::withMessages(['accounts' => __('Name at least one connected account.')]);
        }

        $scheduledFor = null;

        if (($row['scheduled_at'] ?? '') !== '') {
            $scheduledFor = $this->parseTime($row['scheduled_at'], $timezone);
        }

        if ($mode === self::MODE_SCHEDULE && $scheduledFor === null) {
            throw ValidationException::withMessages(['scheduled_at' => __('A time is required to schedule a post.')]);
        }

        $resolve = function (string $column, string $lookup) use ($row, $lookups): ?string {
            $name = mb_strtolower($row[$column] ?? '');

            if ($name === '') {
                return null;
            }

            return $lookups[$lookup][$name]
                ?? throw ValidationException::withMessages([$column => __('":name" does not exist in this workspace.', ['name' => $row[$column]])]);
        };

        $mediaIds = [];

        foreach (preg_split('/[;|]/', $row['media'] ?? '') ?: [] as $name) {
            $name = mb_strtolower(trim($name));

            if ($name === '') {
                continue;
            }

            $matches = $lookups['media'][$name] ?? [];

            if (count($matches) !== 1) {
                throw ValidationException::withMessages(['media' => __('The media file ":name" was not found or is ambiguous.', ['name' => $name])]);
            }

            $mediaIds[] = $matches[0];
        }

        $campaignId = $resolve('campaign', 'campaigns');
        $categoryId = $resolve('category', 'categories');
        $hashtagSetId = $resolve('hashtag_set', 'hashtags');

        DB::transaction(function () use ($by, $import, $body, $title, $link, $scheduledFor, $accountIds, $mediaIds, $campaignId, $categoryId, $hashtagSetId, $mode): void {
            $post = $this->posts->create([
                'title' => $title === '' ? null : $title,
                'body' => $body,
                'link_url' => $link === '' ? null : $link,
                'scheduled_for' => $scheduledFor,
                'campaign_id' => $campaignId,
                'category_id' => $categoryId,
                'hashtag_set_id' => $hashtagSetId,
            ], array_map(static fn (string $id): array => ['account_id' => $id], array_values($accountIds)), $mediaIds, $by);

            $post->forceFill(['import_id' => $import->id])->save();

            if ($mode === self::MODE_SCHEDULE) {
                if ($this->settings->current()->require_approval) {
                    $this->workflow->submit($post, $by);
                } else {
                    $this->workflow->schedule($post, $scheduledFor);
                }
            }
        });
    }
}
