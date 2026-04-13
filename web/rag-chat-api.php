<?php
/**
 * web/rag-chat-api.php
 * Endpoint de chat: recebe {doc_id, question} e retorna {ok, answer, sources}.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

set_time_limit(300);

require_once __DIR__ . '/rag_common.php';

$email = rag_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rag_json_response(['ok' => false, 'error' => 'Metodo invalido'], 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;

$docId = trim((string) ($body['doc_id'] ?? ''));
$question = trim((string) ($body['question'] ?? ''));

if (!rag_is_uuid($docId)) {
    rag_json_response(['ok' => false, 'error' => 'doc_id invalido'], 400);
}
if ($question === '') {
    rag_json_response(['ok' => false, 'error' => 'Pergunta vazia'], 400);
}
if (mb_strlen($question) > 2000) {
    rag_json_response(['ok' => false, 'error' => 'Pergunta muito longa (max 2000 caracteres)'], 400);
}

// Resolve o doc: primeiro nos docs do usuario, depois nos Documentos BBZ (com check de acesso)
$docs = rag_list_documents_php($email);
$found = null;
foreach ($docs as $d) {
    if ($d['doc_id'] === $docId) { $found = $d; break; }
}
if (!$found) {
    $globalDoc = rag_get_global_doc($docId);
    if ($globalDoc && rag_user_has_global_access($docId, $email)) {
        $found = $globalDoc;
    }
}
if (!$found) {
    rag_json_response(['ok' => false, 'error' => 'Documento nao encontrado'], 404);
}
if ($found['status'] !== 'ready') {
    rag_json_response([
        'ok' => false,
        'error' => 'Documento ainda nao esta pronto (status=' . $found['status'] . ')',
    ], 409);
}

// Passa a pergunta via stdin pra evitar limites de shell
$result = rag_run_python(
    'rag_query.py',
    ['--user', $email, '--doc-id', $docId, '--stdin'],
    $question
);

$json = rag_decode_cli_json($result['stdout']);
if ($result['code'] !== 0 || !$json || empty($json['ok'])) {
    app_log('rag.query.error', [
        'user' => $email,
        'doc_id' => $docId,
        'code' => $result['code'],
        'error' => $json['error'] ?? ($result['stderr'] ?: 'erro'),
    ]);
    rag_json_response([
        'ok' => false,
        'error' => $json['error'] ?? 'Falha ao consultar o documento',
    ], 500);
}

app_log('rag.query.ok', [
    'user' => $email,
    'doc_id' => $docId,
    'question_len' => mb_strlen($question),
]);

rag_json_response([
    'ok' => true,
    'answer' => $json['answer'] ?? '',
    'sources' => $json['sources'] ?? [],
]);
