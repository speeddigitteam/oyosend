<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Enums\StatusSource;
use App\Modules\WhatsappMarketing\Enums\TemplateCategory;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Models\Concerns\AuditsConfig;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A message template as recorded from the provider or entered by an administrator. Templates are created and approved on Meta's side; this row only mirrors them.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $language
 * @property TemplateCategory $category
 * @property TemplateStatus $status
 * @property array<int, array<string, mixed>> $components
 * @property string $body_text
 * @property int $variable_count
 * @property StatusSource $status_source
 * @property string|null $status_reason
 * @property string|null $provider_template_id
 * @property CarbonInterface|null $status_changed_at
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Template extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_templates';

    /** @var list<string> */
    protected $fillable = ['name', 'language', 'category', 'status', 'components', 'body_text', 'variable_count', 'status_source', 'status_reason', 'provider_template_id', 'status_changed_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => TemplateCategory::class,
            'status' => TemplateStatus::class,
            'components' => 'array',
            'variable_count' => 'integer',
            'status_source' => StatusSource::class,
            'status_changed_at' => 'datetime',
        ];
    }
}
