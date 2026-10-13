<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Ai\ToolContext;
use App\Core\Identity\Models\User;
use App\Modules\Ai\Enums\AiState;
use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Support\AiRefusal;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\DataBlock;
use App\Modules\Ai\Support\SystemPrompt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * AI-assisted drafting: summarise a record, draft a reply, rewrite a text. Text only, no tools for the
 * model. A record is loaded through the same tool layer the assistant uses, as the signed-in member, so a
 * draft can never contain what the member may not read.
 */
final class DraftService
{
    public const string SUMMARIZE = 'summarize_record';

    public const string REPLY = 'draft_reply';

    public const string REWRITE = 'rewrite';

    /**
     * Record type => read tool that loads it. A module's tool is offered only when the workspace is entitled to
     * the module and enabled the tool's data scope; otherwise the draft is "not found" like an invisible record.
     */
    private const array RECORD_TOOLS = [
        'contact' => 'contacts_get',
        'company' => 'companies_get',
        'whatsapp_conversation' => 'whatsapp_conversation_get',
    ];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly ToolRunner $runner,
        private readonly ToolRegistry $tools,
    ) {}

    /**
     * @return list<string>
     */
    public static function recordTypes(): array
    {
        return array_keys(self::RECORD_TOOLS);
    }

    /**
     * The record types this member can draft from now: the tool's app is entitled, its data scope is enabled and
     * the member holds its permission.
     *
     * @return list<string>
     */
    public function recordTypesFor(User $user): array
    {
        $available = $this->tools->availableFor($user);

        return array_keys(array_filter(self::RECORD_TOOLS, static fn (string $tool): bool => isset($available[$tool])));
    }

    /**
     * @param  array{kind: string, record_type?: string|null, record_id?: string|null, instruction?: string|null, tone?: string|null, text?: string|null}  $input
     * @return array{text: string, ai_generated: bool, label: string, prompt_version: string, usage: array{input_tokens: int, output_tokens: int}}
     *
     * @throws AiRefusal
     * @throws NotFoundHttpException
     */
    public function generate(ToolContext $context, array $input): array
    {
        $state = $this->gateway->state(Feature::Drafting);

        if ($state !== AiState::Ready) {
            throw new AiRefusal($state);
        }

        $tone = (string) ($input['tone'] ?? 'neutral');
        $instruction = trim((string) ($input['instruction'] ?? ''));
        $kind = $input['kind'];

        $data = '';
        $task = '';

        if ($kind === self::REWRITE) {
            $data = DataBlock::text((string) ($input['text'] ?? ''));
            $task = "Rewrite the text below in a {$tone} tone. Keep its meaning and facts.";
        } else {
            $type = (string) ($input['record_type'] ?? '');
            $id = (string) ($input['record_id'] ?? '');
            $tool = self::RECORD_TOOLS[$type] ?? null;

            if ($tool === null) {
                throw new NotFoundHttpException;
            }

            $run = $this->runner->read($tool, ['id' => $id], $context);

            // Not visible to the member (or not there) is the same answer: 404.
            if ($run->status !== 'ok') {
                throw new NotFoundHttpException;
            }

            $data = DataBlock::wrapJson($type, $id, $run->json);
            $task = match (true) {
                $kind === self::SUMMARIZE && $type === 'whatsapp_conversation' => 'Summarize the WhatsApp conversation below in at most five short bullet points for a colleague: what the customer wants, what was answered and what is still open.',
                $kind === self::SUMMARIZE => 'Summarize the record below in at most five short bullet points for a colleague: who it is, the relationship status and anything that needs follow-up.',
                $type === 'whatsapp_conversation' => "Draft the next {$tone} WhatsApp reply from the business to the customer in the conversation below. Answer the customer's latest message, in the language the customer wrote in, as plain text of at most 600 characters. Do not promise anything the conversation does not support.",
                default => "Draft a {$tone} reply message to the person or company described by the record below.",
            };
        }

        $prompt = $task;

        if ($instruction !== '') {
            $prompt .= "\nExtra instruction from the member: ".$instruction;
        }

        $prompt .= "\n\n".$data;

        $response = $this->gateway->complete(
            Feature::Drafting,
            new CompletionRequest(SystemPrompt::drafting(), [['role' => 'user', 'content' => $prompt]], [], (int) config('ai.limits.max_output_tokens', 1024)),
            'ai:draft:'.Str::ulid(),
            $context->membership->id,
        );

        $text = $response->text();

        if ($text === '') {
            $text = __('The assistant could not produce a draft. Try rephrasing the request.');
        }

        return [
            'text' => $text,
            'ai_generated' => true,
            'label' => __('AI-generated draft. Review before use.'),
            'prompt_version' => SystemPrompt::VERSION,
            'usage' => ['input_tokens' => $response->inputTokens, 'output_tokens' => $response->outputTokens],
        ];
    }
}
