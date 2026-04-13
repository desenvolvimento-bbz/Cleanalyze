<?php
/**
 * web/rag_common.php
 * Funcoes utilitarias compartilhadas pelos endpoints do RAG.
 */

require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/../config/paths.php';

/** Retorna JSON e finaliza a execucao. */
function rag_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Garante autenticacao e retorna o email. Usado por todos os endpoints RAG. */
function rag_require_user(): string {
    auth_require_login();
    $email = auth_user_email();
    if (!$email) {
        rag_json_response(['ok' => false, 'error' => 'Usuario nao autenticado'], 401);
    }
    return $email;
}

/** Sanitiza o email para nome de diretorio (alinha com RagStore.sanitize_email). */
function rag_sanitize_email(string $email): string {
    $lower = strtolower(trim($email));
    $safe = preg_replace('/[^a-z0-9]+/', '_', $lower);
    $safe = trim($safe, '_');
    if ($safe === '') {
        rag_json_response(['ok' => false, 'error' => 'Email invalido'], 400);
    }
    return $safe;
}

/** Diretorio raiz do RAG do usuario. */
function rag_user_dir(string $email): string {
    return APP_UPLOADS . APP_SEP . 'rag' . APP_SEP . rag_sanitize_email($email);
}

function rag_user_files_dir(string $email): string {
    return rag_user_dir($email) . APP_SEP . 'files';
}

function rag_user_db(string $email): string {
    return rag_user_dir($email) . APP_SEP . 'index.db';
}

/** Gera UUID v4 (para doc_id). */
function rag_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** Valida que uma string e um UUID v4 ao formato esperado. */
function rag_is_uuid(string $s): bool {
    return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $s);
}

/**
 * Executa um script Python do projeto com argumentos escapados e retorna
 * um array ['code' => int, 'stdout' => string, 'stderr' => string].
 */
function rag_run_python(string $scriptRelativePath, array $args, ?string $stdin = null): array {
    $python = APP_PYTHON;
    $script = APP_BASE . APP_SEP . $scriptRelativePath;

    // Windows e Linux usam escapeshellarg corretamente
    $parts = [escapeshellarg($python), escapeshellarg($script)];
    foreach ($args as $a) {
        $parts[] = escapeshellarg((string) $a);
    }
    $cmd = implode(' ', $parts);

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $cwd = APP_BASE;
    $env = null; // herda do pai (inclui vars do .env carregadas pelo Apache/CLI)
    $proc = proc_open($cmd, $descriptor, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'proc_open falhou'];
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return ['code' => $code, 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
}

/** Decodifica o JSON gerado pelos CLIs do RAG (ultima linha nao vazia). */
function rag_decode_cli_json(string $stdout): ?array {
    $lines = preg_split('/\r?\n/', trim($stdout));
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim($lines[$i]);
        if ($line === '') continue;
        $data = json_decode($line, true);
        if (is_array($data)) return $data;
    }
    return null;
}

/**
 * Lista documentos do usuario lendo direto do SQLite (para evitar spawn do Python).
 * Retorna array de dicts ou [] se ainda nao houver DB.
 */
function rag_list_documents_php(string $email): array {
    $dbPath = rag_user_db($email);
    if (!is_file($dbPath)) return [];
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->query(
            "SELECT doc_id, filename, pages, status, error_message, created_at "
            . "FROM documents ORDER BY created_at DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Historico de chat de um documento. */
function rag_get_chat_history_php(string $email, string $docId, int $limit = 200): array {
    $dbPath = rag_user_db($email);
    if (!is_file($dbPath)) return [];
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare(
            "SELECT role, content, sources_json, created_at FROM chat_history "
            . "WHERE doc_id=:doc ORDER BY created_at ASC LIMIT :lim"
        );
        $stmt->bindValue(':doc', $docId, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['sources'] = $r['sources_json'] ? json_decode($r['sources_json'], true) : null;
            unset($r['sources_json']);
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/** Remove recursivamente um diretorio. */
function rag_rrmdir(string $path): void {
    if (!is_dir($path)) return;
    $items = scandir($path);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($p)) {
            rag_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($path);
}
