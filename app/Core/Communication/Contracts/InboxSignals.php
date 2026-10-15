<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

/**
 * Tells open unified-inbox pages that a conversation changed, so they refresh at once instead of at their next poll
 * (ADR 0035). An app that owns an inbox source calls it where a conversation changes: a message stored (inbound or a
 * reply), a delivery status, a read, an assignment, a close.
 *
 * It carries a signal, never content: the source key and the conversation id. The page reacts by asking the inbox's
 * own endpoints again, which decide what the member may see. Calling it inside a transaction is safe: nothing leaves
 * before the outermost transaction commits, and nothing at all when it rolls back. It never throws and never slows the
 * write down; with realtime switched off it does nothing.
 *
 * The `communication` module binds the broadcasting implementation; without it core binds a no-op.
 */
interface InboxSignals
{
    /**
     * @param  string  $source  The inbox source key (`InboxSourceDefinition::$key`), e.g. `whatsapp_marketing.conversations`.
     * @param  string  $id  The conversation id the inbox lists (the id of `InboxItem`).
     */
    public function changed(string $source, string $id): void;
}
