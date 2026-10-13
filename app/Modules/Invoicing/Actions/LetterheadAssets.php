<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\Storage\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The workspace's letterhead ("pad"): a header band and a footer band, each a private file on the
 * tenant disk. The `letterhead` document template lays an invoice between them so it prints on the
 * workspace's own stationery. SVG is refused on purpose: it can carry script.
 */
final class LetterheadAssets
{
    /** The two bands and the settings column that stores each one's path. */
    private const array SLOTS = [
        'header' => 'letterhead_header_path',
        'footer' => 'letterhead_footer_path',
    ];

    public function __construct(
        private readonly TenantStorage $storage,
        private readonly InvoicingSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<string> */
    public function slots(): array
    {
        return array_keys(self::SLOTS);
    }

    public function isSlot(string $slot): bool
    {
        return array_key_exists($slot, self::SLOTS);
    }

    /**
     * Validation rules for an uploaded band.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=3000,max_height=3000'];
    }

    public function replace(string $slot, UploadedFile $file): void
    {
        $column = $this->column($slot);
        $settings = $this->settings->current();
        $previous = $settings->{$column};
        $path = $this->storage->putFile('invoicing/letterhead', $file);

        DB::transaction(function () use ($settings, $column, $path, $slot): void {
            $settings->update([$column => $path]);
            $this->audit->record('invoicing.letterhead.updated', $settings, ['slot' => $slot]);
        });

        if (is_string($previous) && $previous !== $path) {
            $this->storage->delete($previous);
        }
    }

    public function remove(string $slot): void
    {
        $column = $this->column($slot);
        $settings = $this->settings->current();
        $previous = $settings->{$column};

        if (! is_string($previous)) {
            return;
        }

        DB::transaction(function () use ($settings, $column, $slot): void {
            $settings->update([$column => null]);
            $this->audit->record('invoicing.letterhead.removed', $settings, ['slot' => $slot]);
        });

        $this->storage->delete($previous);
    }

    private function column(string $slot): string
    {
        return self::SLOTS[$slot] ?? throw new \InvalidArgumentException("Unknown letterhead slot [{$slot}].");
    }
}
