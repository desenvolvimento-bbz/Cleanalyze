<?php
/**
 * diagnostico.php - Testa configurações e executáveis
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../auth/bootstrap.php';
auth_require_admin();
require_once __DIR__ . '/../config/paths.php';
$PYTHON       = APP_PYTHON;
$PDFTOTEXT    = APP_PDFTOTEXT;
$TESSERACT    = APP_TESSERACT;
$BASE         = APP_BASE;
$UPLOADS_DIR  = APP_UPLOADS;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Diagnóstico - Cleanalyze</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
  <link href="../assets/css/bbz.css" rel="stylesheet">
  <style>
    :root{ --azul:#04193b; --cinza:#b8b8c4; --cinzaClaro:#efeff4; }
    body{ background:var(--cinzaClaro); color:var(--azul); font-family: 'Manrope', sans-serif; }
    .navbar{ background:var(--azul); }
    .navbar .navbar-brand, .navbar a{ color:#fff !important; }
    .card{ border-color:var(--cinza); }
    .ok { color: #198754; font-weight: 600; }
    .erro { color: #dc3545; font-weight: 600; }
    pre { background: var(--bbz-cinza-claro); padding: 12px; border-radius: 6px; font-size: 0.85rem; overflow-x: auto; }
  </style>
</head>
<body>

<?php $activePage = ''; include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">
      <div class="card">
        <div class="card-body">
          <h4 class="mb-3">🔧 Diagnóstico do Sistema</h4>
          <p class="text-secondary">Verificação das configurações e executáveis do Cleanalyze.</p>

          <!-- 1. Configurações PHP -->
          <div class="card mb-3">
            <div class="card-header">1. Configurações do PHP</div>
            <div class="card-body">
              <table class="table table-sm mb-0">
                <tr><th>Configuração</th><th>Valor</th></tr>
                <tr><td>PHP Version</td><td><?= phpversion() ?></td></tr>
                <tr><td>max_execution_time</td><td><?= ini_get('max_execution_time') ?> segundos</td></tr>
                <tr><td>memory_limit</td><td><?= ini_get('memory_limit') ?></td></tr>
                <tr><td>upload_max_filesize</td><td><?= ini_get('upload_max_filesize') ?></td></tr>
                <tr><td>post_max_size</td><td><?= ini_get('post_max_size') ?></td></tr>
              </table>
            </div>
          </div>

          <!-- 2. Executáveis -->
          <div class="card mb-3">
            <div class="card-header">2. Verificação de Executáveis</div>
            <div class="card-body">
              <ul class="mb-0">
              <?php
              // Python
              if (file_exists($PYTHON)) {
                  echo "<li class='ok'>✅ Python encontrado: $PYTHON</li>";
                  $version = shell_exec("\"$PYTHON\" --version 2>&1");
                  echo "<pre class='mt-1 mb-2'>" . htmlspecialchars(trim($version)) . "</pre>";
              } else {
                  echo "<li class='erro'>❌ Python NÃO encontrado: $PYTHON</li>";
              }

              // pdftotext
              if (file_exists($PDFTOTEXT)) {
                  echo "<li class='ok'>✅ pdftotext encontrado: $PDFTOTEXT</li>";
                  $version = shell_exec("\"$PDFTOTEXT\" -v 2>&1");
                  echo "<pre class='mt-1 mb-2'>" . htmlspecialchars(substr(trim($version), 0, 200)) . "</pre>";
              } else {
                  echo "<li class='erro'>❌ pdftotext NÃO encontrado: $PDFTOTEXT</li>";
              }

              // Tesseract
              if (file_exists($TESSERACT)) {
                  echo "<li class='ok'>✅ Tesseract encontrado: $TESSERACT</li>";
                  $version = shell_exec("\"$TESSERACT\" --version 2>&1");
                  echo "<pre class='mt-1 mb-0'>" . htmlspecialchars(substr(trim($version), 0, 200)) . "</pre>";
              } else {
                  echo "<li class='erro'>❌ Tesseract NÃO encontrado: $TESSERACT</li>";
              }
              ?>
              </ul>
            </div>
          </div>

          <!-- 3. Bibliotecas Python -->
          <div class="card mb-3">
            <div class="card-header">3. Bibliotecas Python</div>
            <div class="card-body">
              <?php
              $libs = ['pandas', 'openpyxl', 'pdfplumber', 'pdf2image', 'pytesseract'];
              $ok_libs = [];
              $fail_libs = [];
              foreach ($libs as $lib) {
                  $cmd = "\"$PYTHON\" -c \"import $lib; print('OK')\" 2>&1";
                  $result = trim(shell_exec($cmd) ?? '');
                  if ($result === 'OK') {
                      $ok_libs[] = $lib;
                  } else {
                      $fail_libs[] = ['name' => $lib, 'error' => $result];
                  }
              }
              foreach ($ok_libs as $lib) {
                  echo "<p class='ok mb-1'>✅ $lib</p>";
              }
              foreach ($fail_libs as $info) {
                  echo "<p class='erro mb-1'>❌ {$info['name']}: " . htmlspecialchars($info['error']) . "</p>";
              }
              if (empty($fail_libs)) {
                  echo "<p class='ok mt-2 mb-0'><strong>Todas as bibliotecas Python estão instaladas.</strong></p>";
              }
              ?>
            </div>
          </div>

          <!-- 4. Permissões -->
          <div class="card mb-3">
            <div class="card-header">4. Verificação de Permissões</div>
            <div class="card-body">
              <ul class="mb-0">
              <?php
              if (is_dir($UPLOADS_DIR)) {
                  echo "<li class='ok'>✅ Pasta uploads existe: $UPLOADS_DIR</li>";
                  if (is_writable($UPLOADS_DIR)) {
                      echo "<li class='ok'>✅ Pasta uploads tem permissão de escrita</li>";
                  } else {
                      echo "<li class='erro'>❌ Pasta uploads NÃO tem permissão de escrita</li>";
                  }
              } else {
                  echo "<li class='erro'>❌ Pasta uploads NÃO existe: $UPLOADS_DIR</li>";
              }

              $testFile = $UPLOADS_DIR . APP_SEP . 'teste_' . date('YmdHis') . '.txt';
              if (@file_put_contents($testFile, 'teste')) {
                  echo "<li class='ok'>✅ Consegue criar arquivos na pasta uploads</li>";
                  @unlink($testFile);
              } else {
                  echo "<li class='erro'>❌ NÃO consegue criar arquivos na pasta uploads</li>";
              }
              ?>
              </ul>
            </div>
          </div>

          <!-- 5. Arquivos de Configuração -->
          <div class="card mb-3">
            <div class="card-header">5. Arquivos de Configuração</div>
            <div class="card-body">
              <ul class="mb-0">
              <?php
              $S = APP_SEP;
              $configs = [
                  'cleanalize_cli.py' => $BASE . $S . 'cleanalize_cli.py',
                  'config/inadimplencia.json' => $BASE . $S . 'config' . $S . 'inadimplencia.json',
                  'config/ahreas.json' => $BASE . $S . 'config' . $S . 'ahreas.json',
                  'modelo_planilha_inadimplencia.xlsx' => $BASE . $S . 'modelo_planilha_inadimplencia.xlsx',
                  'modelo_planilha_importacao.xlsx' => $BASE . $S . 'modelo_planilha_importacao.xlsx',
              ];

              foreach ($configs as $nome => $path) {
                  if (file_exists($path)) {
                      echo "<li class='ok'>✅ $nome encontrado</li>";
                  } else {
                      echo "<li class='erro'>❌ $nome NÃO encontrado</li>";
                  }
              }
              ?>
              </ul>
            </div>
          </div>

          <!-- 6. Teste de Execução -->
          <div class="card mb-3">
            <div class="card-header">6. Teste de Execução</div>
            <div class="card-body">
              <?php
              $testPdf = '';
              $pdfs = glob($UPLOADS_DIR . DIRECTORY_SEPARATOR . '*.pdf');
              if (count($pdfs) > 0) {
                  $testPdf = $pdfs[0];
                  echo "<p>Usando PDF de teste: <code>" . htmlspecialchars(basename($testPdf)) . "</code></p>";

                  $saida = $UPLOADS_DIR . $S . 'DIAG_teste_' . date('YmdHis') . '.xlsx';

                  $cmd = "\"$PYTHON\" \"$BASE{$S}cleanalize_cli.py\" "
                       . "--pdftotext \"$PDFTOTEXT\" "
                       . "--pdf \"$testPdf\" "
                       . "--tipo inadimplencia "
                       . "--config \"$BASE{$S}config{$S}inadimplencia.json\" "
                       . "--modelo \"$BASE{$S}modelo_planilha_inadimplencia.xlsx\" "
                       . "--saida \"$saida\" "
                       . "--ocr "
                       . "--tesseract \"$TESSERACT\" "
                       . "--ocr-lang \"" . APP_OCR_LANG . "\" "
                       . "--poppler \"" . APP_POPPLER . "\" "
                       . "--dpi 200 "
                       . "--debug-save-text 2>&1";

                  echo "<details class='mb-3'><summary>Ver comando</summary>";
                  echo "<pre style='font-size: 0.75rem;'>" . htmlspecialchars($cmd) . "</pre></details>";

                  echo "<p><em>Executando... (pode demorar 1-2 minutos)</em></p>";
                  flush();

                  $inicio = microtime(true);
                  $output = shell_exec($cmd);
                  $fim = microtime(true);
                  $tempo = round($fim - $inicio, 2);

                  echo "<p><strong>Tempo de execução:</strong> {$tempo} segundos</p>";

                  echo "<details class='mb-3'><summary>Ver saída completa</summary>";
                  echo "<pre style='max-height: 300px; overflow: auto;'>" . htmlspecialchars($output) . "</pre></details>";

                  if (file_exists($saida)) {
                      $size = filesize($saida);
                      echo "<p class='ok'>✅ Arquivo XLSX gerado com sucesso! (" . number_format($size, 0, ',', '.') . " bytes)</p>";
                      echo "<p><a href='download.php?f=" . urlencode($saida) . "' class='btn btn-primary btn-sm'>Baixar Planilha de Teste</a></p>";
                  } else {
                      echo "<p class='erro'>❌ Arquivo XLSX NÃO foi gerado</p>";
                  }
              } else {
                  echo "<p class='text-muted'>Nenhum PDF encontrado na pasta uploads para teste.</p>";
              }
              ?>
            </div>
          </div>

          <!-- Recomendações -->
          <div class="alert alert-info">
            <h6>📋 Recomendações</h6>
            <ul class="mb-0">
              <li>Se algum executável não foi encontrado, ajuste os caminhos em <code>web/executar_extracao.php</code></li>
              <li>Se há problemas de permissão, execute: <code>icacls "C:\xampp\htdocs\Cleanalyze\uploads" /grant Users:F</code></li>
              <li>Se o timeout é muito curto, aumente no <code>php.ini</code>: <code>max_execution_time = 300</code></li>
            </ul>
          </div>

        </div>
      </div>
</div>

<footer class="text-center text-muted my-4">
  2025 © Desenvolvimento BBZ.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
