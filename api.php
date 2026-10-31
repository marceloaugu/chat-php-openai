<?php
define('API_ALLOW_NO_DB', true);
require 'db.php';

$action = $_GET['action'] ?? '';

header('Content-Type: application/json');

/**
 * Executa requisição HTTP (GET ou POST) compatível com ambientes com ou sem extensão cURL (ex: XAMPP local)
 * seguindo o padrão OpenAI (/v1) com header Authorization: Bearer
 */
function openai_http_request($url, $apiKey = '', $postData = null, $connectTimeout = 2, $timeout = 4) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($postData) ? $postData : json_encode($postData));
        }
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['body' => $response, 'http_code' => $httpCode, 'error' => $err];
    }

    // Fallback nativo via PHP Streams caso php_curl não esteja ativo no PHP/Apache
    $method = ($postData !== null) ? 'POST' : 'GET';
    $headerLines = "Accept: application/json\r\n";
    if (!empty($apiKey)) {
        $headerLines .= "Authorization: Bearer " . $apiKey . "\r\n";
    }
    $httpOpts = [
        'method' => $method,
        'timeout' => $timeout,
        'ignore_errors' => true,
        'header' => $headerLines
    ];
    if ($postData !== null) {
        $payload = is_string($postData) ? $postData : json_encode($postData);
        $httpOpts['header'] .= "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n";
        $httpOpts['content'] = $payload;
    }

    $context = stream_context_create(['http' => $httpOpts]);
    $response = @file_get_contents($url, false, $context);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $hdr) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
                $httpCode = (int)$m[1];
            }
        }
    }
    $err = ($response === false) ? 'Falha de conexão HTTP stream' : '';
    return ['body' => $response, 'http_code' => $httpCode, 'error' => $err];
}

/**
 * Tenta requisição no endpoint configurado (/v1/...) e faz fallback entre 127.0.0.1 e localhost se necessário
 */
function openai_request_with_fallback($endpoint, $openai_base_url, $openai_api_key, $postData = null, $connectTimeout = 2, $timeout = 4) {
    $url = rtrim($openai_base_url, '/') . $endpoint;
    $res = openai_http_request($url, $openai_api_key, $postData, $connectTimeout, $timeout);

    if ($res['http_code'] === 0 || $res['body'] === false) {
        $alt_base = null;
        if (stripos($openai_base_url, '127.0.0.1') !== false) {
            $alt_base = str_ireplace('127.0.0.1', 'localhost', $openai_base_url);
        } elseif (stripos($openai_base_url, 'localhost') !== false) {
            $alt_base = str_ireplace('localhost', '127.0.0.1', $openai_base_url);
        }
        if ($alt_base) {
            $alt_res = openai_http_request(rtrim($alt_base, '/') . $endpoint, $openai_api_key, $postData, $connectTimeout, $timeout);
            if ($alt_res['http_code'] > 0 && $alt_res['body'] !== false) {
                return $alt_res;
            }
        }
    }
    return $res;
}

if ($action === 'list_conversations') {
    if (!$pdo) {
        echo json_encode([]);
        exit;
    }
    $stmt = $pdo->query("SELECT * FROM conversations ORDER BY created_at DESC");
    echo json_encode($stmt->fetchAll());
} 

elseif ($action === 'get_messages') {
    if (!$pdo) {
        echo json_encode([]);
        exit;
    }
    $conv_id = $_GET['conversation_id'] ?? 0;
    $stmt = $pdo->prepare("SELECT role, content, model, metrics, created_at FROM messages WHERE conversation_id = ? ORDER BY created_at ASC");
    $stmt->execute([$conv_id]);
    echo json_encode($stmt->fetchAll());
} 

elseif ($action === 'new_conversation') {
    if (!$pdo) {
        echo json_encode(['error' => $db_error ?? 'Banco de dados indisponível']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO conversations (title) VALUES ('Nova Conversa')");
    $stmt->execute();
    echo json_encode(['id' => $pdo->lastInsertId()]);
} 

elseif ($action === 'rename_conversation') {
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Banco de dados indisponível']);
        exit;
    }
    $conv_id = (int)($_POST['conversation_id'] ?? $_GET['conversation_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    if ($conv_id > 0 && $title !== '') {
        if (mb_strlen($title) > 120) {
            $title = mb_substr($title, 0, 120);
        }
        $stmt = $pdo->prepare("UPDATE conversations SET title = ? WHERE id = ?");
        $stmt->execute([$title, $conv_id]);
        echo json_encode(['success' => true, 'title' => $title]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    }
}

elseif ($action === 'delete_conversation') {
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Banco de dados indisponível']);
        exit;
    }
    $conv_id = (int)($_POST['conversation_id'] ?? $_GET['conversation_id'] ?? 0);
    if ($conv_id > 0) {
        $stmtMsg = $pdo->prepare("DELETE FROM messages WHERE conversation_id = ?");
        $stmtMsg->execute([$conv_id]);
        $stmt = $pdo->prepare("DELETE FROM conversations WHERE id = ?");
        $stmt->execute([$conv_id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
    }
} 

elseif ($action === 'check_status') {
    // 1. Consulta padrão OpenAI: GET /v1/models
    $res = openai_request_with_fallback('/models', $openai_base_url, $openai_api_key, null, 2, 4);
    $httpCode = $res['http_code'];

    // 2. Se falhar, tenta endpoint alternativo /health na raiz
    if ($httpCode !== 200) {
        $root_url = preg_replace('/\/v\d+\/?$/i', '', $openai_base_url);
        $res2 = openai_http_request("$root_url/health", $openai_api_key, null, 1, 2);
        if ($res2['http_code'] === 200) {
            $httpCode = 200;
        }
    }

    $online = ($httpCode === 200);
    echo json_encode([
        'online' => $online,
        'http_code' => $httpCode,
        'base_url' => $openai_base_url,
        'db_online' => ($pdo !== null)
    ]);
} 

elseif ($action === 'list_models') {
    // Consulta padrão OpenAI: GET /v1/models
    $res = openai_request_with_fallback('/models', $openai_base_url, $openai_api_key, null, 3, 5);
    $httpCode = $res['http_code'];
    $response = $res['body'];

    if ($httpCode === 200 && $response !== false) {
        $data = json_decode($response, true);
        if (isset($data['data']) && is_array($data['data'])) {
            echo $response;
            exit;
        }
    }

    // Lista padrão se não houver modelos listados pelo endpoint
    echo json_encode([
        'object' => 'list',
        'data' => []
    ]);
} 

elseif ($action === 'list_running_models') {
    echo json_encode(['models' => []]);
} 

elseif ($action === 'run_model') {
    $selected_model = $_POST['model'] ?? $_GET['model'] ?? '';
    echo json_encode([
        'success' => true,
        'loaded' => $selected_model,
        'unloaded' => []
    ]);
}
?>