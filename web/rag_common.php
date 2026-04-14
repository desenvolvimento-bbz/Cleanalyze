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

/** Garante que o usuario autenticado e admin (role=admin). Retorna o email. */
function rag_require_admin(): string {
    auth_require_login();
    $email = auth_user_email();
    if (!$email) {
        rag_json_response(['ok' => false, 'error' => 'Usuario nao autenticado'], 401);
    }
    if (!function_exists('auth_is_admin') || !auth_is_admin()) {
        rag_json_response(['ok' => false, 'error' => 'Acesso negado: requer administrador'], 403);
    }
    return $email;
}

/**
 * Extrai o parametro `as_user` da request (GET, POST form ou JSON body).
 * Retorna string vazia se nao presente.
 */
function rag_extract_as_user(array $jsonBody = []): string {
    $candidates = [
        $_GET['as_user'] ?? null,
        $_POST['as_user'] ?? null,
        $jsonBody['as_user'] ?? null,
    ];
    foreach ($candidates as $v) {
        if (is_string($v) && trim($v) !== '') {
            return strtolower(trim($v));
        }
    }
    return '';
}

/**
 * Resolve o "usuario efetivo" da request levando em conta view-as.
 *
 * Regras:
 *  - Se nao ha `as_user` na request, retorna o email do proprio admin (comportamento normal)
 *  - Se ha `as_user` mas o chamador nao e admin, retorna 403 (tentativa de spoof)
 *  - Se ha `as_user` e o chamador e admin, valida que o email alvo existe em users.json,
 *    loga a acao como `rag.admin.view_as.<label>` e retorna o email alvo
 *
 * $readOnly e obrigatorio: apenas endpoints read-only podem aceitar as_user. Se um
 * endpoint destrutivo (upload/chat/delete) receber as_user, retorna 403.
 */
function rag_effective_user(string $actingEmail, bool $readOnly, string $auditLabel, array $jsonBody = []): string {
    $asUser = rag_extract_as_user($jsonBody);
    if ($asUser === '') {
        return $actingEmail;
    }

    // Rejeita em endpoints destrutivos — admin so pode OLHAR, nunca escrever como outro
    if (!$readOnly) {
        rag_json_response([
            'ok' => false,
            'error' => 'Operacoes de escrita nao sao permitidas em modo view-as',
        ], 403);
    }

    // So admin pode usar as_user
    if (!function_exists('auth_is_admin') || !auth_is_admin()) {
        rag_json_response([
            'ok' => false,
            'error' => 'Acesso negado: view-as requer administrador',
        ], 403);
    }

    // Alvo precisa existir em users.json
    $users = function_exists('users_load') ? users_load() : [];
    $foundKey = null;
    foreach (array_keys($users) as $k) {
        if (strtolower($k) === $asUser) {
            $foundKey = $k;
            break;
        }
    }
    if ($foundKey === null) {
        rag_json_response([
            'ok' => false,
            'error' => 'Usuario alvo nao encontrado',
        ], 404);
    }

    app_log('rag.admin.view_as.' . $auditLabel, [
        'admin' => $actingEmail,
        'target' => $foundKey,
    ]);

    return $foundKey;
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

/** --- Documentos BBZ (globais) --- */

function rag_global_dir(): string {
    return APP_UPLOADS . APP_SEP . 'rag' . APP_SEP . '_global';
}

function rag_global_files_dir(): string {
    return rag_global_dir() . APP_SEP . 'files';
}

function rag_global_db(): string {
    return rag_global_dir() . APP_SEP . 'index.db';
}

/**
 * Lista Documentos BBZ acessiveis para um email. Lida bem com DB inexistente
 * (retorna array vazio).
 */
function rag_list_global_docs_for(string $email): array {
    $dbPath = rag_global_db();
    if (!is_file($dbPath)) return [];
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare(
            "SELECT d.doc_id, d.filename, d.pages, d.status, d.error_message, d.created_at "
            . "FROM documents d "
            . "INNER JOIN document_access a ON a.doc_id = d.doc_id "
            . "WHERE a.email = :email "
            . "ORDER BY d.created_at DESC"
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Busca metadados de um documento global pelo id (sem check de ACL). */
function rag_get_global_doc(string $docId): ?array {
    $dbPath = rag_global_db();
    if (!is_file($dbPath)) return null;
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE doc_id = :id");
        $stmt->execute([':id' => $docId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Verifica se um email tem acesso a um doc global. */
function rag_user_has_global_access(string $docId, string $email): bool {
    $dbPath = rag_global_db();
    if (!is_file($dbPath)) return false;
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare(
            "SELECT 1 FROM document_access WHERE doc_id = :id AND email = :email LIMIT 1"
        );
        $stmt->execute([':id' => $docId, ':email' => strtolower(trim($email))]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
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
