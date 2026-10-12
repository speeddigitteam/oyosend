<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved, declarative filter over one list's members. Rules are data (field, operator, value) from fixed
 * allow-lists; there is no expression language and nothing is evaluated.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $list_id
 * @property string $name
 * @property string $match
 * @property list<array{field: string, operator: string, value: string|null}> $rules
 */
class Segment extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_segments';

    /** @var list<string> */
    protected $fillable = ['list_id', 'name', 'match', 'rules'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    /** @return BelongsTo<MailingList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(MailingList::class, 'list_id');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'email_marketing.segment.'.$verb;
    }
}
