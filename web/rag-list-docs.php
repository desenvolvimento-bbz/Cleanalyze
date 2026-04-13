<?php
/**
 * web/rag-list-docs.php
 * Lista os documentos do usuario autenticado e, opcionalmente, o historico
 * de chat de um documento especifico (?doc_id=<uuid>&with_history=1).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/rag_common.php';

$email = rag_require_user();

$docs = rag_list_documents_php($email);
$globals = rag_list_global_docs_for($email);

$response = [
    'ok' => true,
    'documents' => $docs,
    'global_documents' => $globals,
];

$docId = $_GET['doc_id'] ?? null;
if ($docId && rag_is_uuid($docId) && !empty($_GET['with_history'])) {
    $belongs = false;
    foreach ($docs as $d) {
        if ($d['doc_id'] === $docId) { $belongs = true; break; }
    }
    if (!$belongs) {
        // Historico de um doc global: permitido se o usuario tiver ACL
        foreach ($globals as $g) {
            if ($g['doc_id'] === $docId) { $belongs = true; break; }
        }
    }
    if ($belongs) {
        $response['history'] = rag_get_chat_history_php($email, $docId);
    }
}

rag_json_response($response);
