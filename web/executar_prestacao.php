<?php
// Página descontinuada — redirecionar para nova versão
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: ../prestacao_anual.php', true, 301);
    exit;
}
/**
 * executar_prestacao.php (DESCONTINUADO — mantido temporariamente para sessões ativas)
 *
 * Recebe dois PDFs de Prestação de Contas, processa via Python CLI,
 * e exibe resultado com cards de diferenças + exportação PDF/XLSX.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../auth/bootstrap.php';
auth_require_login();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/paths.php';

use Dompdf\Dompdf;

$PYTHON      = APP_PYTHON;
$BASE        = APP_BASE;
$UPLOADS_DIR = APP_UPLOADS;
@mkdir($UPLOADS_DIR, 0777, true);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

function fmt_brl($v) {
    if ($v === null || $v === '') return 'R$ 0,00';
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

// ===========================================================================
// PDF EXPORT (via POST)
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_pdf']) && $_POST['export_pdf'] === '1') {
    $jsonPath = $_POST['json_path'] ?? '';
    $realJson = $jsonPath ? realpath($jsonPath) : false;
    $realUploads = realpath($UPLOADS_DIR);
    if (!$realJson || !$realUploads || stripos($realJson, $realUploads) !== 0) {
        echo "Erro: caminho invalido.";
        exit;
    }
    $dados = json_decode(file_get_contents($jsonPath), true);
    if (!$dados) { echo "Erro ao ler JSON."; exit; }

    $htmlPdf = renderResultadoHTML($dados, true);

    $dompdf = new Dompdf();
    $dompdf->set_option('isRemoteEnabled', true);
    $dompdf->set_option('defaultFont', 'DejaVu Sans');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml($htmlPdf, 'UTF-8');
    $dompdf->render();

    $nomeArquivo = 'Comparativo_PrestContas_' . preg_replace('/[^A-Za-z0-9]/', '_', $dados['condominio'] ?? 'relatorio') . '.pdf';
    $dompdf->stream($nomeArquivo, ['Attachment' => true]);
    exit;
}

// ===========================================================================
// UPLOAD + PROCESSAMENTO
// ===========================================================================
$erros = [];
$ts = date('Ymd_His');

// PDF Mês Anterior
$pdfAnterior = $_FILES['pdf_anterior'] ?? null;
$pathAnterior = '';
if ($pdfAnterior && $pdfAnterior['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($pdfAnterior['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') { $erros[] = "O arquivo do mês anterior deve ser PDF."; }
    else {
        $safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', $pdfAnterior['name']);
        $pathAnterior = $UPLOADS_DIR . APP_SEP . $ts . '_anterior_' . $safeName;
        if (!move_uploaded_file($pdfAnterior['tmp_name'], $pathAnterior)) {
            $erros[] = "Falha ao salvar PDF do mês anterior.";
        }
    }
} else { $erros[] = "Envie o PDF do mês anterior."; }

// PDF Mês Atual
$pdfAtual = $_FILES['pdf_atual'] ?? null;
$pathAtual = '';
if ($pdfAtual && $pdfAtual['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($pdfAtual['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') { $erros[] = "O arquivo do mês atual deve ser PDF."; }
    else {
        $safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', $pdfAtual['name']);
        $pathAtual = $UPLOADS_DIR . APP_SEP . $ts . '_atual_' . $safeName;
        if (!move_uploaded_file($pdfAtual['tmp_name'], $pathAtual)) {
            $erros[] = "Falha ao salvar PDF do mês atual.";
        }
    }
} else { $erros[] = "Envie o PDF do mês atual."; }

$saidaBase = isset($_POST['saidaBase']) ? trim($_POST['saidaBase']) : '';
if (!$saidaBase) $saidaBase = 'Comparativo_PrestContas_' . $ts;
$saidaBase = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', $saidaBase);
if (!preg_match('/\.xlsx$/i', $saidaBase)) $saidaBase .= '.xlsx';
$saidaPath = $UPLOADS_DIR . APP_SEP . $saidaBase;
$jsonPath  = preg_replace('/\.xlsx$/i', '.json', $saidaPath);

if ($erros) {
    http_response_code(400);
    echo "<!DOCTYPE html><html><head><title>Erro</title>";
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo "</head><body><div class='container py-4'>";
    echo "<div class='alert alert-danger'><h5>Erros</h5><ul>";
    foreach ($erros as $e) echo "<li>" . h($e) . "</li>";
    echo "</ul></div><a href='../prestacao.php' class='btn btn-primary'>Voltar</a></div></body></html>";
    exit;
}

// Montar e executar comando Python
$cliScript = $BASE . APP_SEP . 'compare_prestacao_cli.py';
$cmd = escapeshellarg($PYTHON)
     . ' ' . escapeshellarg($cliScript)
     . ' --pdf-anterior ' . escapeshellarg($pathAnterior)
     . ' --pdf-atual '    . escapeshellarg($pathAtual)
     . ' --saida '        . escapeshellarg($saidaPath)
     . ' --json '         . escapeshellarg($jsonPath)
     . ' 2>&1';

$env = ['PYTHONIOENCODING' => 'utf-8'];
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($cmd, $descriptors, $pipes, $BASE, $env);
$output = '';
$exitCode = -1;
if (is_resource($process)) {
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($stderr) $output .= "\n" . $stderr;
}

$sucesso = $exitCode === 0 && file_exists($saidaPath) && file_exists($jsonPath);
$dados = $sucesso ? json_decode(file_get_contents($jsonPath), true) : null;

// ===========================================================================
// RENDER FUNCTIONS
// ===========================================================================

function renderResultadoHTML($dados, $paraPdf = false) {
    $css = '
    <style>
        :root{ --azul:#04193b; --cinza:#b8b8c4; --cinzaClaro:#efeff4; }
        body{ background:#fff; color:#04193b; font-family: DejaVu Sans, Arial, sans-serif; font-size:11px; }
        .header-bar{ background:#04193b; color:#fff; padding:12px 20px; margin-bottom:16px; }
        .header-bar h2{ margin:0; font-size:16px; }
        .header-bar p{ margin:2px 0 0; font-size:11px; opacity:.85; }
        .stat-row{ display:flex; gap:12px; margin-bottom:16px; }
        .stat-box{ flex:1; text-align:center; border:1px solid #ccc; border-radius:6px; padding:10px; }
        .stat-box .num{ font-size:22px; font-weight:700; }
        .stat-box .lbl{ font-size:10px; color:#666; }
        table{ width:100%; border-collapse:collapse; margin-bottom:14px; font-size:10px; }
        th{ background:#04193b; color:#fff; padding:5px 8px; text-align:left; }
        td{ padding:4px 8px; border-bottom:1px solid #ddd; }
        .badge-nova{ background:#fff2cc; color:#856404; padding:2px 8px; border-radius:4px; font-weight:600; font-size:9px; }
        .badge-ausente{ background:#fce4ec; color:#c62828; padding:2px 8px; border-radius:4px; font-weight:600; font-size:9px; }
        .badge-sub-nova{ background:#e2efda; color:#2e7d32; padding:2px 8px; border-radius:4px; font-weight:600; font-size:9px; }
        .badge-sub-removida{ background:#fbe5d6; color:#e65100; padding:2px 8px; border-radius:4px; font-weight:600; font-size:9px; }
        .row-nova td{ background:#fffde7; }
        .row-ausente td{ background:#fce4ec; }
        .row-sub-nova td{ background:#f1f8e9; }
        .row-sub-removida td{ background:#fff3e0; }
        .section-title{ font-size:13px; font-weight:700; margin:16px 0 8px; border-bottom:2px solid #04193b; padding-bottom:4px; }
        .lancamento{ font-size:9px; color:#555; padding-left:20px; }
        .card-diff{ border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:10px; }
        .card-diff-nova{ border-left:4px solid #ffc107; }
        .card-diff-ausente{ border-left:4px solid #ef5350; }
        .card-diff-sub-nova{ border-left:4px solid #66bb6a; }
        .card-diff-sub-removida{ border-left:4px solid #ff9800; }
        .valor-positivo{ color:#c62828; }
        .valor-negativo{ color:#2e7d32; }
        .page-break{ page-break-before:always; }
        .periodo-bar{ display:flex; margin-bottom:14px; border:1px solid #ccc; border-radius:6px; overflow:hidden; }
        .periodo-bar .periodo-col{ flex:1; text-align:center; padding:6px 10px; }
        .periodo-bar .periodo-ant{ background:#2E75B6; color:#fff; }
        .periodo-bar .periodo-atu{ background:#548235; color:#fff; }
        .periodo-bar .periodo-vs{ display:flex; align-items:center; padding:0 10px; font-weight:700; background:#efeff4; color:#04193b; }
        .periodo-bar .periodo-label{ font-size:8px; opacity:.8; }
        .periodo-bar .periodo-date{ font-size:11px; font-weight:700; }
    </style>';

    $condominio = htmlspecialchars($dados['condominio'] ?? '', ENT_QUOTES, 'UTF-8');
    $perAnt = htmlspecialchars($dados['periodoAnterior'] ?? '', ENT_QUOTES, 'UTF-8');
    $perAtu = htmlspecialchars($dados['periodoAtual'] ?? '', ENT_QUOTES, 'UTF-8');

    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'>{$css}</head><body>";

    // Header
    $html .= "<div class='header-bar'>";
    $html .= "<h2>Comparativo de Prestação de Contas</h2>";
    $html .= "<p><strong>{$condominio}</strong> — {$perAnt} vs {$perAtu}</p>";
    $html .= "</div>";

    // Stats
    $html .= "<div class='stat-row'>";
    $html .= "<div class='stat-box'><div class='num'>" . ($dados['lanctosAnterior'] ?? 0) . "</div><div class='lbl'>Lançamentos<br>Mês Anterior</div></div>";
    $html .= "<div class='stat-box'><div class='num'>" . ($dados['lanctosAtual'] ?? 0) . "</div><div class='lbl'>Lançamentos<br>Mês Atual</div></div>";
    $html .= "<div class='stat-box'><div class='num' style='color:#c00;'>" . count($dados['diferencas'] ?? []) . "</div><div class='lbl'>Diferenças<br>Encontradas</div></div>";
    $html .= "</div>";

    // Barra de Períodos
    $html .= "<div class='periodo-bar'>";
    $html .= "<div class='periodo-col periodo-ant'><div class='periodo-label'>Mês Anterior</div><div class='periodo-date'>{$perAnt}</div></div>";
    $html .= "<div class='periodo-vs'>vs</div>";
    $html .= "<div class='periodo-col periodo-atu'><div class='periodo-label'>Mês Atual</div><div class='periodo-date'>{$perAtu}</div></div>";
    $html .= "</div>";

    // Totais por Conta
    $html .= "<div class='section-title'>Totais por Conta</div>";
    $html .= "<table><tr><th>Seção</th><th>Conta</th><th style='text-align:right'>Anterior (R$)</th><th>%</th><th style='text-align:right'>Atual (R$)</th><th>%</th><th style='text-align:right'>Diferença</th><th>Var%</th><th>Status</th></tr>";
    foreach (($dados['totaisComparativo'] ?? []) as $t) {
        $rowClass = '';
        $badge = '';
        if ($t['obs'] === 'NOVA') { $rowClass = 'row-nova'; $badge = "<span class='badge-nova'>NOVA</span>"; }
        elseif ($t['obs'] === 'AUSENTE') { $rowClass = 'row-ausente'; $badge = "<span class='badge-ausente'>AUSENTE</span>"; }
        $diffClass = ($t['diferenca'] ?? 0) > 0 ? 'valor-positivo' : (($t['diferenca'] ?? 0) < 0 ? 'valor-negativo' : '');
        $varStr = $t['varPct'] !== null ? number_format($t['varPct'], 1) . '%' : '-';
        $html .= "<tr class='{$rowClass}'>";
        $html .= "<td>" . h($t['secao']) . "</td>";
        $html .= "<td><strong>" . h($t['conta']) . "</strong></td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($t['totalAnterior']) . "</td>";
        $html .= "<td>" . h($t['pctAnterior']) . "</td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($t['totalAtual']) . "</td>";
        $html .= "<td>" . h($t['pctAtual']) . "</td>";
        $html .= "<td style='text-align:right' class='{$diffClass}'>" . fmt_brl($t['diferenca']) . "</td>";
        $html .= "<td>{$varStr}</td>";
        $html .= "<td>{$badge}</td>";
        $html .= "</tr>";
    }
    $html .= "</table>";

    // Diferenças Detalhadas
    if ($paraPdf) $html .= "<div class='page-break'></div>";
    $html .= "<div class='section-title'>Diferenças Detalhadas</div>";

    $tipoConfig = [
        'CONTA NOVA'            => ['class' => 'card-diff-nova',         'badge' => 'badge-nova',         'label' => 'CONTA NOVA'],
        'CONTA AUSENTE'         => ['class' => 'card-diff-ausente',      'badge' => 'badge-ausente',      'label' => 'CONTA AUSENTE'],
        'SUBCATEGORIA NOVA'     => ['class' => 'card-diff-sub-nova',     'badge' => 'badge-sub-nova',     'label' => 'SUBCATEGORIA NOVA'],
        'SUBCATEGORIA REMOVIDA' => ['class' => 'card-diff-sub-removida', 'badge' => 'badge-sub-removida', 'label' => 'SUBCATEGORIA REMOVIDA'],
    ];

    foreach (($dados['diferencas'] ?? []) as $d) {
        $cfg = $tipoConfig[$d['tipo']] ?? ['class' => '', 'badge' => '', 'label' => $d['tipo']];
        $html .= "<div class='card-diff {$cfg['class']}'>";
        $html .= "<div><span class='{$cfg['badge']}'>{$cfg['label']}</span>";
        $html .= " <strong>" . h($d['conta']) . "</strong>";
        if (!empty($d['subcategoria'])) $html .= " &gt; " . h($d['subcategoria']);
        $html .= "</div>";

        // Valores
        $ant = ($d['totalAnterior'] ?? 0) ? fmt_brl($d['totalAnterior']) : '-';
        $atu = ($d['totalAtual'] ?? 0) ? fmt_brl($d['totalAtual']) : '-';
        $html .= "<div style='margin:4px 0; font-size:10px; color:#555;'>Anterior: {$ant} | Atual: {$atu}</div>";

        // Lançamentos
        if (!empty($d['lancamentos'])) {
            foreach ($d['lancamentos'] as $lanc) {
                $html .= "<div class='lancamento'>" . h($lanc) . "</div>";
            }
        }
        $html .= "</div>";
    }

    // Resumo Financeiro
    if ($paraPdf) $html .= "<div class='page-break'></div>";
    $html .= "<div class='section-title'>Resumo Financeiro Contábil</div>";
    $html .= "<table><tr><th>Categoria</th><th style='text-align:right'>Saldo Ant.</th><th style='text-align:right'>Créditos</th><th style='text-align:right'>Débitos</th><th style='text-align:right'>Saldo Atual</th><th></th><th style='text-align:right'>Saldo Ant.</th><th style='text-align:right'>Créditos</th><th style='text-align:right'>Débitos</th><th style='text-align:right'>Saldo Atual</th></tr>";
    $html .= "<tr><th colspan='5' style='text-align:center; background:#2E75B6;'>Mês Anterior</th><th style='background:#fff'></th><th colspan='4' style='text-align:center; background:#548235;'>Mês Atual</th></tr>";

    $resAnt = $dados['resumo']['anterior'] ?? [];
    $resAtu = $dados['resumo']['atual'] ?? [];
    $allCats = array_unique(array_merge(
        array_column($resAnt, 'categoria'),
        array_column($resAtu, 'categoria')
    ));
    foreach ($allCats as $cat) {
        $a = null; $n = null;
        foreach ($resAnt as $r) { if ($r['categoria'] === $cat) { $a = $r; break; } }
        foreach ($resAtu as $r) { if ($r['categoria'] === $cat) { $n = $r; break; } }
        $html .= "<tr>";
        $html .= "<td><strong>" . h($cat) . "</strong></td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($a['saldoAnterior'] ?? 0) . "</td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($a['creditos'] ?? 0) . "</td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($a['debitos'] ?? 0) . "</td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($a['saldoAtual'] ?? 0) . "</td>";
        $html .= "<td></td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($n['saldoAnterior'] ?? 0) . "</td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($n['creditos'] ?? 0) . "</td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($n['debitos'] ?? 0) . "</td>";
        $html .= "<td style='text-align:right'>" . fmt_brl($n['saldoAtual'] ?? 0) . "</td>";
        $html .= "</tr>";
    }
    $html .= "</table>";

    $html .= "</body></html>";
    return $html;
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Resultado — Prestação de Contas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&display=swap" rel="stylesheet">
  <style>
    :root{ --azul:#04193b; --cinza:#b8b8c4; --cinzaClaro:#efeff4; }
    body{ background:var(--cinzaClaro); color:var(--azul); font-family:'Manrope',sans-serif; }
    .navbar{ background:var(--azul); }
    .navbar .navbar-brand, .navbar a{ color:#fff !important; }
    .btn-primary{ background:var(--azul); border-color:var(--azul); }
    .card{ border-color:var(--cinza); }
    .stat-card{ text-align:center; padding:1.5rem; }
    .stat-card .number{ font-size:2.2rem; font-weight:700; }
    .stat-card .label{ font-size:.85rem; color:#666; }
    .badge-nova{ background:#fff2cc; color:#856404; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .badge-ausente{ background:#fce4ec; color:#c62828; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .badge-sub-nova{ background:#e2efda; color:#2e7d32; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .badge-sub-removida{ background:#fbe5d6; color:#e65100; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .diff-card{ border:1px solid #ddd; border-radius:8px; padding:14px; margin-bottom:10px; background:#fff; }
    .diff-card-nova{ border-left:5px solid #ffc107; }
    .diff-card-ausente{ border-left:5px solid #ef5350; }
    .diff-card-sub-nova{ border-left:5px solid #66bb6a; }
    .diff-card-sub-removida{ border-left:5px solid #ff9800; }
    .lancamento-item{ font-size:.8rem; color:#666; padding:2px 0 2px 16px; border-left:2px solid #ddd; margin:2px 0; }
    .totais-table th{ background:var(--azul); color:#fff; font-size:.8rem; }
    .totais-table td{ font-size:.85rem; vertical-align:middle; }
    .totais-table .row-nova td{ background:#fffde7; }
    .totais-table .row-ausente td{ background:#fce4ec; }
    .valor-pos{ color:#c62828; font-weight:600; }
    .valor-neg{ color:#2e7d32; font-weight:600; }
    .filter-btn{ cursor:pointer; }
    .filter-btn.active{ opacity:1; }
    .filter-btn:not(.active){ opacity:.5; }
  </style>
</head>
<body>
<?php $activePage = 'prestacao'; include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">

<?php if ($sucesso && $dados): ?>

  <!-- Header -->
  <div class="alert alert-success d-flex justify-content-between align-items-center">
    <div>
      <h5 class="mb-1">Comparativo gerado com sucesso!</h5>
      <p class="mb-0">
        <strong><?= h($dados['condominio'] ?? '') ?></strong> —
        <?= h($dados['periodoAnterior'] ?? '') ?> vs <?= h($dados['periodoAtual'] ?? '') ?>
      </p>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card stat-card">
        <div class="number"><?= h($dados['lanctosAnterior'] ?? 0) ?></div>
        <div class="label">Lançamentos<br><strong><?= h($dados['periodoAnterior'] ?? 'Mês Anterior') ?></strong></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stat-card">
        <div class="number"><?= h($dados['lanctosAtual'] ?? 0) ?></div>
        <div class="label">Lançamentos<br><strong><?= h($dados['periodoAtual'] ?? 'Mês Atual') ?></strong></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stat-card">
        <div class="number" style="color:#c00;"><?= count($dados['diferencas'] ?? []) ?></div>
        <div class="label">Diferenças<br>Encontradas</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stat-card">
        <div class="number" style="color:#2e7d32;"><?= count($dados['totaisComparativo'] ?? []) ?></div>
        <div class="label">Contas<br>Comparadas</div>
      </div>
    </div>
  </div>

  <!-- Action Buttons -->
  <div class="d-flex gap-2 mb-4 flex-wrap">
    <a href="download.php?f=<?= urlencode($saidaPath) ?>" class="btn btn-primary">
      📥 Baixar XLSX Detalhado
    </a>
    <form method="post" style="display:inline;">
      <input type="hidden" name="export_pdf" value="1">
      <input type="hidden" name="json_path" value="<?= h($jsonPath) ?>">
      <button type="submit" class="btn btn-outline-secondary">📄 Baixar Relatório PDF</button>
    </form>
    <a href="../prestacao.php" class="btn btn-outline-secondary">Nova Comparação</a>
  </div>

  <!-- Barra de Períodos -->
  <div class="d-flex mb-4 rounded overflow-hidden" style="border:1px solid var(--cinza);">
    <div class="flex-fill text-center py-2" style="background:#2E75B6; color:#fff;">
      <small class="d-block" style="opacity:.8;">Mês Anterior</small>
      <strong style="font-size:1.05rem;"><?= h($dados['periodoAnterior'] ?? '') ?></strong>
    </div>
    <div class="d-flex align-items-center px-3" style="background:var(--cinzaClaro); color:var(--azul); font-weight:700;">vs</div>
    <div class="flex-fill text-center py-2" style="background:#548235; color:#fff;">
      <small class="d-block" style="opacity:.8;">Mês Atual</small>
      <strong style="font-size:1.05rem;"><?= h($dados['periodoAtual'] ?? '') ?></strong>
    </div>
  </div>

  <!-- TOTAIS POR CONTA -->
  <div class="card mb-4">
    <div class="card-header" style="background:var(--azul); color:#fff;">
      <strong>Totais por Conta</strong>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover totais-table mb-0">
          <thead>
            <tr>
              <th>Seção</th><th>Conta</th>
              <th class="text-end">Anterior (R$)</th><th>%</th>
              <th class="text-end">Atual (R$)</th><th>%</th>
              <th class="text-end">Diferença</th><th>Var%</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach (($dados['totaisComparativo'] ?? []) as $t):
              $rowClass = '';
              $badge = '';
              if ($t['obs'] === 'NOVA') { $rowClass = 'row-nova'; $badge = '<span class="badge-nova">NOVA</span>'; }
              elseif ($t['obs'] === 'AUSENTE') { $rowClass = 'row-ausente'; $badge = '<span class="badge-ausente">AUSENTE</span>'; }
              $diffClass = ($t['diferenca'] ?? 0) > 0 ? 'valor-pos' : (($t['diferenca'] ?? 0) < 0 ? 'valor-neg' : '');
              $varStr = $t['varPct'] !== null ? number_format($t['varPct'], 1) . '%' : '-';
          ?>
            <tr class="<?= $rowClass ?>">
              <td><?= h($t['secao']) ?></td>
              <td><strong><?= h($t['conta']) ?></strong></td>
              <td class="text-end"><?= fmt_brl($t['totalAnterior']) ?></td>
              <td><?= h($t['pctAnterior']) ?></td>
              <td class="text-end"><?= fmt_brl($t['totalAtual']) ?></td>
              <td><?= h($t['pctAtual']) ?></td>
              <td class="text-end <?= $diffClass ?>"><?= fmt_brl($t['diferenca']) ?></td>
              <td><?= $varStr ?></td>
              <td><?= $badge ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- DIFERENÇAS DETALHADAS -->
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:#c00; color:#fff;">
      <strong>Diferenças Detalhadas (<?= count($dados['diferencas'] ?? []) ?>)</strong>
      <div class="d-flex gap-2">
        <span class="badge-nova filter-btn active" data-filter="CONTA NOVA" style="cursor:pointer;">CONTA NOVA</span>
        <span class="badge-ausente filter-btn active" data-filter="CONTA AUSENTE" style="cursor:pointer;">CONTA AUSENTE</span>
        <span class="badge-sub-nova filter-btn active" data-filter="SUBCATEGORIA NOVA" style="cursor:pointer;">SUBCAT NOVA</span>
        <span class="badge-sub-removida filter-btn active" data-filter="SUBCATEGORIA REMOVIDA" style="cursor:pointer;">SUBCAT REMOVIDA</span>
      </div>
    </div>
    <div class="card-body">
    <?php
    $tipoClasses = [
        'CONTA NOVA'            => 'diff-card-nova',
        'CONTA AUSENTE'         => 'diff-card-ausente',
        'SUBCATEGORIA NOVA'     => 'diff-card-sub-nova',
        'SUBCATEGORIA REMOVIDA' => 'diff-card-sub-removida',
    ];
    $tipoBadges = [
        'CONTA NOVA'            => 'badge-nova',
        'CONTA AUSENTE'         => 'badge-ausente',
        'SUBCATEGORIA NOVA'     => 'badge-sub-nova',
        'SUBCATEGORIA REMOVIDA' => 'badge-sub-removida',
    ];
    foreach (($dados['diferencas'] ?? []) as $d):
        $cardClass = $tipoClasses[$d['tipo']] ?? '';
        $badgeClass = $tipoBadges[$d['tipo']] ?? '';
        $ant = ($d['totalAnterior'] ?? 0) ? fmt_brl($d['totalAnterior']) : '-';
        $atu = ($d['totalAtual'] ?? 0) ? fmt_brl($d['totalAtual']) : '-';
    ?>
      <div class="diff-card <?= $cardClass ?>" data-tipo="<?= h($d['tipo']) ?>">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="<?= $badgeClass ?>"><?= h($d['tipo']) ?></span>
            <strong class="ms-2"><?= h($d['conta']) ?></strong>
            <?php if (!empty($d['subcategoria'])): ?>
              <span class="text-muted">&gt; <?= h($d['subcategoria']) ?></span>
            <?php endif; ?>
          </div>
          <div class="text-end" style="white-space:nowrap;">
            <small class="text-muted">Anterior:</small> <strong><?= $ant ?></strong>
            <span class="mx-1">|</span>
            <small class="text-muted">Atual:</small> <strong><?= $atu ?></strong>
          </div>
        </div>
        <?php if (!empty($d['lancamentos'])): ?>
          <div class="mt-2">
          <?php foreach ($d['lancamentos'] as $lanc): ?>
            <div class="lancamento-item"><?= h($lanc) ?></div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </div>

<?php else: ?>
  <div class="alert alert-danger">
    <h5>Erro ao processar</h5>
    <p>O comparativo não pôde ser gerado. Verifique se os PDFs são do formato esperado.</p>
  </div>
  <a href="../prestacao.php" class="btn btn-primary mb-4">Voltar</a>
<?php endif; ?>

  <?php if (auth_is_admin()): ?>
  <!-- Detalhes técnicos (somente admin) -->
  <div class="accordion mb-4" id="accDetails">
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#collapseDetails">
          Detalhes técnicos
        </button>
      </h2>
      <div id="collapseDetails" class="accordion-collapse collapse">
        <div class="accordion-body">
          <p><strong>Comando:</strong></p>
          <pre class="bg-dark text-light p-3 rounded" style="white-space:pre-wrap; font-size:.8rem;"><?= h($cmd) ?></pre>
          <p><strong>Saída (exit code <?= $exitCode ?>):</strong></p>
          <pre class="bg-dark text-light p-3 rounded" style="white-space:pre-wrap; max-height:300px; overflow-y:auto; font-size:.8rem;"><?= h($output) ?></pre>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<footer class="text-center text-muted my-4">2025 &copy; Desenvolvimento BBZ.</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Filter toggle for difference types
document.querySelectorAll('.filter-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    this.classList.toggle('active');
    const tipo = this.dataset.filter;
    const active = this.classList.contains('active');
    document.querySelectorAll('.diff-card[data-tipo="' + tipo + '"]').forEach(card => {
      card.style.display = active ? '' : 'none';
    });
  });
});
</script>
</body>
</html>
