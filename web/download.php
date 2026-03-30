<?php
/**
 * download.php
 * Faz o download (stream) de um arquivo gerado em /uploads com checagem de segurança.
 *
 * Uso:
 *   web/download.php?f=<caminho_absoluto_do_arquivo>
 *
 * Regras de segurança:
 *  - Aceita caminho absoluto, porém exige que ele esteja dentro de uploads/
 *  - Normaliza com realpath() e compara prefixo
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../auth/bootstrap.php';
auth_require_login();

require_once __DIR__ . '/../config/paths.php';
$BASE    = APP_BASE;
$UPLOADS = APP_UPLOADS;

if (!isset($_GET['f']) || $_GET['f'] === '') {
  http_response_code(400);
  echo 'Parâmetro "f" ausente.';
  exit;
}

$requested = $_GET['f'];

// Normaliza
$real = realpath($requested);
$uploadsReal = realpath($UPLOADS);

// Checagens
if ($real === false || $uploadsReal === false) {
  http_response_code(400);
  echo 'Caminho inválido.';
  exit;
}

// Garante que o arquivo pedido está DENTRO de /uploads
// (prefixo do caminho real deve começar por /uploads real)
if (stripos($real, $uploadsReal) !== 0) {
  http_response_code(403);
  echo 'Acesso negado.';
  exit;
}

if (!is_file($real) || !is_readable($real)) {
  http_response_code(404);
  echo 'Arquivo não encontrado.';
  exit;
}

// Nome de download (apenas o nome do arquivo)
$nomeDownload = basename($real);

// Mime básico pelo sufixo
$ext = strtolower(pathinfo($nomeDownload, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'xlsx') $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
if ($ext === 'xls')  $mime = 'application/vnd.ms-excel';
if ($ext === 'csv')  $mime = 'text/csv';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: attachment; filename="' . $nomeDownload . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$fp = fopen($real, 'rb');
if ($fp) {
  while (!feof($fp)) {
    echo fread($fp, 8192);
    flush();
  }
  fclose($fp);
}
exit;
