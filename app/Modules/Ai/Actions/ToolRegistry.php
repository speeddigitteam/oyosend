<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Ai\Contracts\AiTool;
use App\Core\Ai\Contracts\ProposalTool;
use App\Core\Identity\Models\User;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The strict tool registry: only tagged `AiTool` classes exist, a tool is offered only for a member who
 * holds its permission in an entitled module and only when its data scope is enabled in the workspace.
 */
final class ToolRegistry
{
    /** Names and permissions may not contain a money or access-control segment: no tool moves money or changes who can do what. */
    private const string FORBIDDEN = '/(^|[._])(payments?|payouts?|refunds?|payroll|billing|roles?|permissions?|invitations?|members?|transfers?|credit_notes?|salary|salaries)([._]|$)/';

    /** @var array<string, AiTool>|null */
    private ?array $tools = null;

    public function __construct(
        private readonly Container $container,
        private readonly AccessChecker $access,
        private readonly AiSettings $settings,
    ) {}

    /**
     * Every registered tool by name (no permission filtering).
     *
     * @return array<string, AiTool>
     */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $tools = [];

        foreach ($this->container->tagged(AiTool::TAG) as $tool) {
            if (! $tool instanceof AiTool) {
                continue;
            }

            $name = $tool::name();

            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1) {
                throw new LogicException("Invalid AI tool name [{$name}].");
            }

            if (isset($tools[$name])) {
                throw new LogicException("Duplicate AI tool [{$name}].");
            }

            if (preg_match(self::FORBIDDEN, $name.'.'.$tool::permission()) === 1) {
                throw new LogicException("AI tool [{$name}] touches money or access control and cannot be registered.");
            }

            $tools[$name] = $tool;
        }

        return $this->tools = $tools;
    }

    /**
     * Tools this member may use right now.
     *
     * @return array<string, AiTool>
     */
    public function availableFor(User $user): array
    {
        $scopes = $this->settings->enabledScopes();

        return array_filter(
            $this->all(),
            fn (AiTool $tool): bool => in_array($tool::dataScope(), $scopes, true)
                && $this->access->allows($user, $tool::permission()),
        );
    }

    public function find(string $name, User $user): ?AiTool
    {
        return $this->availableFor($user)[$name] ?? null;
    }

    public function findProposalTool(string $name, User $user): ?ProposalTool
    {
        $tool = $this->find($name, $user);

        return $tool instanceof ProposalTool ? $tool : null;
    }

    /**
     * Provider-facing tool definitions for the member.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitionsFor(User $user): array
    {
        $definitions = [];

        foreach ($this->availableFor($user) as $name => $tool) {
            $definitions[] = [
                'name' => $name,
                'description' => $tool::description(),
                'input_schema' => $tool::inputSchema(),
            ];
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(array_unique(array_map(
            static fn (AiTool $tool): string => $tool::dataScope(),
            $this->all(),
        )));
    }
}
