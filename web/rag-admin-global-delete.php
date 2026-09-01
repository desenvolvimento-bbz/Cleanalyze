<?php
/**
 * web/rag-admin-global-delete.php
 * Admin deleta um Documento BBZ (global) — remove do store global + arquivos.
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
$docId = trim((string) ($body['doc_id'] ?? ''));
if (!rag_is_uuid($docId)) {
    rag_json_response(['ok' => false, 'error' => 'doc_id invalido'], 400);
}

$doc = rag_get_global_doc($docId);
if (!$doc) {
    // idempotente
    rag_json_response(['ok' => true, 'doc_id' => $docId, 'noop' => true]);
}

$result = rag_run_python('rag_delete.py', [
    '--doc-id', $docId,
    '--global',
]);
$json = rag_decode_cli_json($result['stdout']);
if ($result['code'] !== 0 || !$json || empty($json['ok'])) {
    app_log('rag.admin.global_delete.error', [
        'admin' => $adminEmail,
        'doc_id' => $docId,
        'code' => $result['code'],
        'error' => $json['error'] ?? ($result['stderr'] ?: 'erro desconhecido'),
    ]);
    rag_json_response([
        'ok' => false,
        'error' => 'Nao foi possivel excluir o documento. Tente novamente em instantes.',
    ], 500);
}

app_log('rag.admin.global_delete.ok', [
    'admin' => $adminEmail,
    'doc_id' => $docId,
    'filename' => $doc['filename'] ?? null,
]);

rag_json_response(['ok' => true, 'doc_id' => $docId]);
