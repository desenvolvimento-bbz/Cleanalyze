<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login();
app_log('page.view', ['page'=>basename(__FILE__)]);

require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

/* ---------- helpers de execução ---------- */

function pick_python_binary(): string {
  // Permite forçar via env na hospedagem/local
  $env = getenv('PYTHON_BIN');
  if ($env) return $env;

  $isWin = stripos(PHP_OS_FAMILY, 'Windows') !== false;

  if ($isWin) {
    // tenta py -3, py, depois where python3/python
    $out = @shell_exec('py -3 -V 2>&1');
    if ($out && stripos($out, 'Python') !== false) return 'py -3';
    $out = @shell_exec('py -V 2>&1');
    if ($out && stripos($out, 'Python') !== false) return 'py';
    foreach (['python3','python'] as $bin) {
      $w = @shell_exec('where '.$bin.' 2>&1');
      if ($w && stripos($w, 'Could not find') === false && stripos($w, 'Não foi possível') === false) return $bin;
    }
    return 'py -3';
  } else {
    foreach (['python3','python'] as $bin) {
      $p = @shell_exec('which '.$bin.' 2>&1');
      if ($p && trim($p) !== '') return $bin;
    }
    return 'python3';
  }
}

function run_cmd(string $cmd, array $env = []): array {
  $spec = [
    0 => ['pipe','r'],
    1 => ['pipe','w'],
    2 => ['pipe','w'],
  ];
  $proc = proc_open($cmd, $spec, $pipes, __DIR__, $env);
  if (!is_resource($proc)) {
    return ['exit'=>-1, 'stdout'=>'', 'stderr'=>'proc_open falhou'];
  }
  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
  $exit   = proc_close($proc);
  return ['exit'=>$exit, 'stdout'=>$stdout, 'stderr'=>$stderr];
}

/* ---------- helper para mostrar XLSX ---------- */

function exibirPlanilhaComoTabelaHTML($arquivo_xlsx) {
  $spreadsheet = IOFactory::load($arquivo_xlsx);
  $sheet = $spreadsheet->getActiveSheet();
  $dados = $sheet->toArray(null, true, true, true);

  if (empty($dados)) {
    echo "<div class='alert alert-warning'>Planilha sem dados.</div>";
    return;
  }

  $headers  = array_values($dados[1] ?? []);
  $colCount = count($headers);

  echo '<div class="table-responsive mt-4">';
  echo '<table id="tabelaPreview" class="table table-bordered table-hover table-sm">';
  echo '<thead><tr>';
  foreach ($headers as $h) echo '<th>'.htmlspecialchars((string)$h).'</th>';
  echo '</tr></thead><tbody>';

  $totalRows = count($dados);
  for ($i = 2; $i <= $totalRows; $i++) {
    if (!isset($dados[$i])) continue;
    $cells = array_values($dados[$i]);
    $len   = count($cells);
    if ($len < $colCount)      $cells = array_pad($cells, $colCount, '');
    elseif ($len > $colCount)  $cells = array_slice($cells, 0, $colCount);
    echo '<tr>';
    foreach ($cells as $c) echo '<td>'.htmlspecialchars((string)$c).'</td>';
    echo '</tr>';
  }
  echo '</tbody></table></div>';
}

/* ---------- variáveis de fluxo ---------- */

$err = null;
$stdout = $stderr = '';
$exit = 1;
$pdf_path = $xlsx_gerado = $cmd = '';

/* ---------- processamento ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!isset($_FILES['pdf'])) {
    $err = "Nenhum arquivo recebido (verifique se o formulário tem enctype='multipart/form-data').";
  } elseif (!is_array($_FILES['pdf']) || ($_FILES['pdf']['error'] !== UPLOAD_ERR_OK)) {
    $code = $_FILES['pdf']['error'] ?? -1;
    $map = [
      UPLOAD_ERR_INI_SIZE   => 'Arquivo excedeu upload_max_filesize (php.ini).',
      UPLOAD_ERR_FORM_SIZE  => 'Arquivo excedeu MAX_FILE_SIZE do formulário.',
      UPLOAD_ERR_PARTIAL    => 'Upload enviado parcialmente.',
      UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
      UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente no servidor.',
      UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar o arquivo em disco.',
      UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão do PHP.',
      -1                    => 'Erro desconhecido.'
    ];
    $err = "Erro no upload do PDF! Código={$code} — ".($map[$code] ?? 'Desconhecido')
         ." (upload_max_filesize=".ini_get('upload_max_filesize')
         .", post_max_size=".ini_get('post_max_size').")";
  } else {
    // upload OK
    $upload_dir = __DIR__ . '/uploads/';
    $output_dir = __DIR__ . '/output/';
    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
    if (!is_dir($output_dir)) @mkdir($output_dir, 0777, true);

    $nome_pdf = uniqid('relatorio_') . '.pdf';
    $pdf_path = $upload_dir . $nome_pdf;
    if (!@move_uploaded_file($_FILES['pdf']['tmp_name'], $pdf_path)) {
      $err = "Falha ao mover upload para $pdf_path";
    } else {
      $base        = __DIR__;
      $script      = $base.'/extractor_pdf.py';
      $modelo_path = $base.'/modelo_planilha_importacao.xlsx';
      $xlsx_gerado = $output_dir.'relatorio_unidades_extraido.xlsx';
      $modelo_nome = $_POST['modelo_nome'] ?? 'ahreas';

      if (!file_exists($script))        $err = "Backend Python não encontrado: $script";
      elseif (!file_exists($modelo_path)) $err = "Modelo XLSX não encontrado: $modelo_path";

      if (!$err) {
        $python = pick_python_binary();
        $args = [
          escapeshellarg($script),
          '--pdf',         escapeshellarg($pdf_path),
          '--modelo',      escapeshellarg($modelo_path),
          '--saida',       escapeshellarg($output_dir),
          '--modelo_nome', escapeshellarg($modelo_nome)
        ];
        $pdftotext = getenv('POPPLER_PDFTOTEXT') ?: ($_ENV['POPPLER_PDFTOTEXT'] ?? null);
        if (!empty($pdftotext)) {
          $args[] = '--pdftotext';
          $args[] = escapeshellarg($pdftotext);
        }
        $cmd = escapeshellcmd($python).' '.implode(' ', $args);

        $env = $_ENV;
        $env['PYTHONIOENCODING'] = 'utf-8';

        $res = run_cmd($cmd, $env);
        $stdout = $res['stdout'] ?? '';
        $stderr = $res['stderr'] ?? '';
        $exit   = $res['exit']   ?? 1;

        app_log('extract.run', ['cmd'=>$cmd, 'exit'=>$exit]);
        if ($exit !== 0 || !file_exists($xlsx_gerado)) {
          $err = "Falha ao gerar XLSX. Veja detalhes abaixo.";
        }
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Prévia dos Dados — Cleanalyze BBZ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#efeff4; color:#04193b; padding:20px; }
    h1,h3 { color:#04193b; }
    .btn-primary{ background:#04193b; border-color:#04193b; }
    .btn-outline-secondary{ color:#04193b; border-color:#b8b8c4; }
    table thead th { background:#b8b8c4; color:#04193b; }
    pre{ white-space:pre-wrap; }
  </style>
</head>
<body>
<div class="container">
  <h1>💾 Extração de PDF</h1>

  <div class="mb-3">
    <a href="index.php" class="btn btn-outline-secondary">⬅️ Voltar</a>
    <?php if ($xlsx_gerado && file_exists($xlsx_gerado) && !$err): ?>
      <a href="output/relatorio_unidades_extraido.xlsx" class="btn btn-primary ms-2" download>📥 Baixar Planilha XLSX</a>
    <?php endif; ?>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger"><strong>Erro:</strong> <?= htmlspecialchars($err) ?></div>
    <?php if ($cmd): ?>
      <p><code><?= htmlspecialchars($cmd) ?></code></p>
    <?php endif; ?>
    <?php if ($stdout || $stderr): ?>
      <div class="card my-3">
        <div class="card-header">Detalhes da Execução</div>
        <div class="card-body">
          <?php if ($stdout): ?><h6>STDOUT</h6><pre><?= htmlspecialchars($stdout) ?></pre><?php endif; ?>
          <?php if ($stderr): ?><h6>STDERR</h6><pre><?= htmlspecialchars($stderr) ?></pre><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php elseif ($xlsx_gerado && file_exists($xlsx_gerado)): ?>
    <div class="alert alert-success">✅ Extração concluída com sucesso.</div>
    <?php exibirPlanilhaComoTabelaHTML($xlsx_gerado); ?>
  <?php else: ?>
    <div class="alert alert-info">Envie um PDF pela página inicial para iniciar a extração.</div>
  <?php endif; ?>

  <hr>
  <footer class="text-center text-muted my-4">
    2025 © BBZ Administração de Condomínio Ltda.
  </footer>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function(){
  const $t = $('#tabelaPreview');
  if ($t.length) $t.DataTable({ language:{ url:"//cdn.datatables.net/plug-ins/1.13.6/i18n/pt-BR.json" }});
});
</script>
</body>
</html>
