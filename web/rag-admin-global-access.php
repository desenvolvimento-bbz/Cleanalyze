<?php
/**
 * web/rag-admin-global-access.php
 * Admin — gerencia acesso de usuarios a um Documento BBZ.
 *
 * POST JSON:
 *   { "action": "grant",  "doc_id": "...", "email": "foo@bar" }
 *   { "action": "revoke", "doc_id": "...", "email": "foo@bar" }
 *   { "action": "list",   "doc_id": "..." }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/rag_common.php';

$adminEmail = rag_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rag_json_response(['ok' => false, 'error' => 'Metodo invalido'], 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;

$action = trim((string) ($body['action'] ?? ''));
$docId = trim((string) ($body['doc_id'] ?? ''));
$email = strtolower(trim((string) ($body['email'] ?? '')));

if (!in_array($action, ['grant', 'revoke', 'list'], true)) {
    rag_json_response(['ok' => false, 'error' => 'action invalida'], 400);
}
if (!rag_is_uuid($docId)) {
    rag_json_response(['ok' => false, 'error' => 'doc_id invalido'], 400);
}

// Verifica que o doc existe (evita criar ACL para doc fantasma)
$doc = rag_get_global_doc($docId);
if (!$doc) {
    rag_json_response(['ok' => false, 'error' => 'Documento nao encontrado'], 404);
}

if ($action === 'grant' || $action === 'revoke') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        rag_json_response(['ok' => false, 'error' => 'Email invalido'], 400);
    }
    $result = rag_run_python('rag_access.py', [
        $action,
        '--doc-id', $docId,
        '--email', $email,
    ]);
    $json = rag_decode_cli_json($result['stdout']);
    if ($result['code'] !== 0 || !$json || empty($json['ok'])) {
        app_log('rag.admin.access.error', [
            'admin' => $adminEmail,
            'action' => $action,
            'doc_id' => $docId,
            'email' => $email,
            'error' => $json['error'] ?? ($result['stderr'] ?: ''),
        ]);
        rag_json_response([
            'ok' => false,
            'error' => 'Falha ao ' . $action . ' acesso',
        ], 500);
    }
    app_log('rag.admin.access.ok', [
        'admin' => $adminEmail,
        'action' => $action,
        'doc_id' => $docId,
        'email' => $email,
    ]);
    // Retorna a lista atualizada para simplificar o frontend
    $list = rag_run_python('rag_access.py', ['list', '--doc-id', $docId]);
    $listJson = rag_decode_cli_json($list['stdout']);
    rag_json_response([
        'ok' => true,
        'action' => $action,
        'doc_id' => $docId,
        'email' => $email,
        'emails' => ($listJson && !empty($listJson['ok'])) ? ($listJson['emails'] ?? []) : [],
    ]);
}

// action === 'list'
$result = rag_run_python('rag_access.py', ['list', '--doc-id', $docId]);
$json = rag_decode_cli_json($result['stdout']);
if ($result['code'] !== 0 || !$json || empty($json['ok'])) {
    rag_json_response(['ok' => false, 'error' => 'Falha ao listar acessos'], 500);
}
rag_json_response([
    'ok' => true,
    'doc_id' => $docId,
    'emails' => $json['emails'] ?? [],
]);
