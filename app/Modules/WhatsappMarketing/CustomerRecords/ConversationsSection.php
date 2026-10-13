<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\CustomerRecords;

use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Contacts\Records\CustomerItem;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Contacts\Records\CustomerSection;
use App\Core\Contacts\Records\CustomerSectionDefinition;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Support\Permissions;

/** The contact's WhatsApp conversations with their last message, narrowed as in the inbox. */
final class ConversationsSection implements CustomerRecordProvider
{
    public function __construct(private readonly ConversationAccess $access) {}

    public function definition(): CustomerSectionDefinition
    {
        return new CustomerSectionDefinition(key: 'whatsapp_marketing.conversations', module: 'whatsapp_marketing', label: 'WhatsApp conversations', permission: Permissions::CONVERSATIONS_VIEW, order: 40);
    }

    public function forContact(CustomerQuery $query): CustomerSection
    {
        $conversations = $this->access->constrain(Conversation::query(), $query->user, Permissions::CONVERSATIONS_VIEW)->where('contact_id', $query->contact->id);
        $total = (clone $conversations)->count();

        return new CustomerSection(
            items: $conversations->orderByDesc('last_message_at')->latest()->limit($query->limit)->get()->map(fn (Conversation $conversation): CustomerItem => new CustomerItem(
                id: $conversation->id,
                title: $conversation->last_message_preview ?? $conversation->phone,
                status: $conversation->status === ConversationStatus::Open ? __('Open') : __('Closed'),
                tone: $conversation->status === ConversationStatus::Open ? 'ok' : 'neutral',
                detail: $conversation->unread_count > 0 ? __(':count unread', ['count' => $conversation->unread_count]) : $conversation->phone,
                at: $conversation->last_message_at ?? $conversation->created_at,
                url: route('whatsapp_marketing.inbox.show', $conversation),
            ))->values()->all(),
            total: $total,
        );
    }
}
