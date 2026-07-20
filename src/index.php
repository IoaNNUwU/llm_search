<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/ollama.php';
require_once __DIR__ . '/lib/search.php';
require_once __DIR__ . '/lib/chat.php';

$ollamaModel = ollama_model();
$chat = chat_handle_post($ollamaModel);
$error = $chat['error'];
$prompt = $chat['prompt'];
$windowId = chat_window_id();
$messages = chat_messages();
$chatLanguage = normalize_chat_language($_SESSION['chat_language'] ?? 'ru');

require __DIR__ . '/views/layout.php';
