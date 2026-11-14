<?php
/**
 * Test Script for Meowfy Bot Connection
 */

$BOT_TOKEN = getenv('BOT_TOKEN') ?: '';

if ($BOT_TOKEN === '') {
    http_response_code(500);
    exit('Token missing');
}

function sendMessage(int|string $chatId, string $text): ?array {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'text' => $text
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (is_array($update) && isset($update['message'])) {
    $msg = $update['message'];
    $chatId = $msg['chat']['id'] ?? 0;
    $text = $msg['text'] ?? '';

    // اگر کاربر دستور /start رو فرستاد، فقط یه سلام بده
    if ($chatId !== 0 && str_starts_with(trim($text), '/start')) {
        sendMessage($chatId, 'سلام! گیت‌هاب و ورسل با موفقیت به هم وصلن 🚀 ربات سالم و سرپائه!');
    }
}

http_response_code(200);
echo 'OK';
