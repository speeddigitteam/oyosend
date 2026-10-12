<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Ai\Contracts\AiTool;
use App\Core\Ai\Contracts\ProposalTool;
use App\Core\Ai\ToolContext;
use App\Core\Audit\AuditLogger;
use App\Modules\Ai\Models\Conversation;
use App\Modules\Ai\Models\Proposal;
use App\Modules\Ai\Support\DataBlock;
use App\Modules\Ai\Support\OutputLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Executes one tool as the signed-in member. Everything a tool returns is size-capped and wrapped as
 * untrusted data; every call is audited with the tool name and record ids only.
 */
final class ToolRunner
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ProposalManager $proposals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Run a tool the model asked for. An unknown, unavailable or not permitted tool is refused here, whatever
     * the model was told it could call.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(string $name, array $input, ToolContext $context, ?Conversation $conversation = null): ToolRun
    {
        $tool = $this->tools->find($name, $context->user);

        if ($tool === null) {
            return $this->finish($name, 'denied', ['error' => 'This tool is not available to the member.'], [], $context, $conversation, failed: true);
        }

        // A proposing tool needs a conversation to anchor the confirmation card in.
        if ($tool instanceof ProposalTool && $conversation === null) {
            return $this->finish($name, 'denied', ['error' => 'Changes can only be proposed inside the assistant chat.'], [], $context, null, failed: true);
        }

        return $this->execute($tool, $input, $context, $conversation);
    }

    /**
     * Run a read tool directly for a drafting endpoint (never a proposing tool).
     *
     * @param  array<string, mixed>  $input
     */
    public function read(string $name, array $input, ToolContext $context): ToolRun
    {
        $tool = $this->tools->find($name, $context->user);

        if ($tool === null || $tool instanceof ProposalTool) {
            return $this->finish($name, 'denied', ['error' => 'This tool is not available to the member.'], [], $context, null, failed: true);
        }

        return $this->execute($tool, $input, $context, null);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function execute(AiTool $tool, array $input, ToolContext $context, ?Conversation $conversation): ToolRun
    {
        $name = $tool::name();

        try {
            $output = $tool->execute($context, $input);
        } catch (ValidationException $exception) {
            return $this->finish($name, 'invalid', ['error' => 'Invalid input.', 'fields' => array_keys($exception->errors())], [], $context, $conversation, failed: true);
        } catch (ModelNotFoundException) {
            return $this->finish($name, 'not_found', ['error' => 'No such record, or it is not visible to the member.'], [], $context, $conversation, failed: true);
        } catch (HttpException $exception) {
            $status = $exception->getStatusCode() === 403 ? 'denied' : 'not_found';

            return $this->finish($name, $status, ['error' => 'No such record, or it is not visible to the member.'], [], $context, $conversation, failed: true);
        }

        if ($output->proposal !== null && $tool instanceof ProposalTool && $conversation !== null) {
            $proposal = $this->proposals->create($conversation, $context, $name, $output->proposal);

            return $this->finish($name, 'proposal_created', [
                'status' => 'proposal_created',
                'proposal_id' => $proposal->id,
                'summary' => $output->proposal->summary,
                'note' => 'Awaiting confirmation by the member. Nothing has been changed yet.',
            ], $output->proposal->recordIds, $context, $conversation, $proposal);
        }

        return $this->finish($name, 'ok', $output->data, $output->recordIds, $context, $conversation);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $recordIds
     */
    private function finish(
        string $name,
        string $status,
        array $data,
        array $recordIds,
        ToolContext $context,
        ?Conversation $conversation,
        ?Proposal $proposal = null,
        bool $failed = false,
    ): ToolRun {
        $this->audit->record('ai.tool.executed', null, array_filter([
            'tool' => $name,
            'status' => $status,
            'record_ids' => $recordIds,
            'conversation_id' => $conversation?->id,
            'proposal_id' => $proposal?->id,
        ], static fn ($value): bool => $value !== null), $context->user);

        $json = OutputLimiter::json(
            $data,
            (int) config('ai.limits.tool_result_chars', 6000),
            (int) config('ai.limits.field_chars', 1500),
        );

        return new ToolRun($name, $status, DataBlock::toolResult($json), $recordIds, $proposal, $failed);
    }
}
