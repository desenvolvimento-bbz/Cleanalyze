<?php
/**
 * web/rag-admin-global-list-all.php
 * Admin — lista TODOS os Documentos BBZ com suas respectivas ACLs.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/rag_common.php';

rag_require_admin();

$result = rag_run_python('rag_access.py', ['list-all']);
$json = rag_decode_cli_json($result['stdout']);
if ($result['code'] !== 0 || !$json || empty($json['ok'])) {
    rag_json_response([
        'ok' => false,
        'error' => 'Falha ao listar documentos',
        'detail' => $json['error'] ?? ($result['stderr'] ?: ''),
    ], 500);
}

rag_json_response([
    'ok' => true,
    'documents' => $json['documents'] ?? [],
]);
