<?php

return [
    'meta' => ['token' => env('WHATSAPP_META_TOKEN'), 'phone_id' => env('WHATSAPP_META_PHONE_ID'), 'version' => env('WHATSAPP_META_VERSION'), 'template' => env('WHATSAPP_META_TEMPLATE'), 'language' => env('WHATSAPP_META_LANGUAGE', 'pt_BR')],
    'evolution' => ['url' => env('EVOLUTION_URL'), 'token' => env('EVOLUTION_TOKEN'), 'instance' => env('EVOLUTION_INSTANCE')],
    'google' => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'calendar_id' => env('GOOGLE_CALENDAR_ID')],
];
