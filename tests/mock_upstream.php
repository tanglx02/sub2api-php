<?php
/**
 * Mock 上游服务（测试专用，不进入发布包的运行路径）
 * 请求头 X-Mock-Scenario 控制行为：
 *   ok            正常返回（默认）
 *   invalid_key   401
 *   error_500     500
 *   timeout       挂起 6 秒
 *   drop          流式输出一半后断开
 *   no_usage      正常返回但无 usage 字段
 */
declare(strict_types=1);

$scenario = $_SERVER['HTTP_X_MOCK_SCENARIO'] ?? 'ok';
// 支持路径选择场景：/500 /401 /drop /nousage
$uriPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
foreach ([['/500', 'error_500'], ['/401', 'invalid_key'], ['/drop', 'drop'], ['/nousage', 'no_usage']] as [$pre, $sc]) {
    if (str_starts_with($uriPath, $pre)) { $scenario = $sc; break; }
}
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$stream = !empty($body['stream']);

if (in_array($uriPath, ['/v1/models', '/models', '/500/v1/models'], true)) {
    header('Content-Type: application/json');
    echo json_encode(['object' => 'list', 'data' => [['id' => 'mock-model', 'object' => 'model']]]);
    exit;
}

if ($scenario === 'invalid_key') { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error' => ['message' => 'Incorrect API key', 'type' => 'authentication_error']]); exit; }
if ($scenario === 'error_500') { http_response_code(500); echo json_encode(['error' => ['message' => 'mock upstream internal error']]); exit; }
if ($scenario === 'timeout') { sleep(6); echo json_encode(['error' => ['message' => 'mock timeout']]); exit; }

if (!$stream) {
    $usage = $scenario === 'no_usage' ? null : ['prompt_tokens' => 12, 'completion_tokens' => 34, 'total_tokens' => 46];
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'chatcmpl-mock', 'object' => 'chat.completion', 'created' => time(), 'model' => $body['model'] ?? 'mock',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'MOCK-REPLY'], 'finish_reason' => 'stop']],
        'usage' => $usage,
    ]);
    exit;
}

// SSE 流式
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
echo "data: " . json_encode(['id' => 'cm', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]]) . "\n\n";
flush();
echo "data: " . json_encode(['id' => 'cm', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => 'MOCK']]]]) . "\n\n";
flush();
if ($scenario === 'drop') {
    // 中途断开（不发送 usage 与 [DONE]）
    exit;
}
echo "data: " . json_encode(['id' => 'cm', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => '-STREAM']]]]) . "\n\n";
flush();
echo "data: " . json_encode(['id' => 'cm', 'object' => 'chat.completion.chunk', 'choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 34, 'total_tokens' => 46]]) . "\n\n";
flush();
echo "data: [DONE]\n\n";
flush();
