<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Text with `{{ placeholder }}` slots from a fixed allow-list. Never evaluated as code.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $slug
 * @property string $name
 * @property Channel $channel
 * @property string $locale
 * @property MessagePurpose $purpose
 * @property string|null $subject
 * @property string $body
 * @property bool $is_active
 */
class MessageTemplate extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_templates';

    /** @var list<string> */
    protected $fillable = ['slug', 'name', 'channel', 'locale', 'purpose', 'subject', 'body', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'purpose' => MessagePurpose::class,
            'is_active' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'communication.template.'.$verb;
    }
}
