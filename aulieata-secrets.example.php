<?php
// Copy to aulieata-secrets.php OUTSIDE public/. Never commit the populated file.
return [
    'base_url' => 'https://your-domain.example',
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'itu',
    'db_user' => '',
    'db_pass' => '',
    'gemini_key' => '',
    'gemini_model' => 'gemini-3.8-flash', // Beginner mentor; optional GEMINI_MODEL environment override when empty.
    'telegram_token' => '', // Optional legacy bot; use per-business connections in the app.
    'telegram_username' => '',
    'owner_phone' => '', // Optional legacy routing; never put a real phone in this example.
    'webhook_secret' => '', // Generate locally, e.g. bin2hex(random_bytes(32)).
    'encryption_key' => '', // Generate independently. Keep stable after bots are connected.
];
