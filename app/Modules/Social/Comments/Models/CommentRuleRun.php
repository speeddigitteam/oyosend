<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One automatic action of one rule on one comment. The unique index (rule, comment, action) is what makes a
 * re-delivered comment safe, and the table is the audit of everything a rule did.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rule_id
 * @property string $comment_id
 * @property string $account_id
 * @property string|null $author_external_id
 * @property string $action
 * @property string $outcome
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class CommentRuleRun extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Written before the platform call: a worker that dies mid-call leaves it, so a public reply is at-most-once. */
    public const string CLAIMED = 'claimed';

    public const string DONE = 'done';

    public const string SKIPPED = 'skipped';

    public const string FAILED = 'failed';

    protected $table = 'social_comment_rule_runs';

    /** @var list<string> */
    protected $fillable = ['rule_id', 'comment_id', 'account_id', 'author_external_id', 'action', 'outcome', 'reason'];
}
