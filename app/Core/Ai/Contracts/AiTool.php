<?php

declare(strict_types=1);

namespace App\Core\Ai\Contracts;

use App\Core\Ai\ToolContext;
use App\Core\Ai\ToolOutput;

/**
 * One thing the AI assistant may ask for: a thin, READ-ONLY wrapper over a documented contract.
 *
 * A module registers a tool by tagging the class in the container
 * (`$this->app->tag(MyTool::class, AiTool::TAG)`); the `ai` module offers it to the model only when the
 * tenant is entitled to the permission's module, the tool's data scope is enabled in the workspace's AI
 * settings and the signed-in member holds {@see permission()}. A tool still narrows what it returns to the
 * member's record scope (`RecordAccess`): the assistant can never see more than the person.
 *
 * A tool never changes data and never moves money or changes permissions. Something that should change
 * data is a {@see ProposalTool}: it only describes the change, a human confirms it.
 */
interface AiTool
{
    /** Container tag under which tools are registered. */
    public const string TAG = 'ai.tools';

    /** Provider-facing name: letters, digits and underscore only, e.g. `contacts_search`. Unique. */
    public static function name(): string;

    /** The workspace data scope that must be enabled in the AI settings, e.g. `contacts`. */
    public static function dataScope(): string;

    /** Permission the member must hold (effective: entitlement included). */
    public static function permission(): string;

    /** What the tool does, written for the model. */
    public static function description(): string;

    /**
     * JSON Schema of the input (`type: object`).
     *
     * @return array<string, mixed>
     */
    public static function inputSchema(): array;

    /**
     * Run as the member. Input is validated against the schema loosely by the runner; the tool validates
     * what it uses. Returned text fields are untrusted data and are passed to the model as such.
     *
     * @param  array<string, mixed>  $input
     */
    public function execute(ToolContext $context, array $input): ToolOutput;
}
