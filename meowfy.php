<?php
/**
 * Meowfy - Telegram audio bot
 * PHP port of the original Python bot.
 *
 * Requirements:
 * - PHP 8.x with cURL, JSON
 * - FFmpeg + FFprobe available in PATH
 * - BOT_TOKEN and ADMIN_ID environment variables
 * - writable directory for required_channel.json and user_states.json
 */

const CHANNEL_FILE = __DIR__ . '/required_channel.json';
const STATE_FILE = __DIR__ . '/user_states.json';
const CANCEL_BUTTON = 'لغو';

$BOT_TOKEN = getenv('BOT_TOKEN') ?: '';
$ADMIN_ID = (int)(getenv('ADMIN_ID') ?: 0);

if ($BOT_TOKEN === '') {
    fwrite(STDERR, "❌ BOT_TOKEN پیدا نشد!\n");
    exit(1);
}
if ($ADMIN_ID === 0) {
    fwrite(STDERR, "⚠️ ADMIN_ID تنظیم نشده!\n");
}

function tg(string $method, array $params = []): ?array {
    global $BOT_TOKEN;

    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        error_log('Telegram cURL error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $data = json_decode($response, true);
    if (!is_array($data)) {
        error_log('Telegram invalid response: ' . $response);
        return null;
    }
    if (!($data['ok'] ?? false)) {
        error_log('Telegram API error: ' . $response);
    }
    return $data;
}

function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): ?array {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }
    return tg('sendMessage', $params);
}

function answerCallback(string $callbackId, string $text = '', bool $alert = false): ?array {
    return tg('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert ? 'true' : 'false',
    ]);
}

function editMessageText(int|string $chatId, int $messageId, string $text): ?array {
    return tg('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
    ]);
}

function sendAudio(int|string $chatId, string $path, string $title = 'Converted', string $performer = '@music_cutter_bot'): ?array {
    if (!is_file($path)) return null;
    $params = [
        'chat_id' => $chatId,
        'audio' => new CURLFile($path, 'audio/mpeg', basename($path)),
        'title' => $title,
        'performer' => $performer,
    ];
    return tg('sendAudio', $params);
}

function sendVoice(int|string $chatId, string $path): ?array {
    if (!is_file($path)) return null;
    return tg('sendVoice', [
        'chat_id' => $chatId,
        'voice' => new CURLFile($path, 'audio/ogg', basename($path)),
    ]);
}

function loadJson(string $file, mixed $default = null): mixed {
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return $default;
    $data = json_decode($raw, true);
    return $data === null && json_last_error() !== JSON_ERROR_NONE ? $default : $data;
}

function saveJson(string $file, mixed $data): bool {
    return @file_put_contents(
        $file,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    ) !== false;
}

function loadRequiredChannel(): ?string {
    $data = loadJson(CHANNEL_FILE, []);
    return is_array($data) ? ($data['channel'] ?? null) : null;
}

function saveRequiredChannel(string $channel): void {
    saveJson(CHANNEL_FILE, ['channel' => $channel]);
}

function removeRequiredChannel(): void {
    if (is_file(CHANNEL_FILE)) @unlink(CHANNEL_FILE);
}

function loadStates(): array {
    $data = loadJson(STATE_FILE, []);
    return is_array($data) ? $data : [];
}

function saveStates(array $states): void {
    saveJson(STATE_FILE, $states);
}

function getUserState(int $userId): array {
    $states = loadStates();
    return is_array($states[(string)$userId] ?? null) ? $states[(string)$userId] : [];
}

function setUserState(int $userId, array $state): void {
    $states = loadStates();
    $states[(string)$userId] = $state;
    saveStates($states);
}

function clearUserState(int $userId, bool $deleteInputFile = true): void {
    $states = loadStates();
    $key = (string)$userId;
    $state = $states[$key] ?? [];

    if ($deleteInputFile && !empty($state['input_file']) && is_file($state['input_file'])) {
        @unlink($state['input_file']);
    }

    unset($states[$key]);
    saveStates($states);
}

function normalizeDigits(string $text): string {
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    return trim(str_replace(array_merge($persian, $arabic), array_merge($english, $english), $text));
}

function timeToSeconds(string $timeText): ?int {
    $timeText = normalizeDigits($timeText);
    if ($timeText === '') return null;
    if (ctype_digit($timeText)) return (int)$timeText;

    $parts = explode(':', $timeText);
    if (!in_array(count($parts), [2, 3], true)) return null;
    foreach ($parts as $part) {
        if ($part === '' || !ctype_digit($part)) return null;
    }
    $parts = array_map('intval', $parts);

    if (count($parts) === 2) {
        [$minutes, $seconds] = $parts;
        if ($seconds >= 60) return null;
        return $minutes * 60 + $seconds;
    }

    [$hours, $minutes, $seconds] = $parts;
    if ($minutes >= 60 || $seconds >= 60) return null;
    return $hours * 3600 + $minutes * 60 + $seconds;
}

function secondsToTime(float $seconds): string {
    $seconds = (int)$seconds;
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    return $hours > 0
        ? sprintf('%02d:%02d:%02d', $hours, $minutes, $secs)
        : sprintf('%02d:%02d', $minutes, $secs);
}

function getAudioDuration(string $filePath): ?float {
    $cmd = 'ffprobe -v error -show_entries format=duration ' .
           '-of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($filePath) . ' 2>/dev/null';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    if ($code !== 0 || empty($output)) return null;
    $value = trim(implode("\n", $output));
    return is_numeric($value) ? (float)$value : null;
}

function cancelKeyboard(): array {
    return [
        'keyboard' => [[CANCEL_BUTTON]],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'input_field_placeholder' => 'زمان را وارد کن...',
    ];
}

function removeKeyboard(): array {
    return ['remove_keyboard' => true];
}

function inlineJoinKeyboard(string $channel): array {
    $username = ltrim($channel, '@');
    return [
        'inline_keyboard' => [
            [[
                'text' => 'عضویت در کانال 🐾',
                'url' => "https://t.me/{$username}",
            ]],
            [[
                'text' => 'عضو شدم ✅',
                'callback_data' => 'check_join',
            ]],
        ],
    ];
}

function sendError(int|string $chatId): void {
    sendMessage($chatId, "مشکلی پیش اومده 😿\nدوباره امتحان کن.");
}

function isMember(int $userId): bool {
    $channel = loadRequiredChannel();
    if (!$channel) return true;

    $result = tg('getChatMember', [
        'chat_id' => $channel,
        'user_id' => $userId,
    ]);
    if (!($result['ok'] ?? false)) return false;

    $status = $result['result']['status'] ?? '';
    return in_array($status, ['member', 'administrator', 'creator'], true);
}

function checkMembership(array $update, int $chatId, int $userId): bool {
    if (isMember($userId)) return true;

    $channel = loadRequiredChannel();
    if (!$channel) return true;

    $text = "اول عضو کانال اسپانسر شو 😺🐾\n\nبعد «عضو شدم ✅» رو بزن تا شروع کنیم 🎧";
    sendMessage($chatId, $text, inlineJoinKeyboard($channel));
    return false;
}

function downloadTelegramFile(string $fileId, string $destination): bool {
    $result = tg('getFile', ['file_id' => $fileId]);
    if (!($result['ok'] ?? false)) return false;
    $filePath = $result['result']['file_path'] ?? '';
    if ($filePath === '') return false;

    global $BOT_TOKEN;
    $url = "https://api.telegram.org/file/bot{$BOT_TOKEN}/{$filePath}";
    $in = @fopen($url, 'rb');
    if (!$in) return false;
    $out = @fopen($destination, 'wb');
    if (!$out) {
        fclose($in);
        return false;
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return is_file($destination) && filesize($destination) > 0;
}

function tempFile(string $extension): string {
    $path = tempnam(sys_get_temp_dir(), 'meowfy_');
    if ($path === false) throw new RuntimeException('Unable to create temp file');
    $new = $path . $extension;
    @rename($path, $new);
    return $new;
}

function sendWelcome(int $chatId): void {
    sendMessage($chatId, "سلااامم 😺\n\nمن میوفای‌ام؛ دستیار میوزیکی تو 🎧\n\n🎙 ویس بدی، میوزیک تحویل می‌گیری\n🎵 موزیک بدی، هرجاشو بخوای می‌بُرم ✂️\n\nفایلتو بفرست!");
}

function startCommand(int $chatId, int $userId): void {
    clearUserState($userId);
    if (!checkMembership([], $chatId, $userId)) return;
    sendWelcome($chatId);
}

function helpCommand(int $chatId): void {
    $text = "میووو 😺🐾\n\nمن میوفای‌ام؛ گربه کوچولوی موزیکیت 🎧\n\n🎙 ویس بده\nویستو به موزیک قابل دانلود تبدیل می‌کنم.\n\n🎵 موزیک بده\nهر قسمتی از آهنگ رو که بخوای برات می‌بُرم ✂️\n\n📌 محدودیت‌های من:\n• حداکثر طول فایل: ۱۰ دقیقه\n• حداکثر طول هر برش: ۲ دقیقه\n\n📢 اسپانسر می‌خوای؟\nاگه می‌خوای کانالت رو به کاربرای میوفای معرفی کنی، برای رزرو اسپانسری و تبلیغات بهمون پیام بده:\n@AD_Dotfar \n\n🐾 سازنده میوفای:\n@Dotfar1207";
    sendMessage($chatId, $text);
}

function setChannelCommand(int $chatId, int $userId, array $args): void {
    global $ADMIN_ID;
    if ($userId !== $ADMIN_ID) return;
    if (empty($args[0])) {
        sendMessage($chatId, "مثال:\n/setchannel @channelusername");
        return;
    }
    $channel = trim($args[0]);
    if ($channel[0] !== '@') $channel = '@' . $channel;

    $result = tg('getChat', ['chat_id' => $channel]);
    if ($result['ok'] ?? false) {
        saveRequiredChannel($channel);
        sendMessage($chatId, "کانال جوین اجباری با موفقیت تنظیم شد ✅\n\n{$channel}");
    } else {
        sendMessage($chatId, "نتونستم به این کانال دسترسی پیدا کنم 😿\n\nمطمئن شو ربات داخل کانال ادمین باشه و آیدی کانال رو درست وارد کرده باشی.");
    }
}

function removeChannelCommand(int $chatId, int $userId): void {
    global $ADMIN_ID;
    if ($userId !== $ADMIN_ID) return;
    removeRequiredChannel();
    sendMessage($chatId, "کانال جوین اجباری حذف شد ✅");
}

function showChannelCommand(int $chatId, int $userId): void {
    global $ADMIN_ID;
    if ($userId !== $ADMIN_ID) return;
    $channel = loadRequiredChannel();
    sendMessage($chatId, $channel
        ? "کانال فعلی جوین اجباری:\n{$channel}"
        : "در حال حاضر هیچ کانال جوین اجباری تنظیم نشده."
    );
}

function handleVoice(int $chatId, int $userId, array $message): void {
    if (!checkMembership([], $chatId, $userId)) return;
    clearUserState($userId);

    sendMessage($chatId, "ویست رو گرفتم 🎙️\nدارم تبدیلش می‌کنم، یه لحظه صبر کن...");

    $input = null;
    $output = null;
    try {
        $input = tempFile('.ogg');
        $output = tempFile('.mp3');
        if (!downloadTelegramFile($message['voice']['file_id'], $input)) throw new RuntimeException('Download failed');

        $duration = getAudioDuration($input);
        if ($duration === null) {
            sendError($chatId);
            return;
        }
        if ($duration > 600) {
            sendMessage($chatId, "این ویس " . secondsToTime($duration) . " طول داره.\n\nحداکثر زمانی که می‌تونم پردازش کنم ۱۰ دقیقه‌ست 😿");
            return;
        }

        $cmd = 'ffmpeg -y -i ' . escapeshellarg($input) . ' -c:a libmp3lame -b:a 128k ' . escapeshellarg($output) . ' 2>&1';
        exec($cmd, $ffout, $code);
        if ($code !== 0) {
            error_log("FFmpeg voice error: " . implode("\n", $ffout ?? []));
            sendError($chatId);
            return;
        }

        sendAudio($chatId, $output);
    } catch (Throwable $e) {
        error_log('Voice convert error: ' . $e->getMessage());
        sendError($chatId);
    } finally {
        foreach ([$input, $output] as $file) if ($file && is_file($file)) @unlink($file);
    }
}

function handleMusic(int $chatId, int $userId, array $message): void {
    if (!checkMembership([], $chatId, $userId)) return;
    clearUserState($userId);

    try {
        if (isset($message['audio'])) {
            $fileId = $message['audio']['file_id'];
            $fileName = $message['audio']['file_name'] ?? 'music.mp3';
        } elseif (isset($message['document'])) {
            $mime = $message['document']['mime_type'] ?? '';
            if (!str_starts_with($mime, 'audio/')) return;
            $fileId = $message['document']['file_id'];
            $fileName = $message['document']['file_name'] ?? 'music.mp3';
        } else {
            return;
        }

        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $ext = $ext ? '.' . $ext : '.mp3';
        $input = tempFile($ext);
        if (!downloadTelegramFile($fileId, $input)) throw new RuntimeException('Download failed');

        $duration = getAudioDuration($input);
        if ($duration === null) {
            @unlink($input);
            sendError($chatId);
            return;
        }
        if ($duration > 600) {
            @unlink($input);
            sendMessage($chatId, "این آهنگ " . secondsToTime($duration) . " طول داره.\n\nحداکثر طول فایل ۱۰ دقیقه‌ست 😿\nیه آهنگ کوتاه‌تر بفرست.");
            return;
        }

        setUserState($userId, [
            'input_file' => $input,
            'duration' => $duration,
            'state' => 'waiting_start',
        ]);

        sendMessage($chatId, "میو! آهنگ رسید 😺🎵\n\nمدت آهنگ: " . secondsToTime($duration) . "\nبگو از کجا شروع کنم؟ ⏱️\nمثلاً: 43 یا 00:43\n\nاگه پشیمون شدی، لغو رو بزن", cancelKeyboard());
    } catch (Throwable $e) {
        error_log('Music receive error: ' . $e->getMessage());
        $state = getUserState($userId);
        if (!empty($state['input_file']) && is_file($state['input_file'])) @unlink($state['input_file']);
        clearUserState($userId, false);
        sendError($chatId);
    }
}

function cancelOperation(int $chatId, int $userId): void {
    clearUserState($userId);
    sendMessage($chatId, "عملیات لغو شد\nهر وقت خواستی دوباره شروع کنیم، آهنگت رو بفرست 🎵", removeKeyboard());
}

function handleTime(int $chatId, int $userId, string $text): void {
    if (!checkMembership([], $chatId, $userId)) return;
    $state = getUserState($userId);
    $current = $state['state'] ?? null;
    if (!in_array($current, ['waiting_start', 'waiting_end'], true)) return;

    $text = trim($text);
    if ($text === CANCEL_BUTTON) {
        cancelOperation($chatId, $userId);
        return;
    }

    $seconds = timeToSeconds($text);
    if ($seconds === null) {
        sendMessage($chatId, "میو؟ این زمانو نفهمیدم 😿\n\nاینطوری برام بفرست:\n43 یا 00:43 یا 01:20 ⏱️");
        return;
    }

    $duration = $state['duration'] ?? null;
    if ($duration === null) {
        clearUserState($userId);
        sendMessage($chatId, "اطلاعات آهنگ پیدا نشد 😿\nلطفاً دوباره آهنگت رو بفرست.", removeKeyboard());
        return;
    }

    if ($current === 'waiting_start') {
        if ($seconds >= $duration) {
            sendMessage($chatId, "زمان شروع نمی‌تونه برابر یا بیشتر از مدت آهنگ باشه 😿\n\nمدت آهنگ: " . secondsToTime($duration) . "\nیه زمان کوتاه‌تر وارد کن.");
            return;
        }
        $state['start'] = $seconds;
        $state['state'] = 'waiting_end';
        setUserState($userId, $state);
        sendMessage($chatId, "شروع رو روی " . secondsToTime($seconds) . " گذاشتم ✅\n\nحالا بگو برش تا چه زمانی ادامه داشته باشه؟ ⏱️\nمثلاً اگر می‌خوای تا دقیقه ۱:۲۰ ادامه داشته باشه، بنویس:\n01:20\n\nحداکثر طول برش ۲ دقیقه است", cancelKeyboard());
        return;
    }

    $start = $state['start'] ?? null;
    if ($start === null) {
        clearUserState($userId);
        sendMessage($chatId, "زمان شروع پیدا نشد 😿\nلطفاً دوباره آهنگت رو بفرست.", removeKeyboard());
        return;
    }
    if ($seconds > $duration) {
        sendMessage($chatId, "زمان پایان از مدت آهنگ بیشتره 😿\n\nمدت آهنگ: " . secondsToTime($duration) . "\nیه زمان کوتاه‌تر وارد کن.");
        return;
    }
    if ($seconds <= $start) {
        sendMessage($chatId, "زمان پایان باید بعد از زمان شروع باشه 😺\n\nشروع فعلی: " . secondsToTime($start) . "\nیه زمان پایان درست وارد کن.");
        return;
    }

    $cutDuration = $seconds - $start;
    if ($cutDuration > 120) {
        sendMessage($chatId, "این برش " . secondsToTime($cutDuration) . " میشه 😿\n\nحداکثر طول هر برش ۲ دقیقه است.\nزمان پایان رو کمی نزدیک‌تر به زمان شروع انتخاب کن.");
        return;
    }

    sendMessage($chatId, "همه‌چی آماده‌ست 🎧✂️\nدارم قسمت انتخابی رو برش می‌زنم...\n\nیه لحظه صبر کن 😺", removeKeyboard());

    $input = $state['input_file'] ?? null;
    if (!$input || !is_file($input)) {
        clearUserState($userId, false);
        sendMessage($chatId, "فایل آهنگ دیگه در دسترسم نیست 😿\nلطفاً دوباره آهنگ رو بفرست.");
        return;
    }

    $output = null;
    try {
        $output = tempFile('.ogg');
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($input) .
               ' -ss ' . escapeshellarg((string)$start) .
               ' -to ' . escapeshellarg((string)$seconds) .
               ' -vn -c:a libopus -b:a 96k ' . escapeshellarg($output) . ' 2>&1';
        exec($cmd, $ffout, $code);
        if ($code !== 0) {
            error_log("FFmpeg cut error: " . implode("\n", $ffout ?? []));
            sendError($chatId);
            return;
        }
        sendVoice($chatId, $output);
    } catch (Throwable $e) {
        error_log('Cut error: ' . $e->getMessage());
        sendError($chatId);
    } finally {
        if ($input && is_file($input)) @unlink($input);
        if ($output && is_file($output)) @unlink($output);
        clearUserState($userId, false);
    }
}

function handleUpdate(array $update): void {
    if (isset($update['callback_query'])) {
        $q = $update['callback_query'];
        $callbackId = $q['id'];
        answerCallback($callbackId);
        $userId = (int)$q['from']['id'];
        $chatId = (int)($q['message']['chat']['id'] ?? $userId);

        if (($q['data'] ?? '') === 'check_join') {
            if (isMember($userId)) {
                editMessageText($chatId, (int)$q['message']['message_id'], 'عضویتت تأیید شد 😺✅');
                sendWelcome($chatId);
            } else {
                answerCallback($callbackId, "هنوز عضویتت تأیید نشده 😿\nاول عضو کانال شو و دوباره امتحان کن.", true);
            }
        }
        return;
    }

    $message = $update['message'] ?? null;
    if (!$message) return;

    $chatId = (int)$message['chat']['id'];
    $userId = (int)($message['from']['id'] ?? $chatId);
    $text = $message['text'] ?? '';

    if ($text !== '' && str_starts_with($text, '/')) {
        $parts = preg_split('/\s+/', trim($text));
        $command = strtolower(explode('@', $parts[0])[0]);
        $args = array_slice($parts, 1);

        switch ($command) {
            case '/start': startCommand($chatId, $userId); break;
            case '/help': helpCommand($chatId); break;
            case '/cancel':
                $state = getUserState($userId);
                if (in_array($state['state'] ?? null, ['waiting_start', 'waiting_end'], true)) cancelOperation($chatId, $userId);
                break;
            case '/setchannel': setChannelCommand($chatId, $userId, $args); break;
            case '/removechannel': removeChannelCommand($chatId, $userId); break;
            case '/channel': showChannelCommand($chatId, $userId); break;
        }
        return;
    }

    if (isset($message['voice'])) {
        handleVoice($chatId, $userId, $message);
        return;
    }

    if (isset($message['audio']) || isset($message['document'])) {
        handleMusic($chatId, $userId, $message);
        return;
    }

    if ($text !== '') handleTime($chatId, $userId, $text);
}

$offset = 0;

echo "🐈 Meowfy is running...\n";

while (true) {
    $result = tg('getUpdates', [
        'offset' => $offset,
        'timeout' => 30,
        'allowed_updates' => json_encode(['message', 'callback_query']),
    ]);

    if (!($result['ok'] ?? false)) {
        sleep(2);
        continue;
    }

    foreach (($result['result'] ?? []) as $update) {
        $offset = ((int)$update['update_id']) + 1;
        try {
            handleUpdate($update);
        } catch (Throwable $e) {
            error_log('Update error: ' . $e->getMessage());
        }
    }
}
