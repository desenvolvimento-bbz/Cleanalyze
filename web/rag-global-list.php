<?php
/**
 * web/rag-global-list.php
 * Lista os Documentos BBZ (globais) acessiveis ao usuario autenticado.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/rag_common.php';

$email = rag_require_user();
$docs = rag_list_global_docs_for($email);

rag_json_response([
    'ok' => true,
    'documents' => $docs,
]);
