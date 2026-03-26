<?php
/**
 * executar_extracao.php
 *
 * O que faz:
 * 1) Recebe o PDF e o tipo (ahreas | inadimplencia) + (opcional) Bloco;
 * 2) Salva o PDF em /uploads com timestamp;
 * 3) Monta e executa o comando Python (CLI unificado), com OCR habilitado;
 * 4) Exibe o resultado, link para baixar o XLSX e o .debug.txt, e uma prévia do texto extraído.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

set_time_limit(600);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../auth/bootstrap.php';
auth_require_login();
require_once __DIR__ . '/../config/paths.php';
$PYTHON       = APP_PYTHON;
$PDFTOTEXT    = APP_PDFTOTEXT;
$TESSERACT    = APP_TESSERACT;
$OCR_LANG     = APP_OCR_LANG;
$POPPLER_BIN  = APP_POPPLER;

// ---------- PASTAS DO PROJETO ----------
$BASE         = APP_BASE;
$UPLOADS_DIR  = APP_UPLOADS;
@mkdir($UPLOADS_DIR, 0777, true);

// ---------- ENTRADAS DO FORM ----------
$tipo  = isset($_POST['tipo']) ? trim($_POST['tipo']) : '';              // 'ahreas' | 'inadimplencia'
$bloco = isset($_POST['bloco']) ? trim($_POST['bloco']) : '';            // opcional
$pdfFromForm = isset($_FILES['pdf']) ? $_FILES['pdf'] : null;            // upload padrão
$pdfPathPost  = isset($_POST['pdf_path']) ? trim($_POST['pdf_path']) : '';// caminho já salvo (opcional)

// (opcional) parâmetros de performance para OCR vindos do form
$pages = isset($_POST['pages']) ? trim($_POST['pages']) : ''; // "1-2"
$dpi   = isset($_POST['dpi']) ? (int)$_POST['dpi'] : 200;

// ---------- VALIDAÇÃO/UPLOAD ----------
$erros = [];
$finalPdfPath = '';

if ($pdfFromForm && $pdfFromForm['error'] === UPLOAD_ERR_OK) {
  $origName = $pdfFromForm['name'];
  $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
  if ($ext !== 'pdf') {
    $erros[] = "Envie um arquivo PDF.";
  } else {
    $ts = date('Ymd_His');
    $safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', $origName);
    $finalPdfPath = $UPLOADS_DIR . APP_SEP . $ts . '_' . $safeName;
    if (!move_uploaded_file($pdfFromForm['tmp_name'], $finalPdfPath)) {
      $erros[] = "Falha ao salvar PDF enviado.";
    }
  }
} elseif ($pdfPathPost) {
  if (!file_exists($pdfPathPost)) {
    $erros[] = "Arquivo PDF não encontrado: " . htmlspecialchars($pdfPathPost);
  } else {
    $finalPdfPath = $pdfPathPost;
  }
} else {
  $erros[] = "Nenhum PDF recebido.";
}

if ($tipo !== 'ahreas' && $tipo !== 'inadimplencia') {
  $erros[] = "Tipo inválido. Use 'ahreas' ou 'inadimplencia'.";
}

if ($erros) {
  http_response_code(400);
  echo "<h2>Erro</h2><ul>";
  foreach ($erros as $e) echo "<li>" . htmlspecialchars($e) . "</li>";
  echo "</ul>";
  exit;
}

// ---------- SELEÇÃO DE CONFIG E MODELO ----------
$S = APP_SEP;
if ($tipo === 'inadimplencia') {
  $config = $BASE . $S . 'config' . $S . 'inadimplencia.json';
  $modelo = $BASE . $S . 'modelo_planilha_inadimplencia.xlsx';
  $prefixoSaida = 'Inadimplencia';
} else { // 'ahreas'
  $config = $BASE . $S . 'config' . $S . 'ahreas.json';
  $modelo = $BASE . $S . 'modelo_planilha_importacao.xlsx';
  $prefixoSaida = 'Unidades';
}

// ---------- NOME DO ARQUIVO DE SAÍDA ----------
$ts = date('Ymd_His');
$blocoSafe = preg_replace('/[^A-Za-z0-9_\-]/', '', $bloco);
if ($blocoSafe !== '') {
  $saidaBase = "{$prefixoSaida}_BLOCO_{$blocoSafe}_{$ts}.xlsx";
} else {
  $saidaBase = "{$prefixoSaida}_{$ts}.xlsx";
}
$saida = $UPLOADS_DIR . APP_SEP . $saidaBase;

// ---------- PÁGINAS (opcional, ex.: "1-2") ----------
$firstPage = $lastPage = '';
if ($pages !== '' && preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $pages, $m)) {
  $firstPage = (int)$m[1];
  $lastPage  = (int)$m[2];
}

// ---------- MONTA O COMANDO PYTHON (OCR sempre habilitado) ----------
$cmd = "\"$PYTHON\" \"$BASE{$S}cleanalize_cli.py\" "
     . "--pdftotext \"$PDFTOTEXT\" "
     . "--pdf \"$finalPdfPath\" "
     . "--tipo $tipo "
     . "--config \"$config\" "
     . "--modelo \"$modelo\" "
     . "--saida \"$saida\" "
     . "--ocr "
     . "--tesseract \"$TESSERACT\" "
     . "--ocr-lang \"$OCR_LANG\" "
     . "--poppler \"$POPPLER_BIN\" "
     . "--dpi " . (int)$dpi . " ";
if ($firstPage && $lastPage && $lastPage >= $firstPage) {
  $cmd .= "--first-page $firstPage --last-page $lastPage ";
}
$cmd .= "--debug-save-text 2>&1";

// ---------- EXECUTA ----------
$out = [];
$code = 0;

// Log do comando para debug
$logFile = $UPLOADS_DIR . APP_SEP . 'ultimo_comando.txt';
file_put_contents($logFile, $cmd . "\n\n" . date('Y-m-d H:i:s'));

// Executar comando
exec($cmd, $out, $code);

function h($s){return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');}
$debugTxt = $finalPdfPath . '.debug.txt';

// Tipo formatado para exibição
$tipoLabel = $tipo === 'inadimplencia' ? 'Inadimplência' : 'Ahreas (Unidades)';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Resultado da Extração - Cleanalyze</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&display=swap" rel="stylesheet">
  <style>
    :root{ --azul:#04193b; --cinza:#b8b8c4; --cinzaClaro:#efeff4; }
    body{ background:var(--cinzaClaro); color:var(--azul); font-family: 'Manrope', sans-serif; }
    .navbar{ background:var(--azul); }
    .navbar .navbar-brand, .navbar a{ color:#fff !important; }
    .btn-primary{ background:var(--azul); border-color:var(--azul); }
    .btn-primary:hover{ background:#062a5c; border-color:#062a5c; }
    .card{ border-color:var(--cinza); }
    pre{ white-space: pre-wrap; word-break: break-word; background: #f8f9fa; padding: 12px; border-radius: 6px; font-size: 0.85rem; }
    .result-icon{ font-size: 3rem; }
  </style>
</head>
<body>

<?php $activePage = 'extrair'; include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">
      <div class="card">
        <div class="card-body">
          <h4 class="mb-3">Resultado da Extração</h4>
          <p class="text-secondary mb-4">Tipo: <strong><?= h($tipoLabel) ?></strong></p>

          <?php if ($code === 0): ?>
            <!-- Sucesso -->
            <div class="alert alert-success d-flex align-items-center">
              <span class="result-icon me-3">✅</span>
              <div>
                <h5 class="mb-1">Extração concluída com sucesso!</h5>
                <p class="mb-0">Planilha gerada: <strong><?= h(basename($saida)) ?></strong></p>
                <?php if ($blocoSafe !== ''): ?>
                  <small>Bloco: <strong><?= h($blocoSafe) ?></strong></small>
                <?php endif; ?>
              </div>
            </div>

            <div class="d-flex gap-2 mb-4">
              <a class="btn btn-primary btn-lg" href="<?= h('download.php?f=' . urlencode($saida)) ?>">
                ⬇️ Baixar Planilha
              </a>
              <?php if (is_file($debugTxt)): ?>
                <a class="btn btn-outline-secondary" href="<?= h('download.php?f=' . urlencode($debugTxt)) ?>">
                  📄 Baixar .debug.txt
                </a>
              <?php endif; ?>
              <a class="btn btn-outline-primary" href="../index.php">
                🔄 Nova Extração
              </a>
            </div>

            <?php if (is_file($debugTxt)): ?>
              <?php
                $size = filesize($debugTxt);
                $preview = file_get_contents($debugTxt, false, null, 0, 800);
              ?>
              <div class="card mb-3">
                <div class="card-header">
                  <strong>Prévia do texto extraído</strong>
                  <span class="badge bg-secondary ms-2"><?= number_format((int)$size, 0, ',', '.') ?> bytes</span>
                </div>
                <div class="card-body">
                  <pre style="max-height:200px; overflow:auto; margin:0;"><?= h($preview) ?><?= strlen($preview) >= 800 ? '...' : '' ?></pre>
                </div>
              </div>
              <?php if ($size < 1000): ?>
                <div class="alert alert-warning">
                  ⚠️ O texto extraído está pequeno. Pode ser um PDF escaneado (OCR necessário) ou houve erro no processamento.
                </div>
              <?php endif; ?>
            <?php endif; ?>

          <?php else: ?>
            <!-- Erro -->
            <div class="alert alert-danger d-flex align-items-center">
              <span class="result-icon me-3">❌</span>
              <div>
                <h5 class="mb-1">Falha na extração</h5>
                <p class="mb-0">Código de saída: <strong><?= (int)$code ?></strong></p>
              </div>
            </div>

            <div class="d-flex gap-2 mb-4">
              <a class="btn btn-primary" href="../index.php">
                🔄 Tentar Novamente
              </a>
            </div>
          <?php endif; ?>

          <?php if (auth_is_admin()): ?>
          <!-- Detalhes técnicos (somente admin) -->
          <div class="accordion" id="detalhesAcordion">
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDetalhes">
                  Detalhes técnicos da execução
                </button>
              </h2>
              <div id="collapseDetalhes" class="accordion-collapse collapse" data-bs-parent="#detalhesAcordion">
                <div class="accordion-body">
                  <h6>Comando executado:</h6>
                  <pre><?= h($cmd) ?></pre>
                  <h6>Saída do processo:</h6>
                  <pre><?= h(implode("\n", $out)) ?></pre>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </div>
</div>

<footer class="text-center text-muted my-4">
  2025 © Desenvolvimento BBZ.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
