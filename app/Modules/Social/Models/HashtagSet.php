<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property list<string> $tags Each tag starts with `#`.
 */
class HashtagSet extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_hashtag_sets';

    /** @var list<string> */
    protected $fillable = ['name', 'tags'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'social.hashtag_set.'.$verb;
    }
}
