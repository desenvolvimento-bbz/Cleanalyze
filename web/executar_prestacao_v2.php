<?php
// Página descontinuada — redirecionar para nova versão
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: ../prestacao_anual.php', true, 301);
    exit;
}
/**
 * executar_prestacao_v2.php (DESCONTINUADO — mantido temporariamente para sessões ativas)
 *
 * Versão v2 do resultado de comparação de Prestação de Contas.
 * Melhorias: cores corrigidas, alerta Fundo de Reserva, dropdown por conta.
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
// RENDER FUNCTION (para PDF export via DOMPDF)
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
        /* v2: cores corrigidas — NOVA=vermelho, AUSENTE=amarelo */
        .badge-nova{ background:#fce4ec; color:#c62828; padding:2px 8px; border-radius:4px; font-weight:600; font-size:9px; }
        .badge-ausente{ background:#fff2cc; color:#856404; padding:2px 8px; border-radius:4px; font-weight:600; font-size:9px; }
        .badge-sub-nova{ background:#fce4ec; color:#c62828; padding:2px 8px; border-radius:4px; font-weight:600; font-size:9px; }
        .badge-sub-removida{ background:#fff2cc; color:#856404; padding:2px 8px; border-radius:4px; font-weight:600; font-size:9px; }
        .badge-fundo{ background:#f3e5f5; color:#6a1b9a; padding:2px 8px; border-radius:4px; font-weight:600; font-size:9px; }
        .row-nova td{ background:#fce4ec; }
        .row-ausente td{ background:#fffde7; }
        .row-sub-nova td{ background:#fce4ec; }
        .row-sub-removida td{ background:#fffde7; }
        .section-title{ font-size:13px; font-weight:700; margin:16px 0 8px; border-bottom:2px solid #04193b; padding-bottom:4px; }
        .lancamento{ font-size:9px; color:#555; padding-left:20px; }
        .card-diff{ border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:10px; }
        .card-diff-nova{ border-left:4px solid #ef5350; }
        .card-diff-ausente{ border-left:4px solid #ffc107; }
        .card-diff-sub-nova{ border-left:4px solid #ef5350; }
        .card-diff-sub-removida{ border-left:4px solid #ffc107; }
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
        .fundo-alert{ background:#f3e5f5; border:2px solid #6a1b9a; border-radius:8px; padding:12px; margin-bottom:14px; }
        .fundo-alert-ok{ background:#e8f5e9; border:2px solid #2e7d32; }
    </style>';

    $condominio = htmlspecialchars($dados['condominio'] ?? '', ENT_QUOTES, 'UTF-8');
    $perAnt = htmlspecialchars($dados['periodoAnterior'] ?? '', ENT_QUOTES, 'UTF-8');
    $perAtu = htmlspecialchars($dados['periodoAtual'] ?? '', ENT_QUOTES, 'UTF-8');

    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'>{$css}</head><body>";

    // Header
    $html .= "<div class='header-bar'>";
    $html .= "<h2>Comparativo de Prestacao de Contas</h2>";
    $html .= "<p><strong>{$condominio}</strong> -- {$perAnt} vs {$perAtu}</p>";
    $html .= "</div>";

    // Stats
    $html .= "<div class='stat-row'>";
    $html .= "<div class='stat-box'><div class='num'>" . ($dados['lanctosAnterior'] ?? 0) . "</div><div class='lbl'>Lancamentos<br>Periodo Anterior</div></div>";
    $html .= "<div class='stat-box'><div class='num'>" . ($dados['lanctosAtual'] ?? 0) . "</div><div class='lbl'>Lancamentos<br>Mes Atual</div></div>";
    $html .= "<div class='stat-box'><div class='num' style='color:#c00;'>" . count($dados['diferencas'] ?? []) . "</div><div class='lbl'>Diferencas<br>Encontradas</div></div>";
    $html .= "</div>";

    // Fundo de Reserva Alert
    $fr = $dados['fundoReserva'] ?? [];
    if (!empty($fr)) {
        if ($fr['temDebito']) {
            $html .= "<div class='fundo-alert'>";
            $html .= "<strong style='color:#6a1b9a;'>FUNDO DE RESERVA - DEBITOS DETECTADOS</strong><br>";
            $html .= "Debito Anterior: " . fmt_brl($fr['debitoAnterior']) . " | Debito Atual: " . fmt_brl($fr['debitoAtual']);
            if (!empty($fr['lancamentos'])) {
                $html .= "<div style='margin-top:6px; font-size:9px;'>";
                foreach ($fr['lancamentos'] as $l) {
                    $html .= "<div>- [{$l['periodo']}] {$l['subcategoria']}: {$l['descricao']} | {$l['data']} | " . fmt_brl($l['valor']) . "</div>";
                }
                $html .= "</div>";
            }
            $html .= "</div>";
        } else {
            $html .= "<div class='fundo-alert fundo-alert-ok'>";
            $html .= "<strong style='color:#2e7d32;'>FUNDO DE RESERVA - OK</strong><br>";
            $html .= "<span style='font-size:10px;'>Nenhum debito encontrado no Fundo de Reserva.</span>";
            $html .= "</div>";
        }
    }

    // Barra de Periodos
    $html .= "<div class='periodo-bar'>";
    $html .= "<div class='periodo-col periodo-ant'><div class='periodo-label'>Periodo Anterior</div><div class='periodo-date'>{$perAnt}</div></div>";
    $html .= "<div class='periodo-vs'>vs</div>";
    $html .= "<div class='periodo-col periodo-atu'><div class='periodo-label'>Mes Atual</div><div class='periodo-date'>{$perAtu}</div></div>";
    $html .= "</div>";

    // Totais por Conta
    $html .= "<div class='section-title'>Totais por Conta</div>";
    $html .= "<table><tr><th>Secao</th><th>Conta</th><th style='text-align:right'>Anterior (R$)</th><th>%</th><th style='text-align:right'>Atual (R$)</th><th>%</th><th style='text-align:right'>Diferenca</th><th>Var%</th><th>Status</th></tr>";
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

    // Diferencas Detalhadas
    if ($paraPdf) $html .= "<div class='page-break'></div>";
    $html .= "<div class='section-title'>Diferencas Detalhadas</div>";

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

        $ant = ($d['totalAnterior'] ?? 0) ? fmt_brl($d['totalAnterior']) : '-';
        $atu = ($d['totalAtual'] ?? 0) ? fmt_brl($d['totalAtual']) : '-';
        $html .= "<div style='margin:4px 0; font-size:10px; color:#555;'>Anterior: {$ant} | Atual: {$atu}</div>";

        if (!empty($d['lancamentos'])) {
            foreach ($d['lancamentos'] as $lanc) {
                $html .= "<div class='lancamento'>" . h($lanc) . "</div>";
            }
        }
        $html .= "</div>";
    }

    // Resumo Financeiro
    if ($paraPdf) $html .= "<div class='page-break'></div>";
    $html .= "<div class='section-title'>Resumo Financeiro Contabil</div>";
    $html .= "<table><tr><th>Categoria</th><th style='text-align:right'>Saldo Ant.</th><th style='text-align:right'>Creditos</th><th style='text-align:right'>Debitos</th><th style='text-align:right'>Saldo Atual</th><th></th><th style='text-align:right'>Saldo Ant.</th><th style='text-align:right'>Creditos</th><th style='text-align:right'>Debitos</th><th style='text-align:right'>Saldo Atual</th></tr>";
    $html .= "<tr><th colspan='5' style='text-align:center; background:#2E75B6;'>Periodo Anterior</th><th style='background:#fff'></th><th colspan='4' style='text-align:center; background:#548235;'>Mes Atual</th></tr>";

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
        $isFundo = stripos($cat, 'FUNDO') !== false && stripos($cat, 'RESERVA') !== false;
        $rowStyle = $isFundo ? " style='background:#f3e5f5;'" : "";
        $html .= "<tr{$rowStyle}>";
        $html .= "<td><strong>" . h($cat) . "</strong>" . ($isFundo ? " <span class='badge-fundo'>FUNDO</span>" : "") . "</td>";
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
  <title>Resultado v2 — Prestacao de Contas</title>
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

    /* v2: cores corrigidas — NOVA=vermelho (perigo), AUSENTE=amarelo (atenção) */
    .badge-nova{ background:#fce4ec; color:#c62828; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .badge-ausente{ background:#fff2cc; color:#856404; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .badge-sub-nova{ background:#fce4ec; color:#c62828; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .badge-sub-removida{ background:#fff2cc; color:#856404; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .badge-fundo{ background:#f3e5f5; color:#6a1b9a; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }

    .diff-card{ border:1px solid #ddd; border-radius:8px; padding:14px; margin-bottom:10px; background:#fff; }
    .diff-card-nova{ border-left:5px solid #ef5350; }
    .diff-card-ausente{ border-left:5px solid #ffc107; }
    .diff-card-sub-nova{ border-left:5px solid #ef5350; }
    .diff-card-sub-removida{ border-left:5px solid #ffc107; }

    .lancamento-item{ font-size:.8rem; color:#666; padding:2px 0 2px 16px; border-left:2px solid #ddd; margin:2px 0; }
    .totais-table th{ background:var(--azul); color:#fff; font-size:.8rem; }
    .totais-table td{ font-size:.85rem; vertical-align:middle; }
    .totais-table .row-nova td{ background:#fce4ec; }
    .totais-table .row-ausente td{ background:#fffde7; }
    .valor-pos{ color:#c62828; font-weight:600; }
    .valor-neg{ color:#2e7d32; font-weight:600; }
    .filter-btn{ cursor:pointer; }
    .filter-btn.active{ opacity:1; }
    .filter-btn:not(.active){ opacity:.5; }

    /* Dropdown subcategorias */
    .conta-toggle{ cursor:pointer; user-select:none; }
    .conta-toggle::before{ content:'\25B6'; display:inline-block; width:1rem; font-size:.7rem; transition:transform .2s; }
    .conta-toggle.open::before{ transform:rotate(90deg); }
    .subcat-row td{ background:#f8f9fa; font-size:.8rem; padding-left:2.5rem !important; }
    .subcat-row .subcat-name{ color:#555; }
    .subcat-row .subcat-nova{ color:#c62828; font-weight:600; }
    .subcat-row .subcat-ausente{ color:#856404; font-weight:600; }

    /* Fundo de Reserva */
    .fundo-alert{ border-radius:8px; padding:1rem 1.25rem; }
    .fundo-alert-danger{ background:#f3e5f5; border:2px solid #6a1b9a; }
    .fundo-alert-ok{ background:#e8f5e9; border:2px solid #2e7d32; }
    .fundo-lancamento{ font-size:.82rem; color:#555; padding:2px 0 2px 16px; border-left:2px solid #6a1b9a; margin:3px 0; }

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
        <div class="label">Lancamentos<br><strong><?= h($dados['periodoAnterior'] ?? 'Periodo Anterior') ?></strong></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stat-card">
        <div class="number"><?= h($dados['lanctosAtual'] ?? 0) ?></div>
        <div class="label">Lancamentos<br><strong><?= h($dados['periodoAtual'] ?? 'Mes Atual') ?></strong></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stat-card">
        <div class="number" style="color:#c00;"><?= count($dados['diferencas'] ?? []) ?></div>
        <div class="label">Diferencas<br>Encontradas</div>
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
      Baixar XLSX Detalhado
    </a>
    <form method="post" style="display:inline;">
      <input type="hidden" name="export_pdf" value="1">
      <input type="hidden" name="json_path" value="<?= h($jsonPath) ?>">
      <button type="submit" class="btn btn-outline-secondary">Baixar Relatorio PDF</button>
    </form>
    <a href="../prestacao.php" class="btn btn-outline-secondary">Nova Comparacao</a>
  </div>

  <!-- Barra de Periodos -->
  <div class="d-flex mb-4 rounded overflow-hidden" style="border:1px solid var(--cinza);">
    <div class="flex-fill text-center py-2" style="background:#2E75B6; color:#fff;">
      <small class="d-block" style="opacity:.8;">Periodo Anterior</small>
      <strong style="font-size:1.05rem;"><?= h($dados['periodoAnterior'] ?? '') ?></strong>
    </div>
    <div class="d-flex align-items-center px-3" style="background:var(--cinzaClaro); color:var(--azul); font-weight:700;">vs</div>
    <div class="flex-fill text-center py-2" style="background:#548235; color:#fff;">
      <small class="d-block" style="opacity:.8;">Mes Atual</small>
      <strong style="font-size:1.05rem;"><?= h($dados['periodoAtual'] ?? '') ?></strong>
    </div>
  </div>

  <!-- FUNDO DE RESERVA ALERT -->
  <?php $fr = $dados['fundoReserva'] ?? []; ?>
  <?php if (!empty($fr)): ?>
    <?php if ($fr['temDebitoAtual'] ?? false): ?>
      <div class="fundo-alert fundo-alert-danger mb-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge-fundo" style="font-size:.85rem; padding:5px 14px;">FUNDO DE RESERVA</span>
          <strong style="color:#6a1b9a;">Debitos detectados no mes atual — requer verificacao</strong>
        </div>
        <div class="row g-3 mb-2">
          <div class="col-md-2">
            <small class="text-muted d-block">Saldo Inicial</small>
            <strong><?= fmt_brl($fr['saldoAtualInicio']) ?></strong>
          </div>
          <div class="col-md-2">
            <small class="text-muted d-block">Credito Mes Atual</small>
            <strong style="color:#2e7d32;"><?= fmt_brl($fr['creditoAtual']) ?></strong>
          </div>
          <div class="col-md-2">
            <small class="text-muted d-block">Debito Mes Atual</small>
            <strong style="color:#c62828; font-size:1.1rem;"><?= fmt_brl($fr['debitoAtual']) ?></strong>
          </div>
          <div class="col-md-2">
            <small class="text-muted d-block">Saldo Final</small>
            <strong style="color:#6a1b9a; font-size:1.1rem;"><?= fmt_brl($fr['saldoAtualFim']) ?></strong>
          </div>
        </div>
        <?php if (!empty($fr['lancamentos'])): ?>
          <?php $lancAtual = array_filter($fr['lancamentos'], fn($l) => $l['periodo'] === 'atual'); ?>
          <?php if ($lancAtual): ?>
          <div class="mt-2">
            <small class="text-muted">Lancamentos no Fundo de Reserva (mes atual):</small>
            <?php foreach ($lancAtual as $l): ?>
              <div class="fundo-lancamento">
                <?= h($l['descricao']) ?>
                <?php if (!empty($l['data'])): ?> | <?= h($l['data']) ?><?php endif; ?>
                | <strong><?= fmt_brl($l['valor']) ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($fr['temDebitoAnterior'] ?? false): ?>
          <div class="mt-2 p-2 rounded" style="background:rgba(106,27,154,0.08); font-size:.82rem;">
            <small class="text-muted">Periodo anterior:</small>
            Debito <?= fmt_brl($fr['debitoAnterior']) ?> | Credito <?= fmt_brl($fr['creditoAnterior']) ?>
          </div>
        <?php endif; ?>
        <div class="mt-2" style="font-size:.8rem; color:#6a1b9a;">
          Na teoria, nao devem existir debitos no Fundo de Reserva, a nao ser por decisao do condominio.
        </div>
      </div>
    <?php else: ?>
      <div class="fundo-alert fundo-alert-ok mb-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span style="font-size:1.1rem;">&#10003;</span>
          <strong style="color:#2e7d32;">Fundo de Reserva — Nenhum debito no mes atual</strong>
        </div>
        <div class="row g-3">
          <div class="col-md-2">
            <small class="text-muted d-block">Saldo Atual</small>
            <strong style="color:#2e7d32; font-size:1.1rem;"><?= fmt_brl($fr['saldoAtualFim']) ?></strong>
          </div>
          <div class="col-md-2">
            <small class="text-muted d-block">Credito Mes Atual</small>
            <strong style="color:#2e7d32;"><?= fmt_brl($fr['creditoAtual']) ?></strong>
          </div>
          <?php if ($fr['temDebitoAnterior'] ?? false): ?>
          <div class="col-md-4">
            <small class="text-muted d-block">Periodo anterior teve debitos</small>
            <strong style="color:#856404;"><?= fmt_brl($fr['debitoAnterior']) ?></strong>
            <small class="text-muted ms-1">(informativo)</small>
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- TOTAIS POR CONTA (com dropdown de subcategorias) -->
  <div class="card mb-4">
    <div class="card-header" style="background:var(--azul); color:#fff;">
      <strong>Totais por Conta</strong>
      <small class="ms-2 opacity-75">— clique na conta para expandir subcategorias</small>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover totais-table mb-0">
          <thead>
            <tr>
              <th>Secao</th><th>Conta</th>
              <th class="text-end">Anterior (R$)</th><th>%</th>
              <th class="text-end">Atual (R$)</th><th>%</th>
              <th class="text-end">Diferenca</th><th>Var%</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $subcatMap = $dados['subcategoriasPorConta'] ?? [];
            foreach (($dados['totaisComparativo'] ?? []) as $idx => $t):
              $rowClass = '';
              $badge = '';
              if ($t['obs'] === 'NOVA') { $rowClass = 'row-nova'; $badge = '<span class="badge-nova">NOVA</span>'; }
              elseif ($t['obs'] === 'AUSENTE') { $rowClass = 'row-ausente'; $badge = '<span class="badge-ausente">AUSENTE</span>'; }
              $diffClass = ($t['diferenca'] ?? 0) > 0 ? 'valor-pos' : (($t['diferenca'] ?? 0) < 0 ? 'valor-neg' : '');
              $varStr = $t['varPct'] !== null ? number_format($t['varPct'], 1) . '%' : '-';
              $contaKey = h($t['secao']) . ' > ' . h($t['conta']);
              $subcats = $subcatMap[$contaKey] ?? [];
              $hasSubcats = count($subcats) > 0;
          ?>
            <tr class="<?= $rowClass ?>" <?= $hasSubcats ? 'data-toggle-id="subcats-' . $idx . '"' : '' ?>>
              <td><?= h($t['secao']) ?></td>
              <td>
                <?php if ($hasSubcats): ?>
                  <span class="conta-toggle" data-target="subcats-<?= $idx ?>"><strong><?= h($t['conta']) ?></strong></span>
                <?php else: ?>
                  <strong><?= h($t['conta']) ?></strong>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= fmt_brl($t['totalAnterior']) ?></td>
              <td><?= h($t['pctAnterior']) ?></td>
              <td class="text-end"><?= fmt_brl($t['totalAtual']) ?></td>
              <td><?= h($t['pctAtual']) ?></td>
              <td class="text-end <?= $diffClass ?>"><?= fmt_brl($t['diferenca']) ?></td>
              <td><?= $varStr ?></td>
              <td><?= $badge ?></td>
            </tr>
            <?php if ($hasSubcats): ?>
              <?php foreach ($subcats as $sc): ?>
                <tr class="subcat-row" data-parent="subcats-<?= $idx ?>" style="display:none;">
                  <td></td>
                  <td>
                    <span class="subcat-name"><?= h($sc['subcategoria']) ?></span>
                    <?php if ($sc['status'] === 'NOVA'): ?>
                      <span class="badge-nova" style="font-size:.65rem; padding:1px 6px;">NOVA</span>
                    <?php elseif ($sc['status'] === 'AUSENTE'): ?>
                      <span class="badge-ausente" style="font-size:.65rem; padding:1px 6px;">AUSENTE</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?= fmt_brl($sc['totalAnterior']) ?></td>
                  <td><small class="text-muted"><?= $sc['qtdAnterior'] ?> lanc.</small></td>
                  <td class="text-end"><?= fmt_brl($sc['totalAtual']) ?></td>
                  <td><small class="text-muted"><?= $sc['qtdAtual'] ?> lanc.</small></td>
                  <td class="text-end <?= ($sc['totalAtual'] - $sc['totalAnterior']) > 0 ? 'valor-pos' : (($sc['totalAtual'] - $sc['totalAnterior']) < 0 ? 'valor-neg' : '') ?>">
                    <?= fmt_brl($sc['totalAtual'] - $sc['totalAnterior']) ?>
                  </td>
                  <td>
                    <?php if ($sc['totalAnterior'] > 0): ?>
                      <?= number_format(($sc['totalAtual'] - $sc['totalAnterior']) / $sc['totalAnterior'] * 100, 1) ?>%
                    <?php else: ?>-<?php endif; ?>
                  </td>
                  <td></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- RESUMO FINANCEIRO (antes das diferenças) -->
  <div class="card mb-4">
    <div class="card-header" style="background:var(--azul); color:#fff;">
      <strong>Resumo Financeiro Contabil</strong>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th rowspan="2" style="vertical-align:middle; background:var(--azul); color:#fff;">Categoria</th>
              <th colspan="4" class="text-center" style="background:#2E75B6; color:#fff;">Periodo Anterior</th>
              <th rowspan="2" style="width:10px; background:#fff;"></th>
              <th colspan="4" class="text-center" style="background:#548235; color:#fff;">Mes Atual</th>
            </tr>
            <tr>
              <th class="text-end" style="background:#2E75B6; color:#fff; font-size:.75rem;">Saldo Ant.</th>
              <th class="text-end" style="background:#2E75B6; color:#fff; font-size:.75rem;">Creditos</th>
              <th class="text-end" style="background:#2E75B6; color:#fff; font-size:.75rem;">Debitos</th>
              <th class="text-end" style="background:#2E75B6; color:#fff; font-size:.75rem;">Saldo Atual</th>
              <th class="text-end" style="background:#548235; color:#fff; font-size:.75rem;">Saldo Ant.</th>
              <th class="text-end" style="background:#548235; color:#fff; font-size:.75rem;">Creditos</th>
              <th class="text-end" style="background:#548235; color:#fff; font-size:.75rem;">Debitos</th>
              <th class="text-end" style="background:#548235; color:#fff; font-size:.75rem;">Saldo Atual</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $resAnt = $dados['resumo']['anterior'] ?? [];
            $resAtu = $dados['resumo']['atual'] ?? [];
            $allCats = array_unique(array_merge(
                array_column($resAnt, 'categoria'),
                array_column($resAtu, 'categoria')
            ));
            foreach ($allCats as $cat):
              $a = null; $n = null;
              foreach ($resAnt as $r) { if ($r['categoria'] === $cat) { $a = $r; break; } }
              foreach ($resAtu as $r) { if ($r['categoria'] === $cat) { $n = $r; break; } }
              $isFundo = stripos($cat, 'FUNDO') !== false && stripos($cat, 'RESERVA') !== false;
              $debitoAnt = $a['debitos'] ?? 0;
              $debitoAtu = $n['debitos'] ?? 0;
          ?>
            <tr <?= $isFundo ? 'style="background:#f3e5f5;"' : '' ?>>
              <td>
                <strong><?= h($cat) ?></strong>
                <?php if ($isFundo): ?>
                  <span class="badge-fundo" style="font-size:.65rem;">FUNDO</span>
                  <?php if ($debitoAnt > 0 || $debitoAtu > 0): ?>
                    <span class="badge bg-danger" style="font-size:.65rem;">DEBITO!</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= fmt_brl($a['saldoAnterior'] ?? 0) ?></td>
              <td class="text-end"><?= fmt_brl($a['creditos'] ?? 0) ?></td>
              <td class="text-end <?= ($isFundo && $debitoAnt > 0) ? 'valor-pos' : '' ?>"><?= fmt_brl($debitoAnt) ?></td>
              <td class="text-end"><?= fmt_brl($a['saldoAtual'] ?? 0) ?></td>
              <td></td>
              <td class="text-end"><?= fmt_brl($n['saldoAnterior'] ?? 0) ?></td>
              <td class="text-end"><?= fmt_brl($n['creditos'] ?? 0) ?></td>
              <td class="text-end <?= ($isFundo && $debitoAtu > 0) ? 'valor-pos' : '' ?>"><?= fmt_brl($debitoAtu) ?></td>
              <td class="text-end"><?= fmt_brl($n['saldoAtual'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- DIFERENCAS DETALHADAS (reordenadas: Nova primeiro, Ausente depois) -->
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:#c00; color:#fff;">
      <strong>Diferencas Detalhadas (<?= count($dados['diferencas'] ?? []) ?>)</strong>
      <div class="d-flex gap-2">
        <span class="badge-nova filter-btn active" data-filter="CONTA NOVA" style="cursor:pointer;">CONTA NOVA</span>
        <span class="badge-sub-nova filter-btn active" data-filter="SUBCATEGORIA NOVA" style="cursor:pointer;">SUBCAT NOVA</span>
        <span class="badge-ausente filter-btn active" data-filter="CONTA AUSENTE" style="cursor:pointer;">CONTA AUSENTE</span>
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
    // Ordenar: CONTA NOVA > SUBCATEGORIA NOVA > CONTA AUSENTE > SUBCATEGORIA REMOVIDA
    $tipoPrioridade = [
        'CONTA NOVA' => 0, 'SUBCATEGORIA NOVA' => 1,
        'CONTA AUSENTE' => 2, 'SUBCATEGORIA REMOVIDA' => 3,
    ];
    $diferencas = $dados['diferencas'] ?? [];
    usort($diferencas, function($a, $b) use ($tipoPrioridade) {
        $pa = $tipoPrioridade[$a['tipo']] ?? 9;
        $pb = $tipoPrioridade[$b['tipo']] ?? 9;
        return $pa - $pb;
    });
    foreach ($diferencas as $d):
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
    <p>O comparativo nao pode ser gerado. Verifique se os PDFs sao do formato esperado.</p>
  </div>
  <a href="../prestacao.php" class="btn btn-primary mb-4">Voltar</a>
<?php endif; ?>

  <?php if (auth_is_admin()): ?>
  <!-- Detalhes tecnicos (somente admin) -->
  <div class="accordion mb-4" id="accDetails">
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#collapseDetails">
          Detalhes tecnicos
        </button>
      </h2>
      <div id="collapseDetails" class="accordion-collapse collapse">
        <div class="accordion-body">
          <p><strong>Comando:</strong></p>
          <pre class="bg-dark text-light p-3 rounded" style="white-space:pre-wrap; font-size:.8rem;"><?= h($cmd) ?></pre>
          <p><strong>Saida (exit code <?= $exitCode ?>):</strong></p>
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
// Toggle subcategorias dropdown
document.querySelectorAll('.conta-toggle').forEach(toggle => {
  toggle.addEventListener('click', function() {
    const target = this.dataset.target;
    this.classList.toggle('open');
    document.querySelectorAll('.subcat-row[data-parent="' + target + '"]').forEach(row => {
      row.style.display = row.style.display === 'none' ? '' : 'none';
    });
  });
});

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
