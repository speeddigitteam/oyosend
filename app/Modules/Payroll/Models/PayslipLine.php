<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\LineKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a payslip. Lines are written with their draft payslip and are never updated; they can
 * be deleted only while the payslip is still a draft.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payslip_id
 * @property LineKind $kind
 * @property string $code
 * @property string $name
 * @property string $source
 * @property string|null $source_id
 * @property int $amount
 * @property bool $is_taxable
 * @property int $sort_order
 * @property array<string, mixed>|null $detail
 */
class PayslipLine extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $table = 'payroll_payslip_lines';

    /** @var list<string> */
    protected $fillable = ['payslip_id', 'kind', 'code', 'name', 'source', 'source_id', 'amount', 'is_taxable', 'sort_order', 'detail'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['kind' => LineKind::class, 'amount' => 'integer', 'is_taxable' => 'boolean', 'sort_order' => 'integer', 'detail' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Payslip lines are immutable.');
        });
        static::deleting(static function (PayslipLine $line): void {
            $locked = Payslip::query()->whereKey($line->payslip_id)->whereNotNull('locked_at')->exists();

            if ($locked) {
                throw new LogicException('A line of an approved payslip cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Payslip, $this> */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
