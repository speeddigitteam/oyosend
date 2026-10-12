<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Ai\Enums\MessageRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property MessageRole $role
 * @property string $content
 * @property list<array<string, mixed>>|null $tool_calls
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $prompt_version
 * @property string|null $provider
 * @property string|null $model
 * @property Carbon|null $created_at
 */
class Message extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'ai_messages';

    /** @var list<string> */
    protected $fillable = ['role', 'content', 'tool_calls', 'input_tokens', 'output_tokens', 'prompt_version', 'provider', 'model'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'tool_calls' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
