<?php
require 'db.php';

$action = $_GET['action'] ?? '';

header('Content-Type: application/json');

if ($action === 'list_conversations') {
    $stmt = $pdo->query("SELECT * FROM conversations ORDER BY created_at DESC");
    echo json_encode($stmt->fetchAll());
} 

elseif ($action === 'get_messages') {
    $conv_id = $_GET['conversation_id'] ?? 0;
    $stmt = $pdo->prepare("SELECT role, content, model, metrics, created_at FROM messages WHERE conversation_id = ? ORDER BY created_at ASC");
    $stmt->execute([$conv_id]);
    echo json_encode($stmt->fetchAll());
} 

elseif ($action === 'new_conversation') {
    $stmt = $pdo->prepare("INSERT INTO conversations (title) VALUES ('Nova Conversa')");
    $stmt->execute();
    echo json_encode(['id' => $pdo->lastInsertId()]);
} 

elseif ($action === 'delete_conversation') {
    $conv_id = $_POST['conversation_id'] ?? 0;
    $stmt = $pdo->prepare("DELETE FROM conversations WHERE id = ?");
    $stmt->execute([$conv_id]);
    echo json_encode(['success' => true]);
} 

elseif ($action === 'check_status') {
    // 1. Tenta $openai_base_url/models
    $url = "$openai_base_url/models";
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if (!empty($openai_api_key)) {
        $headers[] = 'Authorization: Bearer ' . $openai_api_key;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // 2. Se falhar, tenta endpoint alternativo /health ou sem /v1
    if ($httpCode !== 200) {
        $root_url = preg_replace('/\/v1\/?$/i', '', $openai_base_url);
        $ch2 = curl_init("$root_url/health");
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 2);
        curl_exec($ch2);
        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($code2 === 200) {
            $httpCode = 200;
        }
    }
    
    $online = ($httpCode === 200);
    echo json_encode([
        'online' => $online,
        'http_code' => $httpCode,
        'base_url' => $openai_base_url
    ]);
} 

elseif ($action === 'list_models') {
    $url = "$openai_base_url/models";
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if (!empty($openai_api_key)) {
        $headers[] = 'Authorization: Bearer ' . $openai_api_key;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response !== false) {
        $data = json_decode($response, true);
        if (isset($data['data']) && is_array($data['data'])) {
            echo $response;
            exit;
        }
    }
    
    // Tenta fallback sem /v1 se houver
    if (strpos($openai_base_url, '/v1') !== false) {
        $root_url = preg_replace('/\/v1\/?$/i', '', $openai_base_url);
        $ch2 = curl_init("$root_url/models");
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 3);
        $alt_res = curl_exec($ch2);
        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($code2 === 200 && $alt_res !== false) {
            echo $alt_res;
            exit;
        }
    }
    
    // Lista padrão se não houver modelos listados pelo endpoint
    echo json_encode([
        'object' => 'list',
        'data' => [
            ['id' => 'default'],
            ['id' => 'deepseek-r1'],
            ['id' => 'gpt-4o'],
            ['id' => 'gpt-4o-mini']
        ]
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