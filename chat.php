<?php
// Desativa compressão do Apache/PHP e desliga buffers para streaming em tempo real
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

require 'db.php';

set_time_limit(0);

$prompt = $_POST['prompt'] ?? '';
$conversation_id = $_POST['conversation_id'] ?? null;

if (!$prompt || !$conversation_id) {
    header('Content-Type: application/json');
    echo json_encode(["error" => "Prompt ou ID da conversa ausentes"]);
    exit;
}

// 1. Recuperar contexto relevante (RAG Simplificado)
$words = explode(' ', preg_replace('/[^a-zA-Z0-9áàâãéèêíïóôõöúçñ ]/u', '', $prompt));
$keywords = array_filter($words, function($w) { return mb_strlen($w) > 3; });

$context_extra = "";
if (!empty($keywords)) {
    $clauses = [];
    $params = [];
    foreach ($keywords as $word) {
        $clauses[] = "content LIKE ?";
        $params[] = "%$word%";
    }
    
    $sql_rag = "SELECT content FROM messages 
                WHERE conversation_id = ? 
                AND content != ?
                AND (" . implode(" OR ", $clauses) . ") 
                ORDER BY created_at DESC LIMIT 3";
    
    $stmt_rag = $pdo->prepare($sql_rag);
    $stmt_rag->execute(array_merge([$conversation_id, $prompt], $params));
    $related_messages = $stmt_rag->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($related_messages)) {
        $cleaned_related = [];
        foreach ($related_messages as $rm) {
            $cleaned = trim(preg_replace('/<think>[\s\S]*?<\/think>/i', '', $rm));
            if (!empty($cleaned)) {
                $cleaned_related[] = $cleaned;
            }
        }
        if (!empty($cleaned_related)) {
            $context_extra = "Informações relevantes de mensagens anteriores:\n- " . implode("\n- ", $cleaned_related) . "\n\n";
        }
    }
}

// 2. Recuperar o modelo selecionado
$model = $_POST['model'] ?? 'gpt-4o';

// 3. Salva a mensagem do usuário no banco
$stmt = $pdo->prepare("INSERT INTO messages (conversation_id, role, model, content) VALUES (?, 'user', ?, ?)");
$stmt->execute([$conversation_id, $model, $prompt]);

// Atualiza o título da conversa se for a primeira mensagem
$stmt_title = $pdo->prepare("UPDATE conversations SET title = SUBSTRING(?, 1, 30) WHERE id = ? AND title = 'Nova Conversa'");
$stmt_title->execute([$prompt, $conversation_id]);

// 4. Recuperar histórico recente (últimas 6 mensagens)
$stmt_hist = $pdo->prepare("SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 6");
$stmt_hist->execute([$conversation_id]);
$history = array_reverse($stmt_hist->fetchAll());

// Parâmetro para ativar ou desativar o raciocínio
$think_param = $_POST['think'] ?? '1';
$think_enabled = ($think_param === '1' || $think_param === 'true');

// Identifica se é modelo da família de raciocínio (o1, o3, deepseek-r1)
$is_o_series = (bool)preg_match('/^(o1|o3)/i', $model);
$is_reasoning_model = $is_o_series || (stripos($model, 'r1') !== false) || (stripos($model, 'reasoner') !== false);

// 5. Montar as mensagens para a API OpenAI
$messages = [];
// Modelos o1/o3 utilizam papel 'developer', outros utilizam 'system'
$system_role = $is_o_series ? 'developer' : 'system';

if (!empty($context_extra)) {
    $messages[] = ["role" => $system_role, "content" => "Você é um assistente útil. Use estas " . $context_extra];
} else if ($think_enabled) {
    $messages[] = ["role" => $system_role, "content" => "Você é um assistente prestativo e inteligente."];
} else {
    $messages[] = ["role" => $system_role, "content" => "Você é um assistente útil e direto ao ponto. Responda de forma concisa e sem raciocínio prévio."];
}

foreach ($history as $msg) {
    $clean_content = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $msg['content']);
    $clean_content = trim($clean_content);
    $messages[] = ["role" => $msg['role'], "content" => !empty($clean_content) ? $clean_content : $msg['content']];
}

// 6. Montar o payload no padrão OpenAI /v1/chat/completions
$url = "$openai_base_url/chat/completions";
$data = [
    "model" => $model,
    "messages" => $messages,
    "stream" => true,
    "stream_options" => [
        "include_usage" => true
    ]
];

// Para modelos que suportam parâmetro reasoning_effort (como o1, o3-mini)
if ($is_o_series) {
    $data["reasoning_effort"] = $think_enabled ? "medium" : "low";
}

$headers = [
    'Content-Type: application/json'
];
if (!empty($openai_api_key)) {
    $headers[] = 'Authorization: Bearer ' . $openai_api_key;
}

// Configura cabeçalhos SSE para streaming
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Content-Encoding: none');

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_implicit_flush(1);

// Padding inicial para flush imediato do Apache/Nginx
echo ":" . str_repeat(" ", 2048) . "\n\n";
flush();

$full_thinking = "";
$full_content = "";
$final_metrics = null;
$start_time = microtime(true);
$first_token_time = null;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 180);
curl_setopt($ch, CURLOPT_TCP_NODELAY, 1);
curl_setopt($ch, CURLOPT_BUFFERSIZE, 128);

$buffer = "";
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (
    &$full_thinking, &$full_content, &$final_metrics, &$buffer, &$start_time, &$first_token_time
) {
    // Repassa o chunk diretamente ao cliente SSE
    echo $chunk;
    if (ob_get_level() > 0) ob_flush();
    flush();
    
    $buffer .= $chunk;
    $lines = explode("\n", $buffer);
    $buffer = array_pop($lines);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, ':') === 0) continue;
        
        $dataStr = $line;
        if (strpos($line, 'data: ') === 0) {
            $dataStr = trim(substr($line, 6));
        }
        
        if ($dataStr === '[DONE]') {
            continue;
        }
        
        $json = json_decode($dataStr, true);
        if ($json && is_array($json)) {
            // 1. Processa escolhas e deltas da resposta OpenAI
            if (isset($json['choices'][0]['delta'])) {
                if ($first_token_time === null) {
                    $first_token_time = microtime(true);
                }
                
                $delta = $json['choices'][0]['delta'];
                
                // Suporte para pensamento / raciocínio (OpenAI-compatible / DeepSeek / o1 / o3)
                $think_chunk = $delta['reasoning_content'] ?? $delta['reasoning'] ?? $delta['thought'] ?? '';
                if ($think_chunk !== '') {
                    $full_thinking .= $think_chunk;
                }
                
                // Conteúdo normal da resposta
                if (isset($delta['content']) && $delta['content'] !== '') {
                    $full_content .= $delta['content'];
                }
            }
            
            // 2. Processa estatísticas de uso (stream_options: include_usage)
            if (isset($json['usage'])) {
                $now = microtime(true);
                $total_sec = round($now - $start_time, 2);
                $gen_sec = ($first_token_time !== null) ? round($now - $first_token_time, 2) : $total_sec;
                
                $prompt_tokens = $json['usage']['prompt_tokens'] ?? 0;
                $completion_tokens = $json['usage']['completion_tokens'] ?? 0;
                $total_tokens = $json['usage']['total_tokens'] ?? 0;
                
                $tok_per_sec = ($gen_sec > 0.05 && $completion_tokens > 0) ? round($completion_tokens / $gen_sec, 1) : 0;
                
                $final_metrics = json_encode([
                    'eval_count' => $completion_tokens,
                    'prompt_eval_count' => $prompt_tokens,
                    'total_tokens' => $total_tokens,
                    'total_sec' => $total_sec,
                    'tok_per_sec' => $tok_per_sec
                ]);
            }
        }
    }
    return strlen($chunk);
});

$exec_result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($exec_result === false || $httpCode >= 400) {
    $error = curl_error($ch);
    if ($httpCode >= 400 && empty($error)) {
        $error = "HTTP $httpCode retornado pela API OpenAI";
    }
    $err_json = json_encode(["error" => "Erro na API OpenAI: $error"]);
    echo "data: $err_json\n\n";
    flush();
}

curl_close($ch);

// 7. Salva a resposta da IA no banco de dados
$full_response = "";
if (!empty($full_thinking)) {
    if (strpos($full_content, '<think>') === false) {
        $full_response = "<think>\n" . trim($full_thinking) . "\n</think>\n\n" . $full_content;
    } else {
        $full_response = $full_content;
    }
} else {
    $full_response = $full_content;
}

if (!empty($full_response)) {
    try {
        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, role, model, content, metrics) VALUES (?, 'assistant', ?, ?, ?)");
        $stmt->execute([$conversation_id, $model, $full_response, $final_metrics]);
    } catch (\PDOException $e) {
        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, role, model, content) VALUES (?, 'assistant', ?, ?)");
        $stmt->execute([$conversation_id, $model, $full_response]);
    }
}
?>