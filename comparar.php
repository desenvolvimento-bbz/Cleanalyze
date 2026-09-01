<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login(); // exige login
app_log('page.view', ['page'=>basename(__FILE__)]);
require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use Dompdf\Dompdf;

/* =========================
   Helpers de Similaridade
   ========================= */

function normaliza_txt($s) {
    $s = (string)$s;
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    return $s;
}

/** Similaridade por caracteres (similar_text) em % */
function sim_por_caracteres($a, $b) {
    $a = normaliza_txt($a);
    $b = normaliza_txt($b);
    if ($a === '' && $b === '') return 100.0;
    if ($a === '' || $b === '') return 0.0;
    similar_text($a, $b, $percent);
    return (float)$percent;
}

/** Similaridade Levenshtein normalizada em %: 100 * (1 - dist / maxlen) */
function sim_por_levenshtein($a, $b) {
    $a = normaliza_txt($a);
    $b = normaliza_txt($b);
    if ($a === '' && $b === '') return 100.0;
    if ($a === '' || $b === '') return 0.0;

    // levenshtein não é multibyte, mas funciona bem o suficiente para nosso uso
    $dist = levenshtein($a, $b);
    $len  = max(strlen($a), strlen($b)); // usar strlen aqui está ok para normalização
    if ($len === 0) return 100.0;
    $sim = 100.0 * (1.0 - ($dist / $len));
    if ($sim < 0) $sim = 0.0;
    return (float)$sim;
}

/** Roteia a métrica escolhida */
function similaridadePercentual($a, $b, $metrica) {
    if ($metrica === 'levenshtein') {
        return sim_por_levenshtein($a, $b);
    }
    // default: caracteres
    return sim_por_caracteres($a, $b);
}

/* =========================
   Leitura e Renderização
   ========================= */

function carregarDadosPlanilha($arquivoPath) {
    $spreadsheet = IOFactory::load($arquivoPath);
    return $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
}

/**
 * Marca diferenças em $dadosA e $dadosB com base no THRESHOLD e métrica.
 * Cabeçalho na linha 1 (A,B,C...), dados a partir da 2.
 * Coloca ["__DIFF__", valor] quando similaridade < threshold.
 */
function marcarDiferencas(&$dadosA, &$dadosB, int $threshold, string $metrica) {
    $rows = max(count($dadosA), count($dadosB));
    $cols = array_keys($dadosA[1] ?? $dadosB[1] ?? []); // A,B,C...

    for ($i = 2; $i <= $rows; $i++) {
        foreach ($cols as $col) {
            $aVal = $dadosA[$i][$col] ?? '';
            $bVal = $dadosB[$i][$col] ?? '';
            $sim  = similaridadePercentual($aVal, $bVal, $metrica);

            if ($sim < $threshold) {
                if (isset($dadosA[$i][$col])) $dadosA[$i][$col] = ["__DIFF__", $aVal];
                if (isset($dadosB[$i][$col])) $dadosB[$i][$col] = ["__DIFF__", $bVal];
            }
        }
    }
}

/** Tabela HTML (A ou B) com classes de diferença aplicadas */
function htmlTabelaComDiff($dados, $titulo) {
    if (!$dados || count($dados) === 0) return "<p>Sem dados</p>";

    $cabecalho = $dados[1] ?? [];
    $ths = '';
    foreach ($cabecalho as $val) {
        $v = htmlspecialchars((string)$val);
        $ths .= "<th class=\"sticky-top\" title=\"{$v}\">{$v}</th>";
    }

    $tbody = '';
    $total = count($dados);
    for ($i = 2; $i <= $total; $i++) {
        if (!isset($dados[$i])) continue;
        $linha = $dados[$i];
        $tds = '';
        $rowHasDiff = false;

        foreach ($cabecalho as $colKey => $ignored) {
            $cel   = $linha[$colKey] ?? '';
            $isDiff = is_array($cel) && ($cel[0] === "__DIFF__");
            if ($isDiff) { $rowHasDiff = true; }
            $val    = $isDiff ? $cel[1] : $cel;
            $valStr = htmlspecialchars((string)$val);
            $cls    = $isDiff ? ' class="diff-cell"' : '';
            $tds   .= "<td{$cls} title=\"{$valStr}\">{$valStr}</td>";
        }

        $trClass = $rowHasDiff ? ' class="row-has-diff"' : '';
        $tbody .= "<tr{$trClass}>{$tds}</tr>";
    }

    return <<<HTML
    <div class="pane">
      <div class="pane-header"><h5 class="m-0">{$titulo}</h5></div>
      <div class="pane-table" id="pane-{$titulo}">
        <table class="table table-bordered table-sm table-hover mb-0">
          <thead><tr>{$ths}</tr></thead>
          <tbody>{$tbody}</tbody>
        </table>
      </div>
    </div>
HTML;
}

/* =========================
   Persistência dos uploads
   ========================= */

function salvarUploadSeEnviado($campo, $prefixo = 'cmp_') {
    if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] !== UPLOAD_ERR_OK) return null;
    $upload_dir = __DIR__ . '/uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    $dest = $upload_dir . $prefixo . uniqid() . '.xlsx';
    if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $dest)) return null;
    return $dest;
}

/* =========================
   Controller
   ========================= */

$erro = null; $htmlA = $htmlB = '';
$threshold = isset($_POST['threshold']) ? max(0, min(100, (int)$_POST['threshold'])) : 80;
$metrica   = isset($_POST['metrica']) ? $_POST['metrica'] : 'caracteres'; // 'caracteres' | 'levenshtein'

// Caminhos persistidos entre submits
$pathA = $_POST['pathA'] ?? null;
$pathB = $_POST['pathB'] ?? null;

// Se o usuário enviou novos arquivos, salvamos e sobrescrevemos os paths
$novoA = salvarUploadSeEnviado('arquivoA', 'A_');
$novoB = salvarUploadSeEnviado('arquivoB', 'B_');
if ($novoA) $pathA = $novoA;
if ($novoB) $pathB = $novoB;

// Se tiver paths válidos, comparamos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$pathA || !$pathB || !file_exists($pathA) || !file_exists($pathB)) {
        $erro = "Envie os dois arquivos (.xlsx) para comparar.";
    } else {
        try {
            $dadosA = carregarDadosPlanilha($pathA);
            $dadosB = carregarDadosPlanilha($pathB);

            // Valida cabeçalhos compatíveis
            $cabA = $dadosA[1] ?? [];
            $cabB = $dadosB[1] ?? [];
            if (implode('|', $cabA) !== implode('|', $cabB)) {
                $erro = "As planilhas possuem colunas diferentes. Gere ambas pelo mesmo modelo antes de comparar.";
            } else {
                marcarDiferencas($dadosA, $dadosB, $threshold, $metrica);
                $htmlA = htmlTabelaComDiff($dadosA, "A");
                $htmlB = htmlTabelaComDiff($dadosB, "B");
            }
        } catch (Throwable $t) {
            $erro = "Falha ao ler as planilhas: " . $t->getMessage();
        }
    }
    
}
// ==== Exportação PDF (somente diferenças) ====
if (($_SERVER['REQUEST_METHOD'] === 'POST') && isset($_POST['export_pdf']) && $_POST['export_pdf'] === '1') {
    ini_set('memory_limit', '1G');

    if (!$pathA || !$pathB || !file_exists($pathA) || !file_exists($pathB)) {
        $erro = "Para exportar o PDF, envie os dois arquivos (.xlsx) e atualize a comparação.";
    } else {
        $dadosA = carregarDadosPlanilha($pathA);
        $dadosB = carregarDadosPlanilha($pathB);

        $cabA = $dadosA[1] ?? [];
        $cabB = $dadosB[1] ?? [];
        if (implode('|', $cabA) !== implode('|', $cabB)) {
            $erro = "As planilhas possuem colunas diferentes. Gere ambas pelo mesmo modelo antes de comparar.";
        } else {
            marcarDiferencas($dadosA, $dadosB, $threshold, $metrica);

            $cabecalho = $dadosA[1] ?? [];
            $colKeys = array_keys($cabecalho);
            $total = max(count($dadosA), count($dadosB));

            // 1) Detectar quais colunas têm pelo menos uma diferença
            $colsComDiff = [];
            $linhasComDiff = []; // índices das linhas com diff
            for ($i = 2; $i <= $total; $i++) {
                $hasDiff = false;
                foreach ($colKeys as $ck) {
                    $celA = $dadosA[$i][$ck] ?? '';
                    $celB = $dadosB[$i][$ck] ?? '';
                    if ((is_array($celA) && $celA[0] === '__DIFF__') || (is_array($celB) && $celB[0] === '__DIFF__')) {
                        $colsComDiff[$ck] = true;
                        $hasDiff = true;
                    }
                }
                if ($hasDiff) $linhasComDiff[] = $i;
            }

            // 2) Colunas de identificação: as primeiras colunas (até 3) que NÃO têm diff
            //    servem como contexto (ex: Cód. Condomínio, Bloco, Unidade, Nome)
            $colsId = [];
            foreach ($colKeys as $ck) {
                if (isset($colsComDiff[$ck])) continue;
                $colsId[$ck] = true;
                if (count($colsId) >= 3) break;
            }

            // 3) Colunas finais para o PDF: identificação + diferenças
            $pdfCols = array_keys($colsId + $colsComDiff);

            // 4) Montar HTML
            $thsHtml = '<th>Ln</th>';
            foreach ($pdfCols as $ck) {
                $thsHtml .= '<th>' . htmlspecialchars((string)$cabecalho[$ck]) . '</th>';
            }

            $rowsA = '';
            $rowsB = '';
            foreach ($linhasComDiff as $i) {
                $tdsA = '<td>' . ($i - 1) . '</td>';
                $tdsB = '<td>' . ($i - 1) . '</td>';
                foreach ($pdfCols as $ck) {
                    $celA = $dadosA[$i][$ck] ?? '';
                    $celB = $dadosB[$i][$ck] ?? '';
                    $isDiffA = is_array($celA) && $celA[0] === '__DIFF__';
                    $isDiffB = is_array($celB) && $celB[0] === '__DIFF__';
                    $valA = htmlspecialchars((string)($isDiffA ? $celA[1] : $celA));
                    $valB = htmlspecialchars((string)($isDiffB ? $celB[1] : $celB));
                    $clsA = $isDiffA ? ' class="diff"' : '';
                    $clsB = $isDiffB ? ' class="diff"' : '';
                    $tdsA .= "<td{$clsA}>{$valA}</td>";
                    $tdsB .= "<td{$clsB}>{$valB}</td>";
                }
                $rowsA .= "<tr>{$tdsA}</tr>";
                $rowsB .= "<tr>{$tdsB}</tr>";
            }

            $diffCount = count($linhasComDiff);
            $totalLinhasA = count($dadosA) - 1;
            $numDiffCols = count($colsComDiff);
            $numPdfCols = count($pdfCols);
            $colsListHtml = '';
            foreach (array_keys($colsComDiff) as $ck) {
                $colsListHtml .= ($colsListHtml ? ', ' : '') . htmlspecialchars((string)$cabecalho[$ck]);
            }

            $htmlPdf = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
  @page { margin: 10mm 8mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #04193b; margin: 0; }
  h2 { font-size: 13px; margin: 0 0 4px; }
  .info { font-size: 8px; color: #8c8c9c; margin-bottom: 4px; }
  .cols-info { font-size: 7px; color: #8c8c9c; margin-bottom: 8px; }
  h3 { font-size: 10px; margin: 10px 0 4px; padding: 3px 8px; background: #04193b; color: #fff; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  th { background: #b8b8c4; padding: 3px 5px; text-align: left; font-size: 7.5px; }
  td { padding: 2px 5px; border-bottom: 1px solid #b8b8c4; }
  .diff { background: #b0d4ff; font-weight: 600; }
  .page-break { page-break-before: always; }
</style>
</head><body>
  <h2>Comparativo de Planilhas — Diferenças</h2>
  <div class="info">
    Similaridade mínima: {$threshold}% | Métrica: {$metrica} | {$diffCount} linhas com diferença de {$totalLinhasA} totais | {$numDiffCols} colunas com diferença
  </div>
  <div class="cols-info">Colunas com diferença: {$colsListHtml}</div>
  <h3>Planilha A</h3>
  <table><thead><tr>{$thsHtml}</tr></thead><tbody>{$rowsA}</tbody></table>
  <div class="page-break"></div>
  <h3>Planilha B</h3>
  <table><thead><tr>{$thsHtml}</tr></thead><tbody>{$rowsB}</tbody></table>
</body></html>
HTML;

            // Liberar memória dos dados antes do render
            unset($dadosA, $dadosB, $rowsA, $rowsB);

            $dompdf = new Dompdf();
            $dompdf->set_option('isRemoteEnabled', false);
            $dompdf->set_option('defaultFont', 'DejaVu Sans');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->loadHtml($htmlPdf, 'UTF-8');
            unset($htmlPdf);
            $dompdf->render();
            $dompdf->stream('comparacao_diferencas.pdf', ['Attachment' => true]);
            exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Comparar Planilhas — Cleanalyze</title>
  <?php include __DIR__ . '/includes/head.php'; ?>
  <style>
    .btn-outline-secondary{ border-color: var(--cinza); color: var(--azul); }
    .pane-grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .pane{
      display:flex; flex-direction:column; min-width:0; background:#fff; border:1px solid var(--cinza); border-radius:.5rem;
    }
    .pane-header{ padding:.5rem .75rem; border-bottom:1px solid var(--cinza); background:var(--cinzaClaro); }
    .pane-table{ overflow-x:auto; }
    .pane-table table{ width: max-content; border-collapse: separate; }
    .pane-table th, .pane-table td{
      white-space: nowrap;  /* não quebra linha */
      line-height: 1.25rem; /* altura uniforme */
    }
    table thead th.sticky-top{
      position: sticky; top: 0; z-index: 2; background:var(--cinza) !important; color:var(--azul);
    }
    .diff-cell{ background:#b0d4ff !important; } /* highlight diferença */
        /* força esconder a linha, independente do CSS do Bootstrap */

    @media (max-width: 992px){
      .pane-grid{ grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<?php $activePage = 'comparar'; include __DIR__ . '/includes/navbar.php'; ?>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingComparar">
  <div class="loading-logo">Cleanalyze <span>IA</span></div>
  <div class="loading-steps">
    <div class="loading-step" id="cmp-upload">
      <div class="l-icon">&#8593;</div>
      <div class="l-text">Enviando planilhas<span class="l-dots"></span></div>
    </div>
    <div class="loading-step" id="cmp-read">
      <div class="l-icon">&#9783;</div>
      <div class="l-text">Lendo dados das planilhas<span class="l-dots"></span><div class="l-detail" id="cmp-read-d"></div></div>
    </div>
    <div class="loading-step" id="cmp-compare">
      <div class="l-icon">&#8644;</div>
      <div class="l-text">Comparando celula a celula<span class="l-dots"></span><div class="l-detail" id="cmp-compare-d"></div></div>
    </div>
    <div class="loading-step" id="cmp-render">
      <div class="l-icon">&#9998;</div>
      <div class="l-text">Montando visualizacao<span class="l-dots"></span><div class="l-detail" id="cmp-render-d"></div></div>
    </div>
  </div>
  <div class="loading-footer">2025 &copy; Desenvolvimento BBZ.</div>
</div>

  <?php $temResultado = !$erro && $htmlA && $htmlB; ?>
  <div class="<?= $temResultado ? 'container-fluid px-4' : 'container' ?> py-4">
      <div class="card">
        <div class="card-body">
          <h4 class="card-title">Comparar Planilhas XLSX</h4>
          <p class="text-secondary">Envie dois arquivos gerados pelo sistema para ver as diferenças lado a lado (A à esquerda, B à direita).</p>

          <form action="comparar.php" method="post" enctype="multipart/form-data" class="row g-3 mb-2" id="formComparar">
            <!-- Persistência dos caminhos salvos -->
            <input type="hidden" name="pathA" value="<?= htmlspecialchars((string)$pathA) ?>">
            <input type="hidden" name="pathB" value="<?= htmlspecialchars((string)$pathB) ?>">

            <div class="col-md-6">
              <label class="form-label">Arquivo A (.xlsx)</label>
              <input type="file" name="arquivoA" accept=".xlsx" class="form-control">
              <?php if ($pathA): ?><div class="form-text">Atual: <?= htmlspecialchars(basename($pathA)) ?></div><?php endif; ?>
            </div>

            <div class="col-md-6">
              <label class="form-label">Arquivo B (.xlsx)</label>
              <input type="file" name="arquivoB" accept=".xlsx" class="form-control">
              <?php if ($pathB): ?><div class="form-text">Atual: <?= htmlspecialchars(basename($pathB)) ?></div><?php endif; ?>
            </div>

            <div class="col-md-3">
              <label for="threshold" class="form-label">Similaridade mínima (%)</label>
              <input type="range" class="form-range" min="0" max="100" step="1" id="threshold" name="threshold"
                     value="<?= htmlspecialchars((string)$threshold) ?>"
                     oninput="document.getElementById('thresholdValue').textContent=this.value+'%';">
              <div><span id="thresholdValue"><?= htmlspecialchars((string)$threshold) ?>%</span></div>
            </div>

            <div class="col-md-3">
              <label for="metrica" class="form-label">Métrica</label>
              <select name="metrica" id="metrica" class="form-select">
                <option value="caracteres" <?= $metrica==='caracteres'?'selected':''; ?>>Caracteres (similar_text)</option>
                <option value="levenshtein" <?= $metrica==='levenshtein'?'selected':''; ?>>Levenshtein (normalizado)</option>
              </select>
            </div>

            <div class="col-12 d-flex align-items-center gap-3">
              <button type="submit" class="btn btn-primary">Atualizar comparação</button>
              <input type="hidden" name="export_pdf" id="export_pdf" value="0">
              <button type="button" id="btnPdf" class="btn btn-outline-secondary btn-sm">Baixar comparação (PDF)</button>
              <span id="diffCount" class="text-secondary small ms-2"></span>
            </div>
          </form>

          <?php if ($erro): ?>
            <div class="alert alert-danger mt-3"><?= htmlspecialchars($erro) ?></div>
          <?php elseif ($htmlA && $htmlB): ?>
            <div class="pane-grid mt-3">
              <?= $htmlA ?>
              <?= $htmlB ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
  </div>

<footer class="text-center text-muted my-4">2025 &copy; Desenvolvimento BBZ.</footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Sincroniza rolagem vertical e horizontal entre A e B
    const a = document.querySelector('#pane-A');
    const b = document.querySelector('#pane-B');
    if (a && b){
      let lock = false;
      function sync(from, to){
        if (lock) return;
        lock = true;
        to.scrollTop  = from.scrollTop;
        to.scrollLeft = from.scrollLeft;
        lock = false;
      }
      a.addEventListener('scroll', ()=>sync(a,b));
      b.addEventListener('scroll', ()=>sync(b,a));
    }

    // Contagem de diferenças (linhas/células)
    function contarDiferencas() {
      const aRows = document.querySelectorAll('#pane-A table tbody tr');
      let linhasComDiff = 0, celulasDiff = 0;
      aRows.forEach((tr) => {
        const has = tr.querySelector('.diff-cell') !== null;
        if (has) {
          linhasComDiff++;
          celulasDiff += tr.querySelectorAll('.diff-cell').length;
        }
      });
      const tag = document.getElementById('diffCount');
      if (tag) tag.textContent = `Linhas com diferença: ${linhasComDiff} | Células diferentes: ${celulasDiff}`;
    }

    // Exporta diferenças para CSV
    function extrairCabecalho(table) {
      return [...table.tHead.rows[0].cells].map(th => th.textContent.trim());
    }
    function rowToArray(tr) {
      return [...tr.cells].map(td => td.textContent.replace(/\s+/g,' ').trim());
    }
    function gerarCsvDif() {
      const tA = document.querySelector('#pane-A table');
      const tB = document.querySelector('#pane-B table');
      if (!tA || !tB) return;

      const head = extrairCabecalho(tA);
      const aRows = tA.tBodies[0].rows;
      const bRows = tB.tBodies[0].rows;
      const n = Math.min(aRows.length, bRows.length);

      const linhas = [];
      linhas.push(['Coluna','Valor A','Valor B','Linha'].join(';'));

      for (let i = 0; i < n; i++) {
        const aHas = aRows[i].querySelector('.diff-cell');
        const bHas = bRows[i].querySelector('.diff-cell');
        if (!aHas && !bHas) continue;

        const arrA = rowToArray(aRows[i]);
        const arrB = rowToArray(bRows[i]);

        for (let c = 0; c < head.length; c++) {
          if (aRows[i].cells[c]?.classList.contains('diff-cell') || bRows[i].cells[c]?.classList.contains('diff-cell')) {
            const linha = [head[c], arrA[c] ?? '', arrB[c] ?? '', (i+1).toString()];
            linhas.push(linha.map(v => `"${v.replace(/"/g,'""')}"`).join(';'));
          }
        }
      }

      const blob = new Blob([linhas.join('\r\n')], {type: 'text/csv;charset=utf-8;'});
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = 'diferencas.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    document.getElementById('btnPdf')?.addEventListener('click', function () {
      const form = this.closest('form');
      if (!form) return;
      document.getElementById('export_pdf').value = '1';
      // Submit direto (sem loading) — é um download
      form._skipLoading = true;
      form.submit();
      // Reset para proximos submits usarem loading
      setTimeout(function() { document.getElementById('export_pdf').value = '0'; form._skipLoading = false; }, 500);
    });

    // Inicializações
    window.addEventListener('load', () => {
      contarDiferencas();
    });

    </script>

<?php include __DIR__ . '/includes/loading-overlay.php'; ?>
<script>
(function() {
  var form = document.getElementById('formComparar');
  if (!form) return;

  // Sobrescrever: só interceptar quando NÃO for export PDF
  form.addEventListener('submit', function(e) {
    if (form._skipLoading) return; // Deixar submit normal para PDF export
    if (document.getElementById('export_pdf').value === '1') return;

    e.preventDefault();

    var btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;

    var overlay = document.getElementById('loadingComparar');
    overlay.classList.add('active');

    var steps = [
      { id: 'cmp-upload', delay: 0, doneText: 'Planilhas enviadas' },
      { id: 'cmp-read', delay: 800, detailId: 'cmp-read-d', detail: 'Parseando celulas e abas...', doneText: 'Dados lidos' },
      { id: 'cmp-compare', delay: 2500, detailId: 'cmp-compare-d',
        details: [
          { at: 0, text: 'Calculando similaridade entre celulas...' },
          { at: 1500, text: 'Identificando diferencas...' },
        ],
        doneText: 'Diferencas mapeadas'
      },
      { id: 'cmp-render', delay: 5000, detailId: 'cmp-render-d', detail: 'Gerando tabelas lado a lado...', doneText: 'Pronto!' },
    ];

    var stepTimers = [];

    function activateStep(stepIndex) {
      var step = steps[stepIndex];
      var el = document.getElementById(step.id);
      for (var i = 0; i < stepIndex; i++) {
        var prev = steps[i];
        var prevEl = document.getElementById(prev.id);
        if (!prevEl.classList.contains('done')) {
          prevEl.classList.remove('active');
          prevEl.classList.add('done');
          prevEl.querySelector('.l-icon').innerHTML = '&#10003;';
          var dots = prevEl.querySelector('.l-dots');
          if (dots) dots.style.display = 'none';
          if (prev.doneText) { var d = prevEl.querySelector('.l-detail'); if (d) d.textContent = prev.doneText; }
        }
      }
      el.classList.add('active');
      if (step.detail && step.detailId) document.getElementById(step.detailId).textContent = step.detail;
      if (step.details && step.detailId) {
        step.details.forEach(function(d) {
          var t = setTimeout(function() { if (el.classList.contains('active')) document.getElementById(step.detailId).textContent = d.text; }, d.at);
          stepTimers.push(t);
        });
      }
    }

    steps.forEach(function(step, idx) { var t = setTimeout(function() { activateStep(idx); }, step.delay); stepTimers.push(t); });

    var formData = new FormData(form);
    fetch(form.action, { method: 'POST', body: formData })
    .then(function(r) { return r.text(); })
    .then(function(html) {
      steps.forEach(function(s, idx) { activateStep(idx); });
      var last = steps[steps.length - 1];
      var lastEl = document.getElementById(last.id);
      lastEl.classList.remove('active'); lastEl.classList.add('done');
      lastEl.querySelector('.l-icon').innerHTML = '&#10003;';
      var dots = lastEl.querySelector('.l-dots'); if (dots) dots.style.display = 'none';
      if (last.doneText && last.detailId) document.getElementById(last.detailId).textContent = last.doneText;
      setTimeout(function() { document.open(); document.write(html); document.close(); }, 600);
    })
    .catch(function(err) {
      stepTimers.forEach(clearTimeout);
      overlay.classList.remove('active');
      if (btn) btn.disabled = false;
      alert('Erro ao processar: ' + err.message);
    });
  });
})();
</script>

</body>
</html>
