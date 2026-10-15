<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Models;

use App\Modules\Social\Comments\Actions\CommentSources;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The one connected account that may receive a Page's comment events. Platform-wide on purpose (no tenant
 * scope; docs/DATABASE.md section 2): the unique (platform, external_id) index is what stops a second
 * workspace from feeding comments from a Page another workspace claimed. Rows are created and removed only
 * by {@see CommentSources}, and read with an explicit account or page.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string $platform
 * @property string $external_id
 * @property string|null $instagram_id The Instagram professional account linked to the Page, while its direct
 *                                     messages are captured (ADR 0031); unique, so it feeds one workspace only.
 */
class PageClaim extends Model
{
    use HasUlids;

    protected $table = 'social_comment_page_claims';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'account_id', 'platform', 'external_id', 'instagram_id'];
}
