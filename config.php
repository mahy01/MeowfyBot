<?php

return [
    'bot_token' => getenv('BOT_TOKEN') ?: '',
    'admin_id'  => (int) (getenv('ADMIN_ID') ?: 0),
];
