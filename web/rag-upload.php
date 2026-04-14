<?php
/**
 * web/rag-upload.php
 * Recebe o PDF, salva no diretorio do usuario e dispara o CLI de ingestao.
 * Retorna JSON com status + doc_id.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

set_time_limit(600);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/rag_common.php';

$actingEmail = rag_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rag_json_response(['ok' => false, 'error' => 'Metodo invalido'], 405);
}

// Upload e operacao de ESCRITA — bloqueado em view-as.
$email = rag_effective_user($actingEmail, false, 'upload');

if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    rag_json_response(['ok' => false, 'error' => 'Arquivo nao recebido'], 400);
}

$orig = $_FILES['pdf']['name'];
$ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];
if (!in_array($ext, $allowed, true)) {
    rag_json_response(
        ['ok' => false, 'error' => 'Extensao nao suportada (use PDF ou imagem)'],
        400
    );
}

// Valida magic bytes para evitar gasto de credito com arquivos que mentem a extensao.
$fh = @fopen($_FILES['pdf']['tmp_name'], 'rb');
$head = $fh ? fread($fh, 16) : '';
if ($fh) fclose($fh);
$isPdf  = substr($head, 0, 4) === '%PDF';
$isPng  = substr($head, 0, 8) === "\x89PNG\r\n\x1a\n";
$isJpeg = substr($head, 0, 3) === "\xFF\xD8\xFF";
$isWebp = substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP';
$typeOk = match ($ext) {
    'pdf'             => $isPdf,
    'png'             => $isPng,
    'jpg', 'jpeg'     => $isJpeg,
    'webp'            => $isWebp,
    default           => false,
};
if (!$typeOk) {
    rag_json_response(
        ['ok' => false, 'error' => 'Arquivo nao parece ser um ' . strtoupper($ext) . ' valido'],
        400
    );
}

$docId = rag_uuid();
$docDir = rag_user_files_dir($email) . APP_SEP . $docId;
if (!is_dir($docDir) && !mkdir($docDir, 0775, true)) {
    rag_json_response(['ok' => false, 'error' => 'Falha ao criar diretorio'], 500);
}
$destPath = $docDir . APP_SEP . 'original.pdf';
if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $destPath)) {
    rag_json_response(['ok' => false, 'error' => 'Falha ao salvar upload'], 500);
}

app_log('rag.upload', ['user' => $email, 'doc_id' => $docId, 'filename' => $orig]);

$result = rag_run_python('rag_ingest.py', [
    '--pdf', $destPath,
    '--user', $email,
    '--doc-id', $docId,
    '--filename', $orig,
]);

$json = rag_decode_cli_json($result['stdout']);
if ($result['code'] !== 0 || !$json || empty($json['ok'])) {
    $rawError = $json['error'] ?? ($result['stderr'] ?: 'erro desconhecido');
    app_log('rag.ingest.error', [
        'user' => $email,
        'doc_id' => $docId,
        'code' => $result['code'],
        'error' => $rawError,
    ]);

    // Traduz erros tecnicos em mensagens amigaveis ao usuario
    $friendly = 'Nao foi possivel processar o documento. Tente novamente em instantes.';
    if (stripos($rawError, 'Status 429') !== false || stripos($rawError, 'rate limit') !== false) {
        $friendly = 'O servico de OCR esta sobrecarregado no momento. Tente novamente em alguns minutos.';
    } elseif (preg_match('/Status 5\d\d/', $rawError) || stripos($rawError, 'Internal Server Error') !== false) {
        $friendly = 'O servico de OCR teve uma falha temporaria. Tente novamente em alguns instantes.';
    } elseif (stripos($rawError, 'ReadTimeout') !== false || stripos($rawError, 'timeout') !== false) {
        $friendly = 'O OCR demorou demais para responder. Se o documento for muito grande, tente um menor.';
    } elseif (stripos($rawError, 'FileNotFoundError') !== false) {
        $friendly = 'Arquivo nao encontrado apos o upload. Tente enviar novamente.';
    } elseif (stripos($rawError, 'nao parece ser') !== false || stripos($rawError, 'nao suportada') !== false) {
        $friendly = $rawError; // mensagens de validacao nossas ja sao amigaveis
    } elseif (stripos($rawError, 'zero paginas') !== false) {
        $friendly = 'Nao foi possivel extrair texto do documento. Verifique se ele contem texto legivel.';
    }

    rag_json_response([
        'ok' => false,
        'doc_id' => $docId,
        'error' => $friendly,
    ], 500);
}

app_log('rag.ingest.ok', [
    'user' => $email,
    'doc_id' => $docId,
    'pages' => $json['pages'] ?? 0,
    'chunks' => $json['chunks'] ?? 0,
]);

rag_json_response([
    'ok' => true,
    'doc_id' => $docId,
    'filename' => $orig,
    'pages' => $json['pages'] ?? 0,
    'chunks' => $json['chunks'] ?? 0,
]);
