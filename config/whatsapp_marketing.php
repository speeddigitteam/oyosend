<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Business (module `whatsapp_marketing`)
|--------------------------------------------------------------------------
|
| Bounds for the campaign sender. There is no credential here: the connection
| to the WhatsApp Cloud API is a channel of the communication app
| (config/communication.php, docs/WHATSAPP.md).
|
*/

return [

    // Recipients handed to the outbox per chunk job. The chunk job re-dispatches itself until none are pending.
    'chunk_size' => 50,

    // Contacts read per page when a campaign's recipient list is frozen.
    'snapshot_page' => 500,

    // The most contacts one campaign sends to, and one list holds.
    'max_recipients' => 10000,
    'max_list_members' => 10000,

];
