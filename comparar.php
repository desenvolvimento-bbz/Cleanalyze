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
// ==== Exportação PDF (somente tabelas) ====
if (($_SERVER['REQUEST_METHOD'] === 'POST') && isset($_POST['export_pdf']) && $_POST['export_pdf'] === '1') {
    // Segurança: garantir que temos os caminhos e conseguimos carregar dados
    if (!$pathA || !$pathB || !file_exists($pathA) || !file_exists($pathB)) {
        // volta para a página com erro simples
        $erro = "Para exportar o PDF, envie os dois arquivos (.xlsx) e atualize a comparação.";
    } else {
        // Reconstrói os dados/tabelas com o threshold/métrica atuais
        $dadosA = carregarDadosPlanilha($pathA);
        $dadosB = carregarDadosPlanilha($pathB);

        // Se as colunas não baterem, aborta export
        $cabA = $dadosA[1] ?? [];
        $cabB = $dadosB[1] ?? [];
        if (implode('|', $cabA) !== implode('|', $cabB)) {
            $erro = "As planilhas possuem colunas diferentes. Gere ambas pelo mesmo modelo antes de comparar.";
        } else {
            marcarDiferencas($dadosA, $dadosB, $threshold, $metrica);
            $htmlA = htmlTabelaComDiff($dadosA, "A");
            $htmlB = htmlTabelaComDiff($dadosB, "B");

            // HTML minimalista só com as tabelas, ajustando estilos para PDF
            $htmlPdf = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 11px; color: #04193b; }
  h2 { margin: 0 0 12px 0; }
  .wrap { display: flex; gap: 10px; }
  /* neutraliza sticky/overflow do HTML da página */
  .pane { border: none; }
  .pane-header { padding: 4px 0; border: none; background: #fff; }
  .pane-table { overflow: visible; }
  .sticky-top { position: static !important; }
  table { border-collapse: collapse; }
  th, td { border: 1px solid #b8b8c4; padding: 4px 6px; white-space: nowrap; }
  thead th { background: #b8b8c4; }
  .diff-cell { background: #f8d7da; }
</style>
</head>
<body>
  <h2>Comparação de Planilhas</h2>
  <div style="margin-bottom:8px;">
    Similaridade mínima: {$threshold}% &nbsp;|&nbsp; Métrica: {$metrica}
  </div>
  <div class="wrap">
    {$htmlA}
    {$htmlB}
  </div>
</body>
</html>
HTML;

            // Dompdf
            $dompdf = new Dompdf();
            $dompdf->set_option('isRemoteEnabled', true);
            $dompdf->set_option('defaultFont', 'DejaVu Sans'); // acentuação
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->loadHtml($htmlPdf, 'UTF-8');
            $dompdf->render();
            $dompdf->stream('comparacao.pdf', ['Attachment' => true]);
            exit;
            exit; // termina a request aqui
        }
    }
    // Se chegou aqui com $erro, a página renderiza a mensagem como já faz
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <title>Comparar Planilhas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    :root{ --azul:#04193b; --cinza:#b8b8c4; --cinzaClaro:#efeff4; }
    body{ background:var(--cinzaClaro); color:var(--azul); }
    .navbar{ background:var(--azul); }
    .navbar .navbar-brand, .navbar a{ color:#fff !important; }
    .btn-primary{ background:var(--azul); border-color:var(--azul); }
    .btn-outline-secondary{ border-color: var(--cinza); color: var(--azul); }
    .card{ border-color:var(--cinza); }

    .pane-grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      height: calc(100vh - 320px);
      min-height: 520px;
    }
    .pane{
      display:flex; flex-direction:column; min-width:0; background:#fff; border:1px solid var(--cinza); border-radius:.5rem;
    }
    .pane-header{ padding:.5rem .75rem; border-bottom:1px solid var(--cinza); background:var(--cinzaClaro); }
    .pane-table{ flex:1; overflow:auto; }
    /* Horizontal: largura natural, com rolagem */
    .pane-table table{ width: max-content; border-collapse: separate; }
    .pane-table th, .pane-table td{
      white-space: nowrap;  /* não quebra linha */
      line-height: 1.25rem; /* altura uniforme */
    }
    table thead th.sticky-top{
      position: sticky; top: 0; z-index: 2; background:var(--cinza) !important; color:var(--azul);
    }
    .diff-cell{ background:#f8d7da !important; } /* highlight diferença */
        /* força esconder a linha, independente do CSS do Bootstrap */

    @media (max-width: 992px){
      .pane-grid{ grid-template-columns: 1fr; height: auto; }
    }
    .sidebar-collapsed {
  width: 60px !important;
    }
    .sidebar-collapsed .list-group-item {
      text-align: center;
      padding-left: 0;
      padding-right: 0;
    }
    .sidebar-collapsed .list-group-item span {
      display: none; /* Esconde textos */
    }
    /* opcional: anima largura da sidebar */
    #sidebarCol { 
      transition: flex-basis .25s ease, max-width .25s ease, width .25s ease;
    }
    .sidebar-slim {
      flex: 0 0 0 !important;
      max-width: 0 !important;
      width: 0 !important;
      overflow: hidden;
    }
  </style>
</head>
<body>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&display=swap" rel="stylesheet">
<nav class="navbar navbar-expand-lg" style="font-family: 'Manrope', sans-serif;">
  <div class="container">
    <a class="navbar-brand" href="index.php">Cleanalyze BBZ</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
      aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? ' active' : '' ?>" href="index.php">
            Extrair
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= basename($_SERVER['PHP_SELF']) === 'comparar.php' ? ' active' : '' ?>" href="comparar.php">
            Comparar
          </a>
        </li>
        <?php if (auth_is_admin()): ?>
        <li class="nav-item">
          <a class="nav-link<?= basename($_SERVER['PHP_SELF']) === 'invite.php' ? ' active' : '' ?>" href="auth/invite.php">
            Gerenciar Convites
          </a>
        </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>


  <div class="container py-4">
  <div class="row g-4">
    <!-- Lateral: menu vertical -->
    <aside class="col-md-3" id="sidebarCol">
      <h4 class="m-0">Menu</h4>
      <div class="list-group" style="font-family: 'Manrope', sans-serif;">
        <a href="index.php"
           class="list-group-item list-group-item-action<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? ' active' : '' ?>">
          📄 Extrair (PDF → XLSX)
        </a>
        <a href="comparar.php"
           class="list-group-item list-group-item-action<?= basename($_SERVER['PHP_SELF']) === 'comparar.php' ? ' active' : '' ?>">
          🔍 Comparar (A × B)
        </a>
        <?php if (auth_is_admin()): ?>
        <a href="auth/invite.php"
           class="list-group-item list-group-item-action<?= basename($_SERVER['PHP_SELF']) === 'invite.php' ? ' active' : '' ?>">
          🛠️ Gerenciar Convites
        </a>
        <?php endif; ?>
      </div>
    </aside>

    <!-- Conteúdo principal: Comparação -->
    <main class="col-md-9" id="contentCol">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary">⮜ Ocultar menu</button>
      </div>
      <div class="card">
        <div class="card-body">
          <h4 class="card-title">🔍 Comparar Planilhas XLSX</h4>
          <p class="text-secondary">Envie dois arquivos gerados pelo sistema para ver as diferenças lado a lado (A à esquerda, B à direita).</p>

          <form action="comparar.php" method="post" enctype="multipart/form-data" class="row g-3 mb-2">
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
    </main>
  </div>
</div>
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
      form.submit();
    });

    // Inicializações
    window.addEventListener('load', () => {
      contarDiferencas();
    });


  (function() {
    const btn  = document.getElementById('toggleSidebar');
    const side = document.getElementById('sidebarCol');
    const main = document.getElementById('contentCol');

    if (!btn || !side || !main) return;

    let hidden = false;
    btn.addEventListener('click', function () {
      hidden = !hidden;

      if (hidden) {
        side.classList.add('d-none');
        main.classList.remove('col-md-9');
        main.classList.add('col-md-12');
        btn.textContent = '⮞ Mostrar menu';
      } else {
        side.classList.remove('d-none');
        main.classList.remove('col-md-12');
        main.classList.add('col-md-9');
        btn.textContent = '⮜ Ocultar menu';
      }
    });
  })();
    </script>
  
</body>
</html>
