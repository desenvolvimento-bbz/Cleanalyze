<?php
/**
 * Executor Cleanalyze para Inadimplência (visual padronizado).
 *
 * O que faz:
 * 1) Valida e salva o PDF em /uploads.
 * 2) Executa cleanalize_cli.py com --tipo inadimplencia + config + modelo.
 * 3) Mostra logs amigáveis e link para baixar o XLSX gerado.
 *
 * Ajuste necessário:
 * - Trocar $PYTHON se o caminho do seu python.exe for outro.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

function fail($msg) {
  http_response_code(400);
  tpl($msg, null, null, null, 1, '');
  exit;
}

function tpl($msg, $saidaXlsx, $cmd, $stdout, $exit, $stderr) {
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Resultado da extração – Inadimplência</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
  <link href="../assets/css/bbz.css" rel="stylesheet">
  <style>
    body { background:var(--bbz-cinza-claro); }
    h1,h3 { color:#04193b; }
    .btn-outline-secondary{ color:#04193b; border-color:#b8b8c4; }
    pre{ white-space:pre-wrap; }
  </style>
</head>
<body>
<div class="container">
  <h1 class="mt-3">💾 Resultado da extração — Inadimplência</h1>

  <div class="mb-3">
    <a href="extrair_inadimplencia.php" class="btn btn-outline-secondary">⬅️ Novo arquivo</a>
    <?php if ($exit === 0 && $saidaXlsx && file_exists($saidaXlsx)): ?>
      <a href="<?php echo htmlspecialchars('../uploads/' . basename($saidaXlsx)); ?>"
         class="btn btn-primary ms-2" download>📥 Baixar planilha</a>
    <?php endif; ?>
  </div>

  <?php if ($exit === 0): ?>
    <div class="alert alert-success">
      <strong>Sucesso!</strong> Planilha gerada em:
      <code><?php echo htmlspecialchars($saidaXlsx); ?></code>
    </div>
  <?php elseif ($msg): ?>
    <div class="alert alert-danger"><strong>Erro:</strong> <?php echo htmlspecialchars($msg); ?></div>
  <?php endif; ?>

  <div class="card my-3">
    <div class="card-header">Detalhes da execução</div>
    <div class="card-body">
      <?php if ($cmd): ?><h6>Comando</h6><pre><?php echo htmlspecialchars($cmd); ?></pre><?php endif; ?>
      <?php if ($stdout): ?><h6>STDOUT</h6><pre><?php echo htmlspecialchars($stdout); ?></pre><?php endif; ?>
      <?php if ($stderr): ?><h6>STDERR</h6><pre><?php echo htmlspecialchars($stderr); ?></pre><?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
<?php
}

/* ==== 1) Valida upload ==== */

if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
  $code = $_FILES['pdf']['error'] ?? -1;
  fail("PDF não recebido (código $code).");
}
if (strtolower(pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION)) !== 'pdf') {
  fail("Arquivo enviado não é PDF.");
}

/* ==== 2) Caminhos base (auto-detecta Windows vs Docker) ==== */

require_once __DIR__ . '/../config/paths.php';
$S         = APP_SEP;
$BASE      = APP_BASE;
$PYTHON    = APP_PYTHON;
$PDFTOTEXT = APP_PDFTOTEXT;
$CLI       = $BASE . $S . 'cleanalize_cli.py';
$config    = $BASE . $S . 'config' . $S . 'inadimplencia.json';
$modelo    = $BASE . $S . 'modelo_planilha_inadimplencia.xlsx';

if (!file_exists($CLI))       fail("CLI não encontrado em $CLI");
if (!file_exists($config))    fail("Config JSON não encontrado em $config");
if (!file_exists($modelo))    fail("Modelo XLSX não encontrado em $modelo");

/* ==== 3) Salvar PDF em uploads com nome único ==== */

$uploadsDir = APP_UPLOADS;
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0777, true);

$origName = preg_replace('/[^\w\-. ]+/', '_', $_FILES['pdf']['name']);
$ts = date('Ymd_His');
$pdfPath = $uploadsDir . $S . $ts . '_' . $origName;
if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfPath)) {
  fail("Falha ao mover PDF para uploads.");
}

/* ==== 4) Nome da saída ==== */

$saidaBase = trim($_POST['saidaBase'] ?? '');
if ($saidaBase === '') $saidaBase = "Inadimplencia_" . $ts;
$saidaXlsx = $uploadsDir . $S . $saidaBase . '.xlsx';

/* ==== 5) Montar e executar comando ==== */

$cmd = "\"$PYTHON\" \"$CLI\" "
     . "--pdftotext \"$PDFTOTEXT\" "
     . "--pdf \"$pdfPath\" "
     . "--tipo inadimplencia "
     . "--config \"$config\" "
     . "--modelo \"$modelo\" "
     . "--saida \"$saidaXlsx\" "
     . "--debug-save-text 2>&1";

$stdout = $stderr = '';
$exit = 1;

$spec = [
  0 => ['pipe','r'],
  1 => ['pipe','w'],
  2 => ['pipe','w'],
];
$proc = proc_open($cmd, $spec, $pipes, $BASE, []);
if (is_resource($proc)) {
  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
  $exit   = proc_close($proc);
}

/* ==== 6) Renderiza resultado ==== */
tpl('', $saidaXlsx, $cmd, $stdout, $exit, $stderr);
