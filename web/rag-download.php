<?php
/**
 * web/rag-download.php
 * Serve o PDF original de um documento do RAG para download.
 * Exige auth e verifica que o documento pertence ao usuario.
 *
 * Uso: GET web/rag-download.php?doc_id=<uuid>
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/rag_common.php';

$email = rag_require_user();

$docId = trim((string) ($_GET['doc_id'] ?? ''));
if (!rag_is_uuid($docId)) {
    rag_json_response(['ok' => false, 'error' => 'doc_id invalido'], 400);
}

// Confirma que o documento existe e pertence a este usuario
$docs = rag_list_documents_php($email);
$found = null;
foreach ($docs as $d) {
    if ($d['doc_id'] === $docId) { $found = $d; break; }
}
if (!$found) {
    rag_json_response(['ok' => false, 'error' => 'Documento nao encontrado'], 404);
}

// Caminho esperado do arquivo original (dentro do dir do usuario)
$userFiles = rag_user_files_dir($email);
$docDir = $userFiles . APP_SEP . $docId;
$filePath = $docDir . APP_SEP . 'original.pdf';

// Validacao de path traversal: resolvemos o caminho real e conferimos que
// ainda esta dentro do diretorio do usuario.
$realUserFiles = realpath($userFiles);
$realFilePath  = realpath($filePath);
if (
    $realUserFiles === false
    || $realFilePath === false
    || strpos($realFilePath, $realUserFiles . DIRECTORY_SEPARATOR) !== 0
    || !is_file($realFilePath)
) {
    rag_json_response(['ok' => false, 'error' => 'Arquivo nao encontrado em disco'], 404);
}

app_log('rag.download', [
    'user' => $email,
    'doc_id' => $docId,
    'filename' => $found['filename'],
]);

// Nome amigavel para o download. Sanitiza para evitar caracteres que quebrem
// o header Content-Disposition e garante extensao .pdf.
$displayName = $found['filename'] ?: 'documento.pdf';
$displayName = preg_replace('/[\r\n"\\\\]/', '_', $displayName);
if (!preg_match('/\.pdf$/i', $displayName)) {
    $displayName .= '.pdf';
}

// Limpa qualquer output anterior (defensivo)
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($realFilePath));
header('Content-Disposition: attachment; filename="' . $displayName . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($realFilePath);
exit;
