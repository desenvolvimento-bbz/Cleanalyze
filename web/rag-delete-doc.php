<?php
/**
 * web/rag-delete-doc.php
 * Remove um documento do usuario autenticado: apaga registros no SQLite
 * (via CLI Python rag_delete.py) + arquivos em disco.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/rag_common.php';

$actingEmail = rag_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rag_json_response(['ok' => false, 'error' => 'Metodo invalido'], 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;

// Delete e operacao destrutiva — bloqueado em view-as.
$email = rag_effective_user($actingEmail, false, 'delete', $body);
$docId = trim((string) ($body['doc_id'] ?? ''));
if (!rag_is_uuid($docId)) {
    rag_json_response(['ok' => false, 'error' => 'doc_id invalido'], 400);
}

// Ownership check antes de deletar: evita que um usuario delete doc de outro.
$docs = rag_list_documents_php($email);
$belongs = false;
foreach ($docs as $d) {
    if ($d['doc_id'] === $docId) { $belongs = true; break; }
}
if (!$belongs) {
    // Idempotente: se o doc ja nao existe, retorna ok sem erro. Isso evita
    // problema quando a UI clicka 2x ou o doc foi removido em outra aba.
    rag_json_response(['ok' => true, 'doc_id' => $docId, 'noop' => true]);
}

$result = rag_run_python('rag_delete.py', [
    '--user', $email,
    '--doc-id', $docId,
]);

$json = rag_decode_cli_json($result['stdout']);
if ($result['code'] !== 0 || !$json || empty($json['ok'])) {
    app_log('rag.delete.error', [
        'user' => $email,
        'doc_id' => $docId,
        'code' => $result['code'],
        'error' => $json['error'] ?? ($result['stderr'] ?: 'erro desconhecido'),
    ]);
    rag_json_response([
        'ok' => false,
        'error' => 'Nao foi possivel excluir o documento. Tente novamente em instantes.',
    ], 500);
}

app_log('rag.delete.ok', [
    'user' => $email,
    'doc_id' => $docId,
    'files_removed' => !empty($json['files_removed']),
]);

rag_json_response(['ok' => true, 'doc_id' => $docId]);
