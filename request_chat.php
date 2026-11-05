<?php

// +----------------------------------------------------------------------
// | Telegram ChatBot 异步任务处理器
// | 职责：执行所有耗时和核心的业务逻辑
// +----------------------------------------------------------------------

declare(strict_types=1);

spl_autoload_register(function ($class) {
    $prefix = 'ChatBot\\';
    $base_dir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

use ChatBot\Core\CurlHelper;
use ChatBot\Core\Logger;
use ChatBot\Database\FileStore;
use ChatBot\Database\RedisStore;
use ChatBot\Services\TelegramAPI;
use ChatBot\Services\AIService;
use ChatBot\Business\ContextManager;
use ChatBot\Business\PersonaManager;
use ChatBot\Business\SystemPromptManager;
use ChatBot\Business\KnowledgeBaseManager;
use ChatBot\Business\StickerManager;
use ChatBot\Services\ToolService;
use ChatBot\Services\ToolExecutor;
use ChatBot\Services\ImageService;

$config = require __DIR__ . '/config.php';

$logger = new Logger($config['log']);

// main_chat.php 派发任务后 1 秒就断开连接，回复 (含搜索/画图) 可能耗时数分钟，不能随连接中断
ignore_user_abort(true);
set_time_limit(0);


if (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
    // cron task
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $logger->warning('RequestWorker: 收到非POST请求');
    http_response_code(405);
    exit;
} elseif (!CurlHelper::isValidWorkerRequest((string)($config['worker']['secret'] ?? ''))) {
    $logger->warning('RequestWorker: 拒绝未授权的请求 (请检查 worker.secret 配置)', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    http_response_code(403);
    exit;
}

$payloadJson = file_get_contents('php://input');
$payload = json_decode($payloadJson, true);

if (!$payload || !isset($payload['type'], $payload['data'])) {
    $logger->error('RequestWorker: 无效的载荷', ['payload' => $payloadJson]);
    http_response_code(400);
    exit;
}

try {
    switch ($payload['type']) {
        case 'handle_message':
            handle_message($payload['data'], $config, $logger);
            break;
        default:
            $logger->warning('RequestWorker: 未知的任务类型', ['type' => $payload['type']]);
    }
} catch (\Throwable $e) {
    $logger->error('RequestWorker: 处理任务时发生致命错误', [
        'type' => $payload['type'],
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

http_response_code(200);
echo "OK";


function initialize_services(array $config, Logger $logger): array
{
    $dbDriver = $config['database']['driver'] ?? 'file';
    $db = ($dbDriver === 'redis')
        ? new RedisStore($config['database']['redis'], $logger)
        : new FileStore($config['database']['file'], $logger);

    $tg = new TelegramAPI($config['telegram']['bot_token'], $logger);

    return [
        'db' => $db,
        'tg' => $tg,
        'ai' => new AIService($config, $logger),
        'contextManager' => new ContextManager($db, $config['context'], $logger),
        'personaManager' => new PersonaManager($config['business'], $logger),
        'systemPromptManager' => new SystemPromptManager($config['paths'], $logger),
        'toolService' => new ToolService($config, $logger),
        'toolExecutor' => new ToolExecutor(new ToolService($config, $logger), $logger),
        'imageService' => new ImageService($config, $logger),
        'stickerManager' => new StickerManager($db, $tg, $config, $logger),
        'knowledgeManager' => new KnowledgeBaseManager($config, $logger),
    ];
}

/**
 * 处理单条消息的核心逻辑
 */
function handle_message(array $message, array $config, Logger $logger): void
{
    global $payload; // 从全局范围获取payload
    $receivedAt = $payload['received_at'] ?? time();
    $processingStartTime = microtime(true);

    // 提前获取关键信息
    $chatId = (int)$message['chat']['id'];
    $messageId = (int)$message['message_id'];
    
    $logger->debug('开始处理新消息', [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ]);

    // 初始化服务
    $services = initialize_services($config, $logger);
        $tg = $services['tg'];
        $ai = $services['ai'];
        $contextManager = $services['contextManager'];
        $personaManager = $services['personaManager'];
        $systemPromptManager = $services['systemPromptManager'];
        $toolService = $services['toolService'];
        $toolExecutor = $services['toolExecutor'];
        $imageService = $services['imageService'];
        $stickerManager = $services['stickerManager'];
        $knowledgeManager = $services['knowledgeManager'];

        $userId = (int)$message['from']['id'];
        $text = $message['text'] ?? $message['caption'] ?? '';
        $botUsername = $config['telegram']['bot_username'];

    $formattedMessage = [
        'role' => 'user',
        'uid' => $userId,
        'username' => $message['from']['username'] ?? '',
        'full_name' => trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')),
        'timestamp' => $message['date'],
        'message_id' => $messageId,
    ];

    if (isset($message['text'])) {
        $formattedMessage['text_content'] = $message['text'];
    }
    if (isset($message['caption'])) {
        $formattedMessage['caption_content'] = $message['caption'];
    }

    if (isset($message['reply_to_message'])) {
        $replyTo = $message['reply_to_message'];
        $formattedMessage['reply_to'] = [
            'uid' => $replyTo['from']['id'] ?? null,
            'username' => $replyTo['from']['username'] ?? '',
            'full_name' => trim(($replyTo['from']['first_name'] ?? '') . ' ' . ($replyTo['from']['last_name'] ?? '')),
            'content_message_id' => $replyTo['message_id'],
        ];
    }

    if (isset($message['forward_date']) || isset($message['forward_from']) || isset($message['forward_from_chat']) || isset($message['forward_sender_name'])) {
        $formattedMessage['is_forwarded'] = true;
        
        if (isset($message['forward_from'])) {
            $forwardFrom = $message['forward_from'];
            $formattedMessage['forward_from'] = [
                'uid' => $forwardFrom['id'],
                'username' => $forwardFrom['username'] ?? '',
                'full_name' => trim(($forwardFrom['first_name'] ?? '') . ' ' . ($forwardFrom['last_name'] ?? '')),
            ];
        } elseif (isset($message['forward_from_chat'])) {
            $forwardChat = $message['forward_from_chat'];
            $formattedMessage['forward_from_chat'] = [
                'id' => $forwardChat['id'],
                'title' => $forwardChat['title'] ?? '',
                'type' => $forwardChat['type'] ?? '',
            ];
        } elseif (isset($message['forward_sender_name'])) {
            $formattedMessage['forward_sender_name'] = $message['forward_sender_name'];
        }
    }

    $photos = $message['photo'] ?? null;
    if ($photos) {
        $fileId = end($photos)['file_id'];
        $formattedMessage['image_file_id'] = $fileId;

        try {
            $imagePrompt = $systemPromptManager->get('describe_image') ?: 'Describe this image in detail.';
            $imageParseStartTime = microtime(true);
            $description = $ai->getImageDescription($fileId, $imagePrompt);
            $imageParseTime = round(microtime(true) - $imageParseStartTime, 3);

            if ($description) {
                $formattedMessage['content_png'] = [$description];
                $formattedMessage['image_status'] = 'success';
                $logger->info('图片解析成功', ['parse_time' => $imageParseTime . 's', 'desc_length' => strlen($description)], $chatId);
            } else {
                $formattedMessage['content_png'] = ['[图片解析失败]'];
                $formattedMessage['image_status'] = 'failed';
                $logger->warning('图片解析失败', ['file_id' => $fileId], $chatId);
            }
        } catch (\Exception $e) {
            $formattedMessage['content_png'] = ['[图片解析异常]'];
            $formattedMessage['image_status'] = 'error';
            $logger->error('图片解析发生异常', ['error' => $e->getMessage()], $chatId);
        }
    }

    if (isset($message['sticker'])) {
        $formattedMessage['sticker'] = '[表情包] ' . ($message['sticker']['emoji'] ?? '');
        $stickerManager->remember($chatId, $message['sticker']);
    }

    $hasTextContent = !empty($formattedMessage['text_content']) || !empty($formattedMessage['caption_content']);
    $hasImageContent = ($formattedMessage['image_status'] ?? null) === 'success';
    if (!$hasTextContent && !$hasImageContent && !isset($formattedMessage['sticker'])) {
        $logger->debug('消息既无文本也无有效图片描述，已忽略', [], $chatId);
        return;
    }

    $context = $contextManager->getContext($chatId);
    $contextManager->addMessage($chatId, $formattedMessage);

    $enableTimeCount = $config['debug']['time_count'] ?? false;
    $judgeStartTime = $enableTimeCount ? microtime(true) : 0;
    $judgePrompt = $systemPromptManager->get('judge');
    $action = 'IGNORE'; // 默认行为

    if (!$judgePrompt || !is_string($judgePrompt)) {
        $logger->error('缺少或无效的判定提示词(judge.txt)，执行默认忽略操作', [], $chatId);
    } else {
        $chatConfig = $config['business']['chat_configs'][$chatId] ?? [];
        $chatModels = $chatConfig['models'] ?? [];
        
        $knowledgeLoadStartTime = $enableTimeCount ? microtime(true) : 0;
        $judgeKnowledgeConfig = $config['business']['judge_uses_knowledge'] ?? ['enabled' => false];
        $knowledgeForJudge = '';
        if ($judgeKnowledgeConfig['enabled']) {
            $knowledgeForJudge = $knowledgeManager->getKnowledge($chatId, [
                'permanent' => $judgeKnowledgeConfig['permanent'] ?? true,
                'group' => $judgeKnowledgeConfig['group'] ?? true,
            ]);
        }
        if ($enableTimeCount) {
            $knowledgeLoadTime = round(microtime(true) - $knowledgeLoadStartTime, 3);
            $logger->debug('Judge知识库加载完成', ['time_spent' => $knowledgeLoadTime . 's', 'size' => strlen($knowledgeForJudge)], $chatId);
        }
        
        $judgeWindowSize = $config['context']['judge_window_size'] ?? 30;
        $recentContext = array_slice($context, -$judgeWindowSize);
        
        $judgeInput = [
            'bot_profile' => [
                'uid' => $config['telegram']['bot_id'] ?? null,
                'username' => $botUsername,
                'nicknames' => $config['telegram']['bot_nicknames'] ?? [],
            ],
            'knowledge_base' => $knowledgeForJudge,
            'chat_history' => $recentContext,
            'latest_message' => $formattedMessage,
        ];
        $userInput = json_encode($judgeInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $logger->debug('Judge模型上下文优化', [
            'total_context_size' => count($context),
            'judge_context_size' => count($recentContext),
            'window_size' => $judgeWindowSize,
            'input_size' => strlen($userInput)
        ], $chatId);
        
        $judgeMessages = [['role' => 'user', 'content' => $userInput]];

        $judgeApiStartTime = $enableTimeCount ? microtime(true) : 0;
        $rawAction = $ai->generateReply('judge', '', $judgePrompt, $judgeMessages);
        if ($enableTimeCount) {
            $judgeApiTime = round(microtime(true) - $judgeApiStartTime, 3);
            $logger->debug('Judge API调用完成', ['time_spent' => $judgeApiTime . 's', 'function' => 'judge'], $chatId);
        }
        
        if ($rawAction) {
            $action = parse_judge_action($rawAction);
            
            if (!in_array($action, ['REJECT', 'REPLY', 'REPLY_PRO', 'IGNORE'])) {
                $logger->warning('Judge返回了无效的action，使用默认值IGNORE', ['invalid_action' => $action, 'raw' => $rawAction], $chatId);
                $action = 'IGNORE';
            }
        } else {
            $action = 'IGNORE';
        }
    }

    // 被点名 (昵称 / @用户名) 或被回复时必须回复，不完全依赖 judge 模型
    if ($action === 'IGNORE' && is_bot_addressed($formattedMessage, $config['telegram'])) {
        $logger->info('消息点名或回复了 bot，judge 返回 IGNORE，改为 REPLY', [], $chatId);
        $action = 'REPLY';
    }

    if ($enableTimeCount) {
        $judgeTotalTime = round(microtime(true) - $judgeStartTime, 3);
        $logger->debug('安全与决策判定完成', ['action' => $action, 'total_time' => $judgeTotalTime . 's'], $chatId);
    } else {
        $logger->debug('安全与决策判定完成', ['action' => $action], $chatId);
    }

    switch ($action) {
        case 'REJECT':
            $logger->warning('检测到注入攻击，消息已丢弃', ['user_id' => $userId, 'text' => $text], $chatId);
            return;
        case 'REPLY':
        case 'REPLY_PRO':
            $serverResponseTime = round($processingStartTime - $receivedAt, 2);
            $decisionTime = round(microtime(true) - $processingStartTime, 2);
            $replyType = ($action === 'REPLY_PRO') ? '专业' : '普通';
            $logger->info("AI决定{$replyType}回复，服务器响应（{$serverResponseTime} 秒）用时 {$decisionTime} 秒", ['user_id' => $userId, 'action' => $action], $chatId);
            $replyStartTime = microtime(true);
            break;
        case 'IGNORE':
        default:
            if ($action !== 'IGNORE') {
                $logger->warning('收到未知的决策action，执行默认忽略操作', ['action' => $action], $chatId);
            }
            $logger->debug('消息已存入上下文，但AI决定不回复', ['user_id' => $userId], $chatId);
            return;
    }

    $replyPrepStartTime = $enableTimeCount ? microtime(true) : 0;
    
    $currentContext = $contextManager->getContext($chatId);
    
    $localContext = [];
    $currentMessageFound = false;
    
    foreach ($currentContext as $msg) {
        if (isset($msg['message_id']) && (int)$msg['message_id'] === $messageId && ($msg['role'] ?? '') === 'user') {
            $msg['_is_current_message'] = true;
            $currentMessageFound = true;
        }
        $localContext[] = $msg;
    }
    
    if (!$currentMessageFound) {
        $logger->warning('在上下文中未找到当前消息', [
            'message_id' => $messageId,
            'context_size' => count($currentContext)
        ], $chatId);
    }
    
    $prompt = $personaManager->getPrompt($userId);
    
    try {
        $date = new \DateTime("now", new \DateTimeZone('Asia/Shanghai'));
        $beijingTime = $date->format('Y-m-d H:i:s');
        $prompt = str_replace('{{current_time}}', $beijingTime, $prompt);
    } catch (\Exception $e) {
        $logger->warning('无法获取北京时间，将移除占位符', ['error' => $e->getMessage()], $chatId);
        $prompt = str_replace('{{current_time}}', 'N/A', $prompt);
    }

    $replyKnowledgeStartTime = $enableTimeCount ? microtime(true) : 0;
    $knowledgeForReply = $knowledgeManager->getKnowledge($chatId, ['permanent' => true, 'group' => true]);
    
    if (!empty($knowledgeForReply)) {
        $knowledgeSystemMessage = "You have access to a dossier...\n---{$knowledgeForReply}\n---";
        array_unshift($localContext, ['role' => 'system', 'content' => $knowledgeSystemMessage]);
    }
    
    if ($enableTimeCount) {
        $replyKnowledgeTime = round(microtime(true) - $replyKnowledgeStartTime, 3);
        $replyPrepTime = round(microtime(true) - $replyPrepStartTime, 3);
        $logger->debug('Reply模型准备完成', [
            'prep_time' => $replyPrepTime . 's',
            'knowledge_load_time' => $replyKnowledgeTime . 's',
            'knowledge_size' => strlen($knowledgeForReply),
            'context_size' => count($localContext),
            'current_message_found' => $currentMessageFound
        ], $chatId);
    }

    $replyFunction = ($action === 'REPLY_PRO') ? 'reply_pro' : 'reply';
    $replyMessages = build_reply_messages($localContext);

    $stickerEmojis = $stickerManager->isEnabled() ? $stickerManager->getEmojis($chatId) : [];
    if ($stickerEmojis) {
        $prompt .= "\n\n" . build_sticker_instructions($stickerEmojis);
    }

    $availableTools = get_available_tools($config, $toolService, $imageService);
    if ($availableTools) {
        $prompt .= "\n\n" . build_tool_instructions($availableTools);
    }

    // 论坛 (话题) 群里不带 reply_parameters 发送时需要指定话题，否则会发到 General
    $threadOptions = (!empty($message['is_topic_message']) && isset($message['message_thread_id']))
        ? ['message_thread_id' => (int)$message['message_thread_id']]
        : [];

    // 工具调用循环：模型输出 <tool_call> 时执行工具并把结果回传，直到模型给出最终回复
    $maxToolRounds = max(0, (int)($config['tools']['max_rounds'] ?? 3));
    $generatedImage = null;
    $drawPrompt = null;
    for ($round = 0; ; $round++) {
        $replyApiStartTime = $enableTimeCount ? microtime(true) : 0;
        $rawReply = $ai->generateReply($replyFunction, '', $prompt, $replyMessages);
        if ($enableTimeCount) {
            $logger->debug('Reply API调用完成', ['time_spent' => round(microtime(true) - $replyApiStartTime, 3) . 's', 'function' => $replyFunction, 'round' => $round], $chatId);
        }

        $toolCalls = ($rawReply !== null && $availableTools) ? parse_tool_calls($rawReply, $availableTools) : [];
        if (!$toolCalls) {
            break;
        }

        $replyMessages[] = ['role' => 'assistant', 'content' => $rawReply];
        if ($round >= $maxToolRounds) {
            $replyMessages[] = ['role' => 'user', 'content' => "[TOOL_LIMIT] 工具调用次数已用完，不要再调用工具，请直接按输出协议给出最终回复。"];
            $rawReply = $ai->generateReply($replyFunction, '', $prompt, $replyMessages);
            break;
        }

        $logger->info('回复模型请求调用工具', ['round' => $round, 'tools' => $toolCalls], $chatId);
        $toolResults = [];
        $httpCalls = [];
        foreach ($toolCalls as $call) {
            if ($call['tool'] !== 'DRAW') {
                $httpCalls[] = $call;
                continue;
            }
            if ($generatedImage !== null) {
                $toolResults[] = ['tool_name' => 'DRAW', 'query' => $call['query'], 'result' => '每次回复只能生成一张图片，已忽略。'];
                continue;
            }
            $tg->sendChatAction($chatId, 'upload_photo', $threadOptions);
            $generatedImage = $imageService->generate($call['query']);
            $drawPrompt = $call['query'];
            $toolResults[] = [
                'tool_name' => 'DRAW',
                'query' => $call['query'],
                'result' => $generatedImage ? '图片已生成，会和你的最终回复一起发送，回复内容会作为图片说明，请保持简短。' : '图片生成失败，请在回复中告知用户。',
            ];
        }
        if ($httpCalls) {
            $executed = $toolExecutor->execute($httpCalls, $chatId, $userId);
            if (!$executed) {
                $executed = [['tool_name' => 'ERROR', 'query' => '', 'result' => '工具执行失败或未配置。']];
            }
            $toolResults = array_merge($toolResults, $executed);
        }

        $replyMessages[] = [
            'role' => 'user',
            'content' => "[TOOL_RESULTS]\n" . json_encode($toolResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\n请根据以上结果继续：仍需信息可以再次调用工具，否则按输出协议给出最终回复。",
        ];
    }

    if ($rawReply === null || trim($rawReply) === '') {
        $logger->error('回复模型未返回有效内容', ['function' => $replyFunction], $chatId);
        return;
    }

    $parsedReply = parse_reply($rawReply);
    $replyText = tg_truncate($parsedReply['text'], 4096);
    $stickerFileId = ($stickerEmojis && $parsedReply['sticker'] !== null)
        ? $stickerManager->pick($chatId, $parsedReply['sticker'])
        : null;
    if ($replyText === '' && $stickerFileId === null) {
        $logger->warning('回复内容解析后为空，已放弃发送', ['raw' => $rawReply], $chatId);
        return;
    }

    $validMessageIds = array_map(
        static fn($m) => (int)($m['message_id'] ?? 0),
        array_filter($currentContext, static fn($m) => ($m['role'] ?? '') === 'user')
    );
    $finalReplyTargetId = ($parsedReply['target'] !== null && in_array($parsedReply['target'], $validMessageIds, true))
        ? $parsedReply['target']
        : $messageId;

    $sendStartTime = $enableTimeCount ? microtime(true) : 0;
    $replyParameters = [
        'message_id' => $finalReplyTargetId,
        'allow_sending_without_reply' => true,
    ];
    $sentMessage = null;
    if ($generatedImage !== null) {
        // 图片说明最长 1024 字符，超出时图片和文字分开发送
        $captionFits = tg_length($replyText) <= 1024;
        $sentMessage = $tg->sendPhoto($chatId, $generatedImage['content'], $generatedImage['mime'], array_filter([
            'caption' => ($captionFits && $replyText !== '') ? $replyText : null,
            'reply_parameters' => $replyParameters,
        ]));
        if (!$sentMessage) {
            $logger->error('发送生成的图片失败，改为只发送文字', [], $chatId);
        }
        if ($replyText !== '' && (!$sentMessage || !$captionFits)) {
            $sentMessage = $tg->sendMessage($chatId, $replyText, ['reply_parameters' => $replyParameters]) ?? $sentMessage;
        }
    } elseif ($replyText !== '') {
        $sentMessage = $tg->sendMessage($chatId, $replyText, ['reply_parameters' => $replyParameters]);
    }

    $sentSticker = null;
    if ($stickerFileId !== null) {
        // 已经发了文字/图片时表情包紧跟其后，否则表情包直接回复目标消息
        $sentSticker = $tg->sendSticker($chatId, $stickerFileId, $sentMessage ? $threadOptions : ['reply_parameters' => $replyParameters]);
        $sentMessage = $sentMessage ?? $sentSticker;
    }

    if ($sentMessage) {
        $totalReplyTime = isset($replyStartTime) ? round(microtime(true) - $replyStartTime, 2) : -1;
        $logData = ['user_id' => $userId];
        if ($enableTimeCount) {
            $sendTime = round(microtime(true) - $sendStartTime, 3);
            $logData['send_time'] = $sendTime . 's';
            $logData['message_length'] = mb_strlen($replyText);
        }
        $logger->info("成功发送最终AI回复，共用时 {$totalReplyTime} 秒", $logData, $chatId);
        
        if (isset($sentMessage['result']['message_id'])) {
            $botContent = $rawReply;
            if ($drawPrompt !== null && $generatedImage !== null) {
                $botContent .= "\n[已随回复发送一张生成的图片，提示词: {$drawPrompt}]";
            }
            if ($sentSticker) {
                $botContent .= "\n[已发送表情包 {$parsedReply['sticker']}]";
            }
            $botMessage = [
                'role' => 'assistant',
                'content' => $botContent,
                'uid' => $config['telegram']['bot_id'],
                'username' => $botUsername,
                'full_name' => 'Bot',
                'timestamp' => time(),
                'message_id' => $sentMessage['result']['message_id'],
                '_reply_to_message_id' => $finalReplyTargetId,
            ];
            
            $contextManager->addMessage($chatId, $botMessage);
            
            $logger->debug('Bot消息已保存到上下文', [
                'bot_message_id' => $sentMessage['result']['message_id'],
                'reply_to_message_id' => $finalReplyTargetId
            ], $chatId);
        } else {
            $logger->error('发送消息成功但未返回message_id', [
                'sent_message' => $sentMessage
            ], $chatId);
        }
    }
}


/**
 * 将上下文转换为回复模型可用的 messages 数组，并标记当前需要回复的消息
 */
function build_reply_messages(array $context): array
{
    $messages = [];
    foreach ($context as $msg) {
        $role = $msg['role'] ?? 'user';
        if ($role === 'system' || $role === 'assistant') {
            $content = (string)($msg['content'] ?? '');
            if ($content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
            continue;
        }

        $isCurrent = !empty($msg['_is_current_message']);
        unset($msg['_is_current_message'], $msg['role'], $msg['image_file_id'], $msg['image_status']);
        $content = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($isCurrent) {
            $content = "[CURRENT_MESSAGE_TO_REPLY]\n" . $content;
        }
        $messages[] = ['role' => 'user', 'content' => $content];
    }
    return $messages;
}

/**
 * 解析 judge 输出，兼容 JSON、<think> 推理过程和多余文字
 */
function parse_judge_action(string $raw): string
{
    $raw = preg_replace('/<think>.*?<\/think>/is', '', $raw);
    if (preg_match('/"ACTION"\s*:\s*"([A-Z_]+)"/i', $raw, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match_all('/\b(REPLY_PRO|REPLY|IGNORE|REJECT)\b/i', $raw, $m)) {
        return strtoupper(end($m[1]));
    }
    return trim(strtoupper($raw), " \t\n\r\0\x0B`");
}

/**
 * 消息是否点名 (昵称 / @用户名) 或回复了 bot
 */
function is_bot_addressed(array $formattedMessage, array $telegramConfig): bool
{
    $botId = (int)($telegramConfig['bot_id'] ?? 0);
    if ($botId !== 0 && (int)($formattedMessage['reply_to']['uid'] ?? 0) === $botId) {
        return true;
    }

    $text = mb_strtolower(($formattedMessage['text_content'] ?? '') . ' ' . ($formattedMessage['caption_content'] ?? ''));
    $names = $telegramConfig['bot_nicknames'] ?? [];
    $username = ltrim((string)($telegramConfig['bot_username'] ?? ''), '@');
    if ($username !== '') {
        $names[] = '@' . $username;
    }
    foreach ($names as $name) {
        $name = mb_strtolower(trim((string)$name));
        if ($name !== '' && str_contains($text, $name)) {
            return true;
        }
    }
    return false;
}

/**
 * Telegram 按 UTF-16 码元计算长度 (emoji 等占 2 个)
 */
function tg_length(string $text): int
{
    return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
}

function tg_truncate(string $text, int $limit): string
{
    if (tg_length($text) <= $limit) {
        return $text;
    }
    $result = '';
    $units = 0;
    foreach (mb_str_split($text) as $char) {
        $units += strlen($char) === 4 ? 2 : 1;
        if ($units > $limit) {
            break;
        }
        $result .= $char;
    }
    return $result;
}

function build_sticker_instructions(array $emojis): string
{
    return "## 表情包\n"
        . "可以在 <response> 里加一个 <sticker>emoji</sticker>，额外发一张表达该情绪的表情包，例如 <sticker>😂</sticker>。\n"
        . "像群友一样偶尔使用：闲聊、玩梗、吐槽时可以用，严肃问答或长回复不要用。只想发表情包时 reply_content 可以留空。\n"
        . "只能使用这些 emoji: " . implode(' ', $emojis);
}

/**
 * 根据配置返回当前可用的工具列表
 */
function get_available_tools(array $config, ToolService $toolService, ImageService $imageService): array
{
    $tools = [];
    if ($toolService->getSearchProvider() !== null) {
        $tools[] = 'SEARCH';
        if (!empty($config['worker']['curl_url'])) {
            $tools[] = 'FETCH';
        }
    }
    if ($imageService->isEnabled()) {
        $tools[] = 'DRAW';
    }
    return $tools;
}

function build_tool_instructions(array $tools): string
{
    $descriptions = [
        'SEARCH' => '- SEARCH: 联网搜索。涉及新闻、实时信息、价格、版本号或你不确定的事实时使用。query 为简洁的搜索关键词。',
        'FETCH' => '- FETCH: 抓取网页正文。query 为完整的 http(s) URL，可用于阅读搜索结果或用户发来的链接。',
        'DRAW' => '- DRAW: 生成一张图片，仅在用户明确要求画图/生成图片时使用。query 为详细的英文画面描述。',
    ];
    $lines = array_map(static fn($t) => $descriptions[$t], $tools);

    return "## 工具调用\n"
        . "需要使用工具时，本次只输出一个或多个工具调用，不要输出 <response>，格式如下 (每个工具调用一行)：\n"
        . '<tool_call>{"tool": "SEARCH", "query": "关键词"}</tool_call>' . "\n"
        . "系统执行后会以 [TOOL_RESULTS] 把结果发给你，你再按输出协议给出最终回复。单次最多调用 3 个工具，不需要工具时直接回复。\n"
        . "可用工具：\n" . implode("\n", $lines);
}

/**
 * 从模型输出中解析 <tool_call>，只保留可用工具，最多 3 个
 *
 * @return array<array{tool: string, query: string}>
 */
function parse_tool_calls(string $raw, array $availableTools): array
{
    if (!preg_match_all('/<tool_call>\s*(.*?)\s*<\/tool_call>/is', $raw, $matches)) {
        return [];
    }

    $calls = [];
    foreach ($matches[1] as $json) {
        $json = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $json);
        $data = json_decode($json, true);
        $tool = strtoupper((string)($data['tool'] ?? ''));
        $query = trim((string)($data['query'] ?? ''));
        if ($query !== '' && in_array($tool, $availableTools, true)) {
            $calls[] = ['tool' => $tool, 'query' => $query];
        }
    }
    return array_slice($calls, 0, 3);
}

/**
 * 解析回复模型输出的 XML 数据包，兼容模型未遵守协议直接输出纯文本的情况
 *
 * @return array{text: string, target: ?int, sticker: ?string}
 */
function parse_reply(string $raw): array
{
    $target = null;
    if (preg_match('/<target_message_id>\s*(-?\d+)\s*<\/target_message_id>/i', $raw, $m)) {
        $target = (int)$m[1];
    }

    $sticker = null;
    if (preg_match('/<sticker>\s*(.*?)\s*<\/sticker>/is', $raw, $m) && $m[1] !== '') {
        $sticker = $m[1];
    }

    if (preg_match('/<reply_content>\s*<!\[CDATA\[(.*?)\]\]>\s*<\/reply_content>/is', $raw, $m)
        || preg_match('/<reply_content>(.*?)<\/reply_content>/is', $raw, $m)) {
        $text = $m[1];
    } else {
        $text = preg_replace('/<(tool_call|sticker)>.*?<\/\1>/is', '', $raw);
        $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text));
        $text = strip_tags(preg_replace('/<target_message_id>.*?<\/target_message_id>/is', '', $text));
    }

    return ['text' => trim($text), 'target' => $target, 'sticker' => $sticker];
}
