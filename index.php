<?php
// Enable error reporting and log errors to bot.log
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bot.log');

require 'vendor/autoload.php';

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

// Custom logging function
function logAction($message, $severity = 'INFO', $context = []) {
    $logEntry = sprintf(
        "[%s] %s: %s %s\n",
        date('Y-m-d H:i:s'),
        str_pad($severity, 5),
        $message,
        !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
    );
    file_put_contents(__DIR__ . '/bot.log', $logEntry, FILE_APPEND);
}

// Load environment variables
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    logAction('Environment variables loaded');
} catch (Exception $e) {
    logAction('Dotenv initialization failed', 'ERROR', ['error' => $e->getMessage()]);
    die("Configuration error");
}

$bot_api_key = $_ENV['TELEGRAM_BOT_TOKEN'];
$bot_username = $_ENV['TELEGRAM_BOT_USERNAME'] ?? 'krizboldbot';
$koboldai_endpoint = $_ENV['KOBOLDAI_ENDPOINT'];

// Configure Guzzle with retry middleware
$handlerStack = HandlerStack::create();
$handlerStack->push(Middleware::retry(
    function ($retries, $request, $response, $exception) {
        $shouldRetry = $retries < 3 && ($exception instanceof \GuzzleHttp\Exception\ConnectException 
            || ($response && $response->getStatusCode() >= 500));
        if ($shouldRetry) {
            logAction('Retrying request', 'WARNING', [
                'attempt' => $retries + 1,
                'uri' => (string)$request->getUri()
            ]);
        }
        return $shouldRetry;
    },
    function ($retries) {
        return 1000 * pow(2, $retries);
    }
));

$guzzleClient = new Client([
    'handler' => $handlerStack,
    'timeout' => 45,
    'connect_timeout' => 10,
    'headers' => [
        'User-Agent' => 'TelegramBot/1.0',
        'Accept' => 'application/json'
    ]
]);

// Initialize Telegram Bot
try {
    $telegram = new Telegram($bot_api_key, $bot_username);
    logAction('Telegram bot initialized');
} catch (Exception $e) {
    logAction('Telegram initialization failed', 'ERROR', ['error' => $e->getMessage()]);
    die("Bot initialization error");
}

// Message formatting functions
function escape_message($message) {
    $reserved_chars = ['*', '_', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    return str_replace($reserved_chars, array_map(function($c) { return '\\' . $c; }, $reserved_chars), $message);
}

function personalizeMessage($message, $char_name, $user_name) {
    return str_replace(['{{char}}', '{{user}}'], [$char_name, $user_name], $message);
}

// AI Prompt generation
function getPrompt($conversation_history, $user, $text, $char_name, $char_data) {
    $memory = "You are {$char_data['name']}, a character with the following traits:\n" .
              "Personality: {$char_data['personality']}\nScenario: {$char_data['scenario']}\n" .
              "Always stay in character and respond as {$char_data['name']}.\n" .
              "Do not break character or act as the user.\nFirst Message: {$char_data['first_mes']}";

    $prompt = [
        "prompt" => "{$conversation_history}\n{$user}: {$text}\n{$char_name}:",
        "use_story" => false,
        "use_memory" => false,
        "use_authors_note" => false,
        "use_world_info" => false,
        "max_context_length" => 2048,
        "max_length" => 120,
        "rep_pen" => 1.1,
        "rep_pen_range" => 1024,
        "temperature" => 0.69,
        "top_p" => 0.9,
        "singleline" => true,
        "memory" => $memory
    ];
    
    logAction('Generated KoboldAI prompt', 'DEBUG', $prompt);
    return $prompt;
}

// Character management
function loadCharacterCards() {
    $cards = [];
    foreach (array_merge(glob('cards/*.json'), glob('cards/custom/*.json')) as $file) {
        try {
            $cardData = json_decode(file_get_contents($file), true);
            if ($cardData) {
                $cardName = basename($file, '.json');
                if (strpos($cardName, '_') !== false) {
                    $cardName = substr($cardName, strpos($cardName, '_') + 1);
                }
                $cards[$cardName] = $cardData;
                logAction('Loaded character card', 'INFO', ['file' => $file]);
            }
        } catch (Exception $e) {
            logAction('Failed to load character card', 'ERROR', ['file' => $file, 'error' => $e->getMessage()]);
        }
    }
    return $cards;
}

function handleFileUpload($userId, $fileId, $fileName, $guzzleClient, $bot_api_key) {
    logAction('File upload started', 'INFO', ['user_id' => $userId, 'file_name' => $fileName]);
    
    try {
        $fileResponse = $guzzleClient->get("https://api.telegram.org/bot{$bot_api_key}/getFile?file_id={$fileId}");
        $fileData = json_decode($fileResponse->getBody(), true);
        
        if (!isset($fileData['result']['file_path'])) {
            logAction('File path not found', 'ERROR', $fileData);
            return ['success' => false, 'message' => 'File access error'];
        }

        $filePath = $fileData['result']['file_path'];
        $fileContent = $guzzleClient->get("https://api.telegram.org/file/bot{$bot_api_key}/{$filePath}")
            ->getBody()
            ->getContents();

        $isImage = pathinfo($fileName, PATHINFO_EXTENSION) === 'png';
        $safeName = '';
        
        if ($isImage) {
            $tempFile = tempnam(sys_get_temp_dir(), 'char_card');
            file_put_contents($tempFile, $fileContent);
            $jsonData = extractCharacterDataFromPNG($tempFile);
            unlink($tempFile);

            if (!$jsonData) {
                logAction('Invalid PNG character card', 'ERROR', ['file' => $fileName]);
                return ['success' => false, 'message' => 'Invalid character card image'];
            }

            $cardData = json_decode($jsonData, true);
            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cardData['data']['name'] ?? '');
            $targetFile = "cards/custom/{$userId}_{$safeName}.json";
            file_put_contents($targetFile, $jsonData);
            file_put_contents("cards/custom/{$userId}_{$safeName}.png", $fileContent);
        } else {
            $cardData = json_decode($fileContent, true);
            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cardData['data']['name'] ?? '');
            $targetFile = "cards/custom/{$userId}_{$safeName}.json";
            file_put_contents($targetFile, $fileContent);
        }

        logAction('File upload successful', 'INFO', ['user_id' => $userId, 'file' => $targetFile]);
        return ['success' => true, 'cardName' => $safeName, 'message' => 'Character card processed!'];

    } catch (RequestException $e) {
        logAction('File upload failed', 'ERROR', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return ['success' => false, 'message' => 'Processing error'];
    }
}

function extractCharacterDataFromPNG($imagePath) {
    try {
        $file = fopen($imagePath, 'rb');
        fread($file, 8); // Skip PNG header
        
        while (!feof($file)) {
            $lengthData = fread($file, 4);
            if (strlen($lengthData) < 4) break;
            $length = unpack('N', $lengthData)[1];
            
            $type = fread($file, 4);
            $data = $length > 0 ? fread($file, $length) : '';
            fread($file, 4); // Skip CRC

            if ($type === 'tEXt') {
                $parts = explode("\0", $data, 2);
                if (count($parts) === 2 && $parts[0] === 'chara') {
                    return base64_decode($parts[1]);
                }
            }
            if ($type === 'IEND') break;
        }
        return null;
    } finally {
        if (isset($file)) fclose($file);
    }
}

// Session management
function loadUserSession($userId) {
    $sessionFile = __DIR__ . "/users/{$userId}.json";
    if (file_exists($sessionFile)) {
        try {
            $sessionData = json_decode(file_get_contents($sessionFile), true);
            logAction('Session loaded', 'DEBUG', ['user_id' => $userId]);
            return $sessionData;
        } catch (Exception $e) {
            logAction('Session load failed', 'ERROR', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
    return null;
}

function saveUserSession($userId, $data) {
    try {
        $sessionFile = __DIR__ . "/users/{$userId}.json";
        if (!is_dir(dirname($sessionFile))) {
            mkdir(dirname($sessionFile), 0755, true);
        }
        file_put_contents($sessionFile, json_encode($data));
        logAction('Session saved', 'DEBUG', ['user_id' => $userId]);
    } catch (Exception $e) {
        logAction('Session save failed', 'ERROR', ['user_id' => $userId, 'error' => $e->getMessage()]);
    }
}

// Keyboard layout
function createKeyboard($userId = null) {
    $cards = loadCharacterCards();
    $cardNames = array_keys($cards);
    
    return [
        'keyboard' => array_chunk(array_merge($cardNames, ['Stop Session', 'Switch Character', 'Upload Custom Card']), 3),
        'one_time_keyboard' => false,
        'resize_keyboard' => true
    ];
}

// Main request handling
$update = json_decode(file_get_contents('php://input'), true);
logAction('Incoming request', 'DEBUG', $update);

if (!$update) {
    logAction('Invalid request received', 'WARNING');
    exit;
}

$message = $update['message'] ?? null;
if (!$message) {
    logAction('Empty message received', 'WARNING');
    exit;
}

$chat_id = $message['chat']['id'];
$text = $message['text'] ?? '';
$user = $message['from']['first_name'] ?? 'User';
$userId = $message['from']['id'] ?? 0;
$session = loadUserSession($userId);

logAction('Processing message', 'INFO', [
    'user_id' => $userId,
    'chat_id' => $chat_id,
    'text' => $text
]);

// Command handling
switch ($text) {
    case '/start':
        logAction('Handling /start command', 'INFO');
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "Welcome! Choose a character to start chatting.",
            'reply_markup' => json_encode(createKeyboard($userId))
        ]);
        exit;

    case 'Stop Session':
        logAction('Stopping session', 'INFO', ['user_id' => $userId]);
        saveUserSession($userId, null);
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "Session stopped. Use /start to begin again.",
            'reply_markup' => json_encode(createKeyboard($userId))
        ]);
        exit;

    case 'Switch Character':
        logAction('Switching character', 'INFO', ['user_id' => $userId]);
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "Choose a character:",
            'reply_markup' => json_encode(createKeyboard($userId))
        ]);
        exit;

    case 'Upload Custom Card':
        logAction('Requesting card upload', 'INFO', ['user_id' => $userId]);
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "Send me a character card JSON file or PNG (as FILE not PHOTO).",
            'reply_markup' => ['remove_keyboard' => true]
        ]);
        exit;
}

// File upload handling
if (isset($message['document'])) {
    $doc = $message['document'];
    $result = handleFileUpload($userId, $doc['file_id'], $doc['file_name'], $guzzleClient, $bot_api_key);
    
    Request::sendMessage([
        'chat_id' => $chat_id,
        'text' => $result['success'] 
            ? "✅ {$result['message']} New character '{$result['cardName']}' added!" 
            : "❌ {$result['message']}",
        'reply_markup' => json_encode(createKeyboard($userId))
    ]);
    exit;
}

// Character selection
$cards = loadCharacterCards();
if (isset($cards[$text])) {
    logAction('Character selected', 'INFO', ['user_id' => $userId, 'character' => $text]);
    $charData = $cards[$text]['data'];
    $conversation = "{$charData['name']}'s Persona: {$charData['personality']}\n";
    $conversation .= "World Scenario: {$charData['scenario']}\n\n";
    $conversation .= "{$charData['name']}: {$charData['first_mes']}\n";

    saveUserSession($userId, [
        'char_name' => $charData['name'],
        'char_data' => $charData,
        'conversation_history' => $conversation
    ]);

    Request::sendMessage([
        'chat_id' => $chat_id,
        'text' => personalizeMessage($charData['first_mes'], $charData['name'], $user),
        'reply_markup' => json_encode(createKeyboard($userId))
    ]);
    exit;
}

// Chat handling
if ($session && isset($session['char_name'])) {
    try {
        $prompt = getPrompt(
            $session['conversation_history'],
            $user,
            $text,
            $session['char_name'],
            $session['char_data']
        );

        $response = $guzzleClient->post($koboldai_endpoint . '/api/v1/generate', [
            'json' => $prompt,
            'timeout' => 45
        ]);

        logAction('KoboldAI response', 'DEBUG', [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getBody(), true)
        ]);

        $responseData = json_decode($response->getBody(), true);
        if (isset($responseData['results'][0]['text'])) {
            $generated = trim(explode("\n", $responseData['results'][0]['text'])[0]);
            $responseText = escape_message(personalizeMessage(
                str_replace(["\n", "*"], [" ", "_"], $generated),
                $session['char_name'],
                $user
            ));

            $newHistory = $session['conversation_history'] . "{$user}: {$text}\n{$session['char_name']}: {$responseText}\n";
            saveUserSession($userId, array_merge($session, ['conversation_history' => $newHistory]));

            Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => $responseText,
                'parse_mode' => 'MarkdownV2',
                'reply_markup' => json_encode(createKeyboard($userId))
            ]);
        } else {
            throw new Exception('Invalid response format from KoboldAI');
        }

    } catch (RequestException $e) {
        logAction('KoboldAI request failed', 'ERROR', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null
        ]);
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "⚠️ Connection error. Please try again later."
        ]);
    } catch (Exception $e) {
        logAction('Processing error', 'ERROR', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "❌ Error processing your request."
        ]);
    }
} else {
    Request::sendMessage([
        'chat_id' => $chat_id,
        'text' => "Please select a character first!",
        'reply_markup' => json_encode(createKeyboard($userId))
    ]);
}

// Telegram API request logging
Request::setClient(new GuzzleHttp\Client([
    'handler' => HandlerStack::create(),
    'on_stats' => function (\GuzzleHttp\TransferStats $stats) {
        logAction('Telegram API call', 'DEBUG', [
            'uri' => (string)$stats->getEffectiveUri(),
            'status' => $stats->hasResponse() ? $stats->getResponse()->getStatusCode() : null,
            'duration' => round($stats->getTransferTime(), 3)
        ]);
    }
]));
