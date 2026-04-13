<?php
/**
 * web/rag-ocr-text.php
 * Retorna o texto extraido pelo OCR (conteudo de files/<doc_id>/ocr.txt)
 * como JSON, para o frontend copiar para a area de transferencia.
 *
 * Uso: GET web/rag-ocr-text.php?doc_id=<uuid>
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/rag_common.php';

$email = rag_require_user();

$docId = trim((string) ($_GET['doc_id'] ?? ''));
if (!rag_is_uuid($docId)) {
    rag_json_response(['ok' => false, 'error' => 'doc_id invalido'], 400);
}

// Resolve o doc: primeiro nos docs do usuario, depois globais (com check de ACL)
$docs = rag_list_documents_php($email);
$found = null;
$isGlobal = false;
foreach ($docs as $d) {
    if ($d['doc_id'] === $docId) { $found = $d; break; }
}
if (!$found) {
    $globalDoc = rag_get_global_doc($docId);
    if ($globalDoc && rag_user_has_global_access($docId, $email)) {
        $found = $globalDoc;
        $isGlobal = true;
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

// Caminho do ocr.txt com validacao de path traversal
$baseFiles = $isGlobal ? rag_global_files_dir() : rag_user_files_dir($email);
$txtPath = $baseFiles . APP_SEP . $docId . APP_SEP . 'ocr.txt';
$realBase    = realpath($baseFiles);
$realTxtPath = realpath($txtPath);
if (
    $realBase === false
    || $realTxtPath === false
    || strpos($realTxtPath, $realBase . DIRECTORY_SEPARATOR) !== 0
    || !is_file($realTxtPath)
) {
    rag_json_response(['ok' => false, 'error' => 'Texto OCR nao disponivel para este documento'], 404);
}

$text = file_get_contents($realTxtPath);
if ($text === false) {
    rag_json_response(['ok' => false, 'error' => 'Falha ao ler texto OCR'], 500);
}

app_log('rag.ocr_text', [
    'user' => $email,
    'doc_id' => $docId,
    'bytes' => strlen($text),
]);

rag_json_response([
    'ok' => true,
    'doc_id' => $docId,
    'filename' => $found['filename'],
    'text' => $text,
    'bytes' => strlen($text),
]);
