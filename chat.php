<?php
// Desativa compressão do Apache/PHP e desliga buffers para streaming em tempo real
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

require 'db.php';

set_time_limit(0);
ignore_user_abort(true);

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

// Identifica se é modelo da família de raciocínio (o1, o3, o4, deepseek-r1, qwen3/qwq)
$is_o_series = (bool)preg_match('/^(o1|o3|o4)/i', $model);
$is_qwen_reasoning = (stripos($model, 'qwen3') !== false) || (stripos($model, 'qwen-3') !== false) || (stripos($model, 'qwq') !== false);
$is_reasoning_model = $is_o_series || $is_qwen_reasoning || (stripos($model, 'r1') !== false) || (stripos($model, 'reasoner') !== false) || (stripos($model, 'reasoning') !== false) || (stripos($model, 'thinking') !== false);

// 5. Montar as mensagens para a API OpenAI
$messages = [];
// Modelos o1/o3 utilizam papel 'developer', outros utilizam 'system'
$system_role = $is_o_series ? 'developer' : 'system';
$qwen_switch = ($is_qwen_reasoning && !$think_enabled) ? " /no_think" : "";

if (!empty($context_extra)) {
    $messages[] = ["role" => $system_role, "content" => "Você é um assistente útil. Use estas " . $context_extra . $qwen_switch];
} else if ($think_enabled) {
    $messages[] = ["role" => $system_role, "content" => "Você é um assistente prestativo e inteligente."];
} else {
    $messages[] = ["role" => $system_role, "content" => "Você é um assistente útil e direto ao ponto. Responda de forma concisa e sem raciocínio prévio." . $qwen_switch];
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
$streamed_tokens_count = 0;
$buffer = "";

$processLine = function($rawLine) use (
    &$full_thinking, &$full_content, &$final_metrics, &$start_time, &$first_token_time, &$streamed_tokens_count
) {
    $line = trim($rawLine);
    if ($line === '' || strpos($line, ':') === 0) return;

    // Repassa a linha SSE ao cliente com padding de comentário SSE para forçar flush imediato no Apache/Windows
    echo $line . "\n";
    echo ":" . str_repeat(" ", 2048) . "\n\n";
    if (ob_get_level() > 0) @ob_flush();
    flush();

    $dataStr = $line;
    if (strpos($line, 'data:') === 0) {
        $dataStr = trim(substr($line, 5));
    }

    if ($dataStr === '[DONE]') {
        return;
    }

    $json = json_decode($dataStr, true);
    if ($json && is_array($json)) {
        // 1. Processa escolhas e deltas da resposta OpenAI (/v1/chat/completions)
        if (isset($json['choices'][0]['delta'])) {
            if ($first_token_time === null) {
                $first_token_time = microtime(true);
            }

            $delta = $json['choices'][0]['delta'];

            // Suporte para pensamento / raciocínio (OpenAI-compatible / DeepSeek / Ollama / Qwen / o1 / o3)
            $think_chunk = $delta['reasoning_content'] ?? $delta['reasoning'] ?? $delta['thought'] ?? $delta['thinking'] ?? '';
            if ($think_chunk !== '') {
                $full_thinking .= $think_chunk;
                $streamed_tokens_count++;
            }

            // Conteúdo normal da resposta
            if (isset($delta['content']) && $delta['content'] !== '') {
                $full_content .= $delta['content'];
                $streamed_tokens_count++;
            }
        }

        // 2. Processa estatísticas de uso (stream_options: include_usage)
        if (isset($json['usage']) && is_array($json['usage'])) {
            $now = microtime(true);
            $total_sec = round($now - $start_time, 2);
            $gen_sec = ($first_token_time !== null) ? round($now - $first_token_time, 2) : $total_sec;

            $prompt_tokens = $json['usage']['prompt_tokens'] ?? 0;
            $completion_tokens = $json['usage']['completion_tokens'] ?? $streamed_tokens_count;
            $total_tokens = $json['usage']['total_tokens'] ?? ($prompt_tokens + $completion_tokens);

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
};

$processChunk = function($chunk) use (&$buffer, $processLine) {
    $buffer .= $chunk;
    $lines = explode("\n", $buffer);
    $buffer = array_pop($lines);
    foreach ($lines as $line) {
        $processLine($line);
    }
};

$flushRemainingBuffer = function() use (&$buffer, $processLine) {
    if (trim($buffer) !== '') {
        $rem = $buffer;
        $buffer = '';
        $processLine($rem);
    }
};

$payload = json_encode($data);

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_TCP_NODELAY, 1);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 128);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use ($processChunk) {
        $processChunk($chunk);
        return strlen($chunk);
    });

    $exec_result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $flushRemainingBuffer();

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
} else {
    // Fallback via socket TCP/SSL direto sem buffer de 8KB para streaming instantâneo no XAMPP sem cURL
    $parsed = parse_url($url);
    $scheme = strtolower($parsed['scheme'] ?? 'http');
    $host = $parsed['host'] ?? '127.0.0.1';
    $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
    $path = ($parsed['path'] ?? '/v1/chat/completions') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    $transport = ($scheme === 'https') ? 'ssl' : 'tcp';

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client("$transport://$host:$port", $errno, $errstr, 10);

    if (!$fp && ($host === '127.0.0.1' || $host === 'localhost')) {
        $altHost = ($host === '127.0.0.1') ? 'localhost' : '127.0.0.1';
        $fp = @stream_socket_client("$transport://$altHost:$port", $errno, $errstr, 10);
    }

    if (!$fp) {
        $err_json = json_encode(["error" => "Erro ao conectar na API ($url): $errstr ($errno)"]);
        echo "data: $err_json\n\n";
        flush();
    } else {
        stream_set_timeout($fp, 300);
        if (function_exists('stream_set_read_buffer')) {
            @stream_set_read_buffer($fp, 0);
        }
        if (function_exists('stream_set_chunk_size')) {
            @stream_set_chunk_size($fp, 64);
        }

        // HTTP/1.0 evita chunked transfer encoding e entrega cada linha data: imediatamente
        $authHeader = !empty($openai_api_key) ? "Authorization: Bearer $openai_api_key\r\n" : "";
        $req = "POST $path HTTP/1.0\r\n"
             . "Host: $host:$port\r\n"
             . "Content-Type: application/json\r\n"
             . $authHeader
             . "Content-Length: " . strlen($payload) . "\r\n"
             . "Connection: close\r\n\r\n"
             . $payload;
        fwrite($fp, $req);

        // Lê cabeçalhos HTTP da resposta
        $httpCode = 0;
        while (!feof($fp)) {
            $hdrLine = fgets($fp, 1024);
            if ($hdrLine === false) break;
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdrLine, $m)) {
                $httpCode = (int)$m[1];
            }
            if (rtrim($hdrLine, "\r\n") === '') {
                break; // Fim dos headers HTTP
            }
        }

        // Lê o corpo linha por linha em tempo real
        $rawErrorBody = '';
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            if ($line === false) break;
            if ($httpCode >= 400) {
                $rawErrorBody .= $line;
            } else {
                $processChunk($line);
            }
        }
        $flushRemainingBuffer();
        fclose($fp);

        if ($httpCode >= 400) {
            $decodedErr = json_decode($rawErrorBody, true);
            $errMsg = $decodedErr['error']['message'] ?? $decodedErr['error'] ?? ("HTTP $httpCode retornado pela API");
            $err_json = json_encode(["error" => "Erro na API OpenAI: $errMsg"]);
            echo "data: $err_json\n\n";
            flush();
        }
    }
}

// Se o servidor não enviou o bloco 'usage' no stream, calcula métricas com base nos tokens recebidos
if ($final_metrics === null && ($full_content !== '' || $full_thinking !== '')) {
    $now = microtime(true);
    $total_sec = round($now - $start_time, 2);
    $gen_sec = ($first_token_time !== null) ? round($now - $first_token_time, 2) : $total_sec;
    $completion_tokens = max(1, $streamed_tokens_count);
    $tok_per_sec = ($gen_sec > 0.05) ? round($completion_tokens / $gen_sec, 1) : 0;

    $final_metrics = json_encode([
        'eval_count' => $completion_tokens,
        'prompt_eval_count' => 0,
        'total_tokens' => $completion_tokens,
        'total_sec' => $total_sec,
        'tok_per_sec' => $tok_per_sec
    ]);

    // Envia evento de usage sintetizado para o frontend exibir as métricas ao finalizar
    $usageEvent = json_encode([
        'usage' => [
            'prompt_tokens' => 0,
            'completion_tokens' => $completion_tokens,
            'total_tokens' => $completion_tokens
        ]
    ]);
    echo "data: $usageEvent\n\n";
    echo "data: [DONE]\n\n";
    flush();
}

// 7. Salva a resposta da IA no banco de dados
$full_response = "";
if (!empty($full_thinking)) {
    if (stripos($full_content, '<think>') === false && stripos($full_content, '</think>') === false) {
        $full_response = "<think>\n" . trim($full_thinking) . "\n</think>\n\n" . $full_content;
    } else {
        $full_response = $full_content;
    }
} elseif (stripos($full_content, '<think>') === false && stripos($full_content, '</think>') !== false) {
    // Caso o servidor já tenha incluído <think> no template de prompt e emitido apenas ...</think>
    $full_response = "<think>\n" . ltrim($full_content);
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