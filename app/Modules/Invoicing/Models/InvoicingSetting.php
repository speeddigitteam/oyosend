<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Invoicing\Enums\DocumentTemplate;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per workspace.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_prefix
 * @property string $credit_note_prefix
 * @property int $number_padding
 * @property bool $fiscal_year_numbering
 * @property int $fiscal_year_start_month
 * @property int $default_due_days
 * @property string|null $default_terms
 * @property string|null $default_notes
 * @property bool $reminders_enabled
 * @property int $reminder_before_days
 * @property int $reminder_overdue_every_days
 * @property bool $invoice_accepted_quotes
 * @property DocumentTemplate $document_template
 * @property string|null $letterhead_header_path
 * @property string|null $letterhead_footer_path
 */
class InvoicingSetting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'invoicing_settings';

    /** @var list<string> */
    protected $fillable = [
        'invoice_prefix', 'credit_note_prefix', 'number_padding', 'fiscal_year_numbering',
        'fiscal_year_start_month', 'default_due_days', 'default_terms', 'default_notes', 'reminders_enabled',
        'reminder_before_days', 'reminder_overdue_every_days', 'invoice_accepted_quotes', 'document_template',
        'letterhead_header_path', 'letterhead_footer_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number_padding' => 'integer',
            'fiscal_year_numbering' => 'boolean',
            'fiscal_year_start_month' => 'integer',
            'default_due_days' => 'integer',
            'reminders_enabled' => 'boolean',
            'reminder_before_days' => 'integer',
            'reminder_overdue_every_days' => 'integer',
            'invoice_accepted_quotes' => 'boolean',
            'document_template' => DocumentTemplate::class,
        ];
    }

    public function auditLabel(): string
    {
        return 'Invoicing settings';
    }

    public function auditEventName(string $verb): string
    {
        return 'invoicing.settings.'.$verb;
    }
}
