<?php
// Carrega variáveis do arquivo .env se existir (sem dependência externa)
if (file_exists(__DIR__ . '/.env')) {
    $env_lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            putenv("$key=$val");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

// Configurações do Banco de Dados
$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$db   = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'chat_app';
$user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'ubuntu';
$pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
if ($pass === false || $pass === null) {
    $pass = '';
}
$charset = 'utf8mb4';

// Configurações da API OpenAI (ou endpoint compatível padrão /v1)
$openai_api_key = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: 'ollama';
$openai_base_url = $_ENV['OPENAI_BASE_URL'] ?? getenv('OPENAI_BASE_URL') ?: 'http://127.0.0.1:11434/v1';
$openai_base_url = rtrim($openai_base_url, '/');
if (!preg_match('#/v\d+$#i', $openai_base_url)) {
    $openai_base_url .= '/v1';
}

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

function connect_and_setup_db($host, $db, $user, $pass, $charset, $options) {
    // Tenta se conectar diretamente ao banco primeiro
    try {
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        if ($e->getCode() == 1045) {
            throw $e;
        }
        // Se falhar (ex: banco de dados ainda não existe), tenta o setup automático
        $dsn_setup = "mysql:host=$host;charset=$charset";
        $pdo = new PDO($dsn_setup, $user, $pass, $options);

        // Cria banco de dados se não existir
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET $charset COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$db`");
    }

    // Cria tabela conversations se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS conversations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) DEFAULT 'Nova Conversa',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Cria tabela messages se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT,
        role ENUM('user', 'assistant') NOT NULL,
        model VARCHAR(100) DEFAULT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
    )");

    // Garante que a coluna 'model' existe na tabela 'messages'
    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'model'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN model VARCHAR(100) DEFAULT NULL AFTER role");
    }

    // Garante que a coluna 'metrics' existe na tabela 'messages'
    $stmt_m = $pdo->query("SHOW COLUMNS FROM messages LIKE 'metrics'");
    if (!$stmt_m->fetch()) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN metrics TEXT DEFAULT NULL AFTER content");
    }

    return $pdo;
}

$pdo = null;
$db_error = null;

try {
    $pdo = connect_and_setup_db($host, $db, $user, $pass, $charset, $options);
} catch (\PDOException $e) {
    // Fallback automático para ambiente local XAMPP (root sem senha) caso o usuário do .env não exista localmente
    if ($e->getCode() == 1045 && $user !== 'root' && ($host === 'localhost' || $host === '127.0.0.1')) {
        try {
            $pdo = connect_and_setup_db($host, $db, 'root', '', $charset, $options);
        } catch (\PDOException $e2) {
            $db_error = $e2->getMessage();
        }
    } else {
        $db_error = $e->getMessage();
    }

    if (!$pdo && !defined('API_ALLOW_NO_DB')) {
        die("Erro ao conectar ou configurar o banco de dados: " . $db_error);
    }
}
?>