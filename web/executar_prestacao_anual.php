<?php
/**
 * executar_prestacao_anual.php
 *
 * Recebe 1 PDF anual de Prestação de Contas, executa analise_anual_cli.py
 * e exibe resultados: comparativos mês-a-mês, análise de padrão mensal,
 * Fundo de Reserva, visão panorâmica.
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

    $nomesMes = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
    $htmlPdf = renderPdfAnual($dados, $nomesMes);

    $dompdf = new Dompdf();
    $dompdf->set_option('isRemoteEnabled', true);
    $dompdf->set_option('defaultFont', 'DejaVu Sans');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml($htmlPdf, 'UTF-8');
    $dompdf->render();

    $nomeArquivo = 'Analise_Anual_' . preg_replace('/[^A-Za-z0-9]/', '_', $dados['condominio'] ?? 'relatorio') . '.pdf';
    $dompdf->stream($nomeArquivo, ['Attachment' => true]);
    exit;
}

// ===========================================================================
// UPLOAD + PROCESSAMENTO
// ===========================================================================
$erros = [];
$ts = date('Ymd_His');

$pdfAnual = $_FILES['pdf_anual'] ?? null;
$pathAnual = '';
if ($pdfAnual && $pdfAnual['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($pdfAnual['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') { $erros[] = "O arquivo deve ser PDF."; }
    else {
        $safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', $pdfAnual['name']);
        $pathAnual = $UPLOADS_DIR . APP_SEP . $ts . '_anual_' . $safeName;
        if (!move_uploaded_file($pdfAnual['tmp_name'], $pathAnual)) {
            $erros[] = "Falha ao salvar PDF.";
        }
    }
} else { $erros[] = "Envie o PDF anual de Prestacao de Contas."; }

$saidaBase = isset($_POST['saidaBase']) ? trim($_POST['saidaBase']) : '';
if (!$saidaBase) $saidaBase = 'Analise_Anual_' . $ts;
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
    echo "</ul></div><a href='../prestacao_anual.php' class='btn btn-primary'>Voltar</a></div></body></html>";
    exit;
}

// Executar CLI Python
$cliScript = $BASE . APP_SEP . 'analise_anual_cli.py';
$cmd = escapeshellarg($PYTHON)
     . ' ' . escapeshellarg($cliScript)
     . ' --pdf '  . escapeshellarg($pathAnual)
     . ' --saida ' . escapeshellarg($saidaPath)
     . ' --json '  . escapeshellarg($jsonPath)
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

$sucesso = $exitCode === 0 && file_exists($jsonPath);
$dados = $sucesso ? json_decode(file_get_contents($jsonPath), true) : null;

$nomesMes = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];

// ===========================================================================
// RENDER PDF (para DOMPDF export)
// ===========================================================================
function renderPdfAnual($dados, $nomesMes) {
    $css = '
    <style>
        body{ background:#fff; color:#04193b; font-family:DejaVu Sans,Arial,sans-serif; font-size:10px; margin:0; padding:0; }
        .header{ background:#04193b; color:#fff; padding:14px 20px; margin-bottom:12px; }
        .header h2{ margin:0; font-size:15px; }
        .header p{ margin:3px 0 0; font-size:10px; opacity:.85; }
        .ia-warn{ background:#fff3e0; border:2px solid #e65100; border-radius:6px; padding:10px 14px; margin-bottom:14px; color:#bf360c; font-size:9px; }
        .ia-warn strong{ color:#e65100; }
        .stat-row{ display:flex; gap:8px; margin-bottom:14px; }
        .stat-box{ flex:1; text-align:center; border:1px solid #ccc; border-radius:5px; padding:8px 4px; }
        .stat-box .num{ font-size:18px; font-weight:700; }
        .stat-box .lbl{ font-size:8px; color:#666; }
        table{ width:100%; border-collapse:collapse; margin-bottom:12px; font-size:9px; }
        th{ background:#04193b; color:#fff; padding:4px 6px; text-align:left; }
        td{ padding:3px 6px; border-bottom:1px solid #ddd; }
        .section-title{ font-size:12px; font-weight:700; margin:14px 0 6px; border-bottom:2px solid #0664e4; padding-bottom:3px; color:#0664e4; }
        .badge-nova{ background:#fce4ec; color:#c62828; padding:1px 6px; border-radius:3px; font-weight:600; font-size:8px; }
        .badge-ausente{ background:#fff2cc; color:#856404; padding:1px 6px; border-radius:3px; font-weight:600; font-size:8px; }
        .fundo-ok{ background:#e8f5e9; border:1px solid #2e7d32; border-radius:5px; padding:8px 12px; margin-bottom:12px; color:#2e7d32; }
        .fundo-danger{ background:#f3e5f5; border:1px solid #6a1b9a; border-radius:5px; padding:8px 12px; margin-bottom:12px; color:#6a1b9a; }
        .footer{ text-align:center; font-size:8px; color:#999; margin-top:20px; padding-top:10px; border-top:1px solid #ddd; }
    </style>';

    $cond = h($dados['condominio'] ?? '');
    $periodo = h($dados['periodo'] ?? '');
    $totalLanctos = $dados['totalLancamentos'] ?? 0;
    $totalMeses = count($dados['meses'] ?? []);
    $cm = $dados['comparativoMesmoMes'] ?? null;
    $ca = $dados['comparativoMesAnterior'] ?? null;
    $am = $dados['analiseMensal'] ?? [];
    $fr = $dados['fundoReserva'] ?? [];

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">' . $css . '</head><body>';

    // Header
    $html .= '<div class="header"><h2>Analise de Prestacao de Contas por Cleanalyze IA</h2>';
    $html .= '<p>' . $cond . ' — Periodo: ' . $periodo . '</p></div>';

    // IA disclaimer
    $html .= '<div class="ia-warn"><strong>&#9888; Analise gerada por Inteligencia Artificial.</strong> ';
    $html .= 'Esta e uma analise inicial automatizada para identificacao de anomalias e padroes. ';
    $html .= 'Os resultados sao indicativos e <strong>devem ser validados e revisados pelo usuario</strong> ';
    $html .= 'antes de qualquer tomada de decisao.</div>';

    // Stats
    $diffsMM = $cm ? count($cm['diferencas'] ?? []) : 'N/A';
    $diffsMA = $ca ? count($ca['diferencas'] ?? []) : 'N/A';
    $contasNovas = count($am['contasNovas'] ?? []);
    $contasAusentes = count($am['contasAusentes'] ?? []);
    $html .= '<div class="stat-row">';
    $html .= '<div class="stat-box"><div class="num">' . $totalLanctos . '</div><div class="lbl">Lancamentos</div></div>';
    $html .= '<div class="stat-box"><div class="num">' . ($totalMeses > 12 ? '12+1' : $totalMeses) . '</div><div class="lbl">Meses</div></div>';
    $html .= '<div class="stat-box"><div class="num">' . $diffsMM . '</div><div class="lbl">Diffs Mesmo Mes</div></div>';
    $html .= '<div class="stat-box"><div class="num">' . $diffsMA . '</div><div class="lbl">Diffs Mes Anterior</div></div>';
    $html .= '<div class="stat-box"><div class="num" style="color:#c62828;">' . $contasNovas . '</div><div class="lbl">Contas Novas</div></div>';
    $html .= '<div class="stat-box"><div class="num" style="color:#856404;">' . $contasAusentes . '</div><div class="lbl">Contas Ausentes</div></div>';
    $html .= '</div>';

    // Fundo de Reserva
    if (!empty($fr)) {
        if ($fr['temDebitoMesAtual'] ?? false) {
            $html .= '<div class="fundo-danger"><strong>FUNDO DE RESERVA — Debitos no mes atual: ' . fmt_brl($fr['debitoMesAtual']) . '</strong>';
            $html .= ' | Saldo Final: ' . fmt_brl($fr['saldoAtual']);
            foreach ($fr['lancamentosMesAtual'] ?? [] as $l) {
                $html .= '<br>&nbsp;&nbsp;- ' . h($l['descricao']) . ' | ' . h($l['data']) . ' | ' . fmt_brl($l['valor']);
            }
            $html .= '</div>';
        } else {
            $html .= '<div class="fundo-ok"><strong>&#10003; Fundo de Reserva — Nenhum debito no mes atual.</strong>';
            $html .= ' Saldo Final: ' . fmt_brl($fr['saldoAtual']) . '</div>';
        }
    }

    // Resumo Financeiro
    $resumo = $dados['resumoFinanceiro'] ?? [];
    if ($resumo) {
        $html .= '<div class="section-title">Resumo Financeiro Contabil</div>';
        $html .= '<table><tr><th>Categoria</th><th style="text-align:right">Saldo Anterior</th><th style="text-align:right">Creditos</th><th style="text-align:right">Debitos</th><th style="text-align:right">Saldo Atual</th></tr>';
        foreach ($resumo as $r) {
            $html .= '<tr><td><strong>' . h($r['categoria']) . '</strong></td>';
            $html .= '<td style="text-align:right">' . fmt_brl($r['saldoAnterior']) . '</td>';
            $html .= '<td style="text-align:right;color:#2e7d32;">' . fmt_brl($r['creditos']) . '</td>';
            $html .= '<td style="text-align:right;color:#c62828;">' . fmt_brl($r['debitos']) . '</td>';
            $html .= '<td style="text-align:right"><strong>' . fmt_brl($r['saldoAtual']) . '</strong></td></tr>';
        }
        $html .= '</table>';
    }

    // Analise de Padrao Mensal
    if (!empty($am['contasNovas']) || !empty($am['contasAusentes']) || !empty($am['novasSemHistorico']) || !empty($am['ausentesNoMes'])) {
        $html .= '<div class="section-title">Analise de Padrao Mensal</div>';

        if (!empty($am['contasNovas'])) {
            $html .= '<table><tr><th>Conta Nova</th><th style="text-align:right">Total Atual</th></tr>';
            foreach ($am['contasNovas'] as $cn) {
                $html .= '<tr style="background:#fce4ec;"><td><strong>' . h($cn['conta']) . '</strong></td><td style="text-align:right">' . fmt_brl($cn['totalAtual']) . '</td></tr>';
            }
            $html .= '</table>';
        }
        if (!empty($am['contasAusentes'])) {
            $html .= '<table><tr><th>Conta Ausente</th><th style="text-align:right">Ultimo Valor</th><th>Recorrencia</th></tr>';
            foreach ($am['contasAusentes'] as $ca2) {
                $html .= '<tr style="background:#fff2cc;"><td><strong>' . h($ca2['conta']) . '</strong></td>';
                $html .= '<td style="text-align:right">' . fmt_brl($ca2['totalUltimaRef']) . '</td>';
                $html .= '<td>' . $ca2['recorrencia'] . '/12 meses</td></tr>';
            }
            $html .= '</table>';
        }
        if (!empty($am['novasSemHistorico'])) {
            $html .= '<table><tr><th>Subcategoria Nova</th><th>Conta</th><th style="text-align:right">Total</th></tr>';
            foreach ($am['novasSemHistorico'] as $n) {
                $html .= '<tr style="background:#fce4ec;"><td>' . h($n['subcategoria']) . '</td><td>' . h($n['conta']) . '</td><td style="text-align:right">' . fmt_brl($n['totalAtual']) . '</td></tr>';
            }
            $html .= '</table>';
        }
        if (!empty($am['ausentesNoMes'])) {
            $html .= '<table><tr><th>Subcategoria Ausente</th><th>Conta</th><th style="text-align:right">Total Ant.</th><th>Recorrencia</th></tr>';
            foreach ($am['ausentesNoMes'] as $a) {
                $html .= '<tr style="background:#fff2cc;"><td>' . h($a['subcategoria']) . '</td><td>' . h($a['conta']) . '</td>';
                $html .= '<td style="text-align:right">' . fmt_brl($a['totalMesmoMesAnterior']) . '</td>';
                $html .= '<td>' . $a['recorrencia'] . '/12 meses</td></tr>';
            }
            $html .= '</table>';
        }
    }

    // Comparativos
    $comparativos = [];
    if ($cm) $comparativos[] = ['label' => $cm['labelAnterior'] . ' vs ' . $cm['labelAtual'] . ' (Mesmo Mes)', 'data' => $cm];
    if ($ca) $comparativos[] = ['label' => $ca['labelAnterior'] . ' vs ' . $ca['labelAtual'] . ' (Mes Anterior)', 'data' => $ca];

    foreach ($comparativos as $comp) {
        $d = $comp['data'];
        $html .= '<div class="section-title">Comparativo: ' . h($comp['label']) . '</div>';
        $totais = $d['totaisComparativo'] ?? [];
        if ($totais) {
            $html .= '<table><tr><th>Conta</th><th style="text-align:right">Anterior</th><th style="text-align:right">Atual</th><th style="text-align:right">Diferenca</th><th>Obs</th></tr>';
            foreach ($totais as $t) {
                $diff = $t['diferenca'] ?? 0;
                $bg = '';
                if (($t['obs'] ?? '') === 'NOVA') $bg = ' style="background:#fce4ec;"';
                elseif (($t['obs'] ?? '') === 'AUSENTE') $bg = ' style="background:#fff2cc;"';
                $html .= '<tr' . $bg . '><td>' . h($t['conta']) . '</td>';
                $html .= '<td style="text-align:right">' . fmt_brl($t['totalAnterior']) . '</td>';
                $html .= '<td style="text-align:right">' . fmt_brl($t['totalAtual']) . '</td>';
                $html .= '<td style="text-align:right;color:' . ($diff > 0 ? '#c62828' : '#2e7d32') . ';">' . fmt_brl($diff) . '</td>';
                $obs = $t['obs'] ?? '';
                $badge = '';
                if ($obs === 'NOVA') $badge = '<span class="badge-nova">NOVA</span>';
                elseif ($obs === 'AUSENTE') $badge = '<span class="badge-ausente">AUSENTE</span>';
                $html .= '<td>' . $badge . '</td></tr>';
            }
            $html .= '</table>';
        }
    }

    // Totais por Mes
    $totaisPorMes = $am['totaisPorMes'] ?? [];
    if ($totaisPorMes) {
        $html .= '<div class="section-title">Totais de Despesas por Mes</div>';
        $html .= '<table><tr><th>Mes</th><th style="text-align:right">Total Despesas</th><th style="text-align:right">Variacao</th></tr>';
        $prev = null;
        foreach ($totaisPorMes as $mk => $total) {
            $parts = explode('/', $mk);
            $label = ($nomesMes[(int)$parts[0]] ?? $mk) . '/' . ($parts[1] ?? '');
            $var = ($prev !== null) ? ($total - $prev) : null;
            $isUltimo = ($mk === ($dados['ultimoMes'] ?? ''));
            $style = $isUltimo ? ' style="background:#e8f5e9;font-weight:600;"' : '';
            $html .= '<tr' . $style . '><td>' . $label . ($isUltimo ? ' (atual)' : '') . '</td>';
            $html .= '<td style="text-align:right">' . fmt_brl($total) . '</td>';
            $varColor = ($var !== null && $var > 0) ? '#2e7d32' : '#c62828';
            $html .= '<td style="text-align:right;color:' . $varColor . ';">' . ($var !== null ? fmt_brl($var) : '-') . '</td></tr>';
            $prev = $total;
        }
        $html .= '</table>';
    }

    // Footer
    $html .= '<div class="footer">';
    $html .= '<strong>Analise gerada por Inteligencia Artificial.</strong> Os resultados sao indicativos e devem ser validados pelo usuario.<br>';
    $html .= date('d/m/Y H:i') . ' — Cleanalyze IA &copy; Desenvolvimento BBZ.';
    $html .= '</div>';

    $html .= '</body></html>';
    return $html;
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Analise de Prestacao de Contas por Cleanalyze IA</title>
  <base href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/../' ?>">
  <?php include __DIR__ . '/../includes/head.php'; ?>
  <style>
    :root{ --azul:#04193b; --azul-inst:#0664e4; --roxo-inst:#8578ef; --cinza:#b8b8c4; --cinzaClaro:#efeff4; }
    .stat-card{ text-align:center; padding:1.2rem .5rem; }
    .stat-card .number{ font-size:1.8rem; font-weight:700; color:var(--azul); }
    .stat-card .label{ font-size:.78rem; color:#666; }

    .badge-nova{ background:#fce4ec; color:#c62828; padding:2px 8px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .badge-ausente{ background:#fff2cc; color:#856404; padding:2px 8px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .badge-sub-nova{ background:#fce4ec; color:#c62828; padding:2px 6px; border-radius:3px; font-size:.72rem; }
    .badge-sub-removida{ background:#fff2cc; color:#856404; padding:2px 6px; border-radius:3px; font-size:.72rem; }

    .diff-card{ border:1px solid #ddd; border-radius:6px; padding:10px 14px; margin-bottom:8px; }
    .diff-card-nova{ border-left:4px solid #c62828; background:#fce4ec20; }
    .diff-card-ausente{ border-left:4px solid #856404; background:#fff2cc20; }
    .diff-card-sub-nova{ border-left:4px solid #e57373; background:#fce4ec10; }
    .diff-card-sub-removida{ border-left:4px solid #8578ef; background:#8578ef10; }

    .badge-fundo{ background:#6a1b9a; color:#fff; padding:3px 10px; border-radius:4px; font-weight:600; font-size:.75rem; }
    .fundo-alert-danger{ background:#f3e5f5; border:2px solid #6a1b9a; border-radius:8px; padding:16px; }
    .fundo-alert-ok{ background:#e8f5e9; border:2px solid #2e7d32; border-radius:8px; padding:16px; }
    .fundo-lancamento{ font-size:.82rem; color:#555; padding:2px 0 2px 16px; border-left:2px solid #6a1b9a; margin:3px 0; }

    .mes-bar{ display:flex; overflow-x:auto; gap:0; border:1px solid var(--cinza); border-radius:6px; margin-bottom:1rem; }
    .mes-bar .mes-item{ flex:1; min-width:70px; text-align:center; padding:8px 4px; border-right:1px solid #e0e0e0; font-size:.75rem; }
    .mes-bar .mes-item:last-child{ border-right:none; }
    .mes-bar .mes-item .num{ font-weight:700; font-size:1rem; }
    .mes-bar .mes-ultimo{ background:#548235; color:#fff; }
    .mes-bar .mes-penultimo{ background:var(--azul-inst); color:#fff; }
    .mes-bar .mes-mesmo{ background:var(--roxo-inst); color:#fff; }

    .tab-comparativo .nav-tabs{ border-bottom:2px solid var(--azul-inst); }
    .tab-comparativo .nav-link{
      font-size:.9rem; font-weight:600; padding:10px 20px;
      background:#e8e8e8; color:#333; border:1px solid #ccc; border-bottom:none;
      margin-right:4px; border-radius:8px 8px 0 0;
    }
    .tab-comparativo .nav-link:hover{ background:#d0d0d0; }
    .tab-comparativo .nav-link.active{
      background:var(--azul-inst); color:#fff; border-color:var(--azul-inst);
    }
    .ia-disclaimer{
      background:linear-gradient(135deg, #fff3e0 0%, #fff8e1 100%);
      border:2px solid #e65100; border-radius:8px; padding:14px 18px;
      color:#bf360c;
    }
    .ia-disclaimer strong{ color:#e65100; }

    .dropdown-subcats{ cursor:pointer; }
    .dropdown-subcats:hover{ background:#f5f5f5; }
    .subcat-detail{ display:none; }
    .subcat-detail.show{ display:table-row; }
  </style>
</head>
<body>
<?php $activePage = 'prestacao'; include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">

<?php if ($sucesso && $dados): ?>

  <!-- Header -->
  <div class="alert alert-success d-flex justify-content-between align-items-center">
    <div>
      <h5 class="mb-1">Analise de Prestacao de Contas por Cleanalyze IA</h5>
      <p class="mb-0">
        <strong><?= h($dados['condominio'] ?? '') ?></strong> —
        Periodo: <?= h($dados['periodo'] ?? '') ?>
      </p>
    </div>
  </div>

  <!-- Disclaimer IA -->
  <div class="ia-disclaimer mb-4">
    <div class="d-flex align-items-start gap-2">
      <span style="font-size:1.3rem;">&#9888;</span>
      <div>
        <strong>Analise gerada por Inteligencia Artificial</strong>
        <p class="mb-0" style="font-size:.85rem;">
          Esta e uma analise inicial automatizada para identificacao de anomalias e padroes.
          Os resultados sao indicativos e <strong>devem ser validados e revisados pelo usuario</strong>
          antes de qualquer tomada de decisao. Toda prestacao de contas deve ser conferida manualmente.
        </p>
      </div>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-2">
      <div class="card stat-card">
        <div class="number"><?= h($dados['totalLancamentos'] ?? 0) ?></div>
        <div class="label">Lancamentos<br>Totais</div>
      </div>
    </div>
    <?php
      $totalMeses = count($dados['meses'] ?? []);
      // Apresentar como "12 meses + mês atual" quando > 12
      $mesesLabel = $totalMeses > 12
        ? '12 meses<br>+ mes atual'
        : $totalMeses . ' meses';
    ?>
    <div class="col-md-2">
      <div class="card stat-card">
        <div class="number"><?= $totalMeses > 12 ? '12+1' : $totalMeses ?></div>
        <div class="label"><?= $mesesLabel ?></div>
      </div>
    </div>
    <?php $cm = $dados['comparativoMesmoMes'] ?? null; ?>
    <?php $ca = $dados['comparativoMesAnterior'] ?? null; ?>
    <div class="col-md-2">
      <div class="card stat-card">
        <div class="number" style="color:#8578ef;"><?= $cm ? count($cm['diferencas'] ?? []) : 'N/A' ?></div>
        <div class="label">Diffs<br>Mesmo Mes</div>
      </div>
    </div>
    <div class="col-md-2">
      <div class="card stat-card">
        <div class="number" style="color:#0664e4;"><?= $ca ? count($ca['diferencas'] ?? []) : 'N/A' ?></div>
        <div class="label">Diffs<br>Mes Anterior</div>
      </div>
    </div>
    <?php $am = $dados['analiseMensal'] ?? []; ?>
    <div class="col-md-2">
      <div class="card stat-card">
        <div class="number" style="color:#c62828;"><?= count($am['contasNovas'] ?? []) ?></div>
        <div class="label">Contas<br>Novas</div>
      </div>
    </div>
    <div class="col-md-2">
      <div class="card stat-card">
        <div class="number" style="color:#856404;"><?= count($am['contasAusentes'] ?? []) ?></div>
        <div class="label">Contas<br>Ausentes</div>
      </div>
    </div>
  </div>

  <!-- Action Buttons -->
  <div class="d-flex gap-2 mb-4 flex-wrap">
    <?php if (file_exists($saidaPath)): ?>
    <a href="web/download.php?f=<?= urlencode($saidaPath) ?>" class="btn btn-primary">
      Baixar XLSX Detalhado
    </a>
    <?php endif; ?>
    <form method="post" action="web/executar_prestacao_anual.php" style="display:inline;">
      <input type="hidden" name="export_pdf" value="1">
      <input type="hidden" name="json_path" value="<?= h($jsonPath) ?>">
      <button type="submit" class="btn btn-outline-primary">Baixar Relatorio PDF</button>
    </form>
    <a href="prestacao_anual.php" class="btn btn-outline-secondary">Nova Analise</a>
  </div>

  <!-- Barra de Meses -->
  <?php $meses = $dados['meses'] ?? []; ?>
  <?php if ($meses): ?>
  <div class="mes-bar">
    <?php foreach ($meses as $key => $info):
      $classe = '';
      if ($key === $dados['ultimoMes']) $classe = 'mes-ultimo';
      elseif ($key === $dados['penultimoMes']) $classe = 'mes-penultimo';
      elseif ($key === $dados['mesmoMesAnterior']) $classe = 'mes-mesmo';
    ?>
      <div class="mes-item <?= $classe ?>">
        <div class="num"><?= h($info['qtdLancamentos']) ?></div>
        <div><?= h($info['label']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="d-flex gap-3 mb-4" style="font-size:.75rem;">
    <span><span style="display:inline-block;width:12px;height:12px;background:#548235;border-radius:2px;"></span> Ultimo mes</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:#0664e4;border-radius:2px;"></span> Mes anterior</span>
    <?php if ($dados['mesmoMesAnterior']): ?>
    <span><span style="display:inline-block;width:12px;height:12px;background:#8578ef;border-radius:2px;"></span> Mesmo mes ano anterior</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- FUNDO DE RESERVA -->
  <?php $fr = $dados['fundoReserva'] ?? []; ?>
  <?php if (!empty($fr)): ?>
    <?php if ($fr['temDebitoMesAtual'] ?? false): ?>
      <div class="fundo-alert fundo-alert-danger mb-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge-fundo">FUNDO DE RESERVA</span>
          <strong style="color:#6a1b9a;">Debitos detectados no mes atual — requer verificacao</strong>
        </div>
        <div class="row g-3 mb-2">
          <div class="col-md-3">
            <small class="text-muted d-block">Debito no Mes Atual</small>
            <strong style="color:#c62828; font-size:1.1rem;"><?= fmt_brl($fr['debitoMesAtual']) ?></strong>
          </div>
          <div class="col-md-3">
            <small class="text-muted d-block">Saldo Final</small>
            <strong style="color:#6a1b9a; font-size:1.1rem;"><?= fmt_brl($fr['saldoAtual']) ?></strong>
          </div>
          <div class="col-md-3">
            <small class="text-muted d-block">Creditos no Periodo</small>
            <strong style="color:#2e7d32;"><?= fmt_brl($fr['creditosPeriodo']) ?></strong>
          </div>
        </div>
        <?php if (!empty($fr['lancamentosMesAtual'])): ?>
          <div class="mt-2">
            <small class="text-muted">Lancamentos no Fundo de Reserva (mes atual):</small>
            <?php foreach ($fr['lancamentosMesAtual'] as $l): ?>
              <div class="fundo-lancamento">
                <?= h($l['descricao']) ?>
                <?php if (!empty($l['data'])): ?> | <?= h($l['data']) ?><?php endif; ?>
                | <strong style="color:#c62828;"><?= fmt_brl($l['valor']) ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="fundo-alert fundo-alert-ok mb-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span style="font-size:1.1rem;">&#10003;</span>
          <strong style="color:#2e7d32;">Fundo de Reserva — Nenhum debito no mes atual</strong>
        </div>
        <div class="row g-3">
          <div class="col-md-3">
            <small class="text-muted d-block">Saldo Final</small>
            <strong style="color:#2e7d32; font-size:1.1rem;"><?= fmt_brl($fr['saldoAtual']) ?></strong>
          </div>
          <div class="col-md-3">
            <small class="text-muted d-block">Creditos no Periodo</small>
            <strong style="color:#2e7d32;"><?= fmt_brl($fr['creditosPeriodo']) ?></strong>
          </div>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- RESUMO FINANCEIRO -->
  <?php $resumo = $dados['resumoFinanceiro'] ?? []; ?>
  <?php if ($resumo): ?>
  <div class="card mb-4">
    <div class="card-header" style="background:#0664e4; color:#fff;">
      <strong>Resumo Financeiro Contabil — Periodo Completo</strong>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
          <thead style="background:var(--azul); color:#fff;">
            <tr><th>Categoria</th><th class="text-end">Saldo Anterior</th><th class="text-end">Creditos</th><th class="text-end">Debitos</th><th class="text-end">Saldo Atual</th></tr>
          </thead>
          <tbody>
          <?php foreach ($resumo as $r): ?>
            <tr>
              <td><strong><?= h($r['categoria']) ?></strong></td>
              <td class="text-end"><?= fmt_brl($r['saldoAnterior']) ?></td>
              <td class="text-end" style="color:#2e7d32;"><?= fmt_brl($r['creditos']) ?></td>
              <td class="text-end" style="color:#c62828;"><?= fmt_brl($r['debitos']) ?></td>
              <td class="text-end"><strong><?= fmt_brl($r['saldoAtual']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ANALISE DE PADRAO MENSAL -->
  <?php if (!empty($am) && (!empty($am['ausentesNoMes']) || !empty($am['novasSemHistorico']) || !empty($am['contasNovas']) || !empty($am['contasAusentes']))): ?>
  <?php
    $mesAtualNum = $am['mesAtual'] ?? 0;
    $mesAtualNome = $nomesMes[$mesAtualNum] ?? $mesAtualNum;
  ?>
  <div class="card mb-4">
    <div class="card-header" style="background:#0664e4; color:#fff;">
      <strong>Analise de Padrao Mensal</strong>
      <small class="ms-2 opacity-75">— comparando <?= $mesAtualNome ?> atual com historico de 12 meses</small>
    </div>
    <div class="card-body">

      <!-- Contas inteiras novas -->
      <?php if (!empty($am['contasNovas'])): ?>
        <h6 style="color:#c62828;">Contas novas — nunca existiram no periodo anterior</h6>
        <p class="text-muted small mb-2">Contas inteiras que aparecem no ultimo mes mas nao existem em nenhum dos meses anteriores.</p>
        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered">
            <thead><tr style="background:#fce4ec;"><th>Conta</th><th class="text-end">Total Atual</th></tr></thead>
            <tbody>
            <?php foreach ($am['contasNovas'] as $cn): ?>
              <tr>
                <td><strong><?= h($cn['conta']) ?></strong></td>
                <td class="text-end" style="color:#c62828;"><?= fmt_brl($cn['totalAtual']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- Contas inteiras ausentes -->
      <?php if (!empty($am['contasAusentes'])): ?>
        <h6 style="color:#856404;">Contas ausentes — nao aparecem no mes atual</h6>
        <p class="text-muted small mb-2">Contas que existiam em meses anteriores mas nao aparecem no ultimo mes.</p>
        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered">
            <thead><tr style="background:#fff2cc;"><th>Conta</th><th class="text-end">Ultimo Valor</th><th>Ultima Ref.</th><th>Recorrencia</th><th>Meses</th></tr></thead>
            <tbody>
            <?php foreach ($am['contasAusentes'] as $ca2):
              $refParts = explode('/', $ca2['mesReferencia'] ?? '');
              $refLabel = isset($nomesMes[(int)($refParts[0] ?? 0)]) ? $nomesMes[(int)$refParts[0]] . '/' . ($refParts[1] ?? '') : ($ca2['mesReferencia'] ?? '');
            ?>
              <tr>
                <td><strong><?= h($ca2['conta']) ?></strong></td>
                <td class="text-end"><?= fmt_brl($ca2['totalUltimaRef']) ?></td>
                <td><?= h($refLabel) ?></td>
                <td><?= $ca2['recorrencia'] ?>/12 meses</td>
                <td><small class="text-muted"><?php
                  $ms = array_map(function($m) use ($nomesMes) { return $nomesMes[$m] ?? $m; }, $ca2['mesesPresente']);
                  echo implode(', ', $ms);
                ?></small></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if (!empty($am['novasSemHistorico'])): ?>
        <h6 style="color:#c62828;">Lancamentos sem historico no periodo</h6>
        <p class="text-muted small mb-2">Subcategorias que aparecem no ultimo mes mas nunca existiram nos meses anteriores do periodo.</p>
        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered">
            <thead><tr style="background:#fce4ec;"><th>Conta</th><th>Subcategoria</th><th class="text-end">Total Atual</th></tr></thead>
            <tbody>
            <?php foreach ($am['novasSemHistorico'] as $n): ?>
              <tr>
                <td><?= h($n['conta']) ?></td>
                <td><strong><?= h($n['subcategoria']) ?></strong></td>
                <td class="text-end" style="color:#c62828;"><?= fmt_brl($n['totalAtual']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if (!empty($am['ausentesNoMes'])): ?>
        <h6 style="color:#856404;">Lancamentos esperados em <?= $mesAtualNome ?> mas ausentes</h6>
        <p class="text-muted small mb-2">Subcategorias que existiam em <?= $mesAtualNome ?> do ano anterior mas nao aparecem no mes atual.</p>
        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead><tr style="background:#fffde7;"><th>Conta</th><th>Subcategoria</th><th class="text-end">Total em <?= $mesAtualNome ?> Ant.</th><th>Recorrencia</th><th>Meses</th></tr></thead>
            <tbody>
            <?php foreach ($am['ausentesNoMes'] as $a): ?>
              <tr>
                <td><?= h($a['conta']) ?></td>
                <td><strong><?= h($a['subcategoria']) ?></strong></td>
                <td class="text-end"><?= fmt_brl($a['totalMesmoMesAnterior']) ?></td>
                <td><?= $a['recorrencia'] ?>/12 meses</td>
                <td><small class="text-muted"><?php
                  $ms = array_map(function($m) use ($nomesMes) { return $nomesMes[$m] ?? $m; }, $a['mesesPresente']);
                  echo implode(', ', $ms);
                ?></small></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- VISAO PANORAMICA: TOTAIS POR MES -->
  <?php $totaisPorMes = $am['totaisPorMes'] ?? []; ?>
  <?php if ($totaisPorMes): ?>
  <div class="card mb-4">
    <div class="card-header" style="background:#548235; color:#fff;">
      <strong>Totais de Despesas por Mes</strong>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
          <thead style="background:var(--azul); color:#fff;">
            <tr><th>Mes</th><th class="text-end">Total Despesas</th><th class="text-end">Variacao</th></tr>
          </thead>
          <tbody>
          <?php
            $prevTotal = null;
            foreach ($totaisPorMes as $mesKey => $total):
              $parts = explode('/', $mesKey);
              $mesNum = (int)$parts[0];
              $label = ($nomesMes[$mesNum] ?? $mesKey) . '/' . ($parts[1] ?? '');
              $var = ($prevTotal !== null) ? ($total - $prevTotal) : null;
              $varClass = ($var !== null && $var > 0) ? 'color:#2e7d32;' : (($var !== null && $var < 0) ? 'color:#c62828;' : '');
              $isUltimo = ($mesKey === $dados['ultimoMes']);
          ?>
            <tr style="<?= $isUltimo ? 'background:#e8f5e9; font-weight:600;' : '' ?>">
              <td><?= h($label) ?><?= $isUltimo ? ' (atual)' : '' ?></td>
              <td class="text-end"><?= fmt_brl($total) ?></td>
              <td class="text-end" style="<?= $varClass ?>">
                <?= $var !== null ? fmt_brl($var) : '-' ?>
              </td>
            </tr>
          <?php $prevTotal = $total; endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- TABS: COMPARATIVOS -->
  <div class="tab-comparativo">
  <h5 class="mb-3" style="color:var(--azul);">Comparativos Detalhados</h5>
  <ul class="nav nav-tabs mb-0" role="tablist">
    <?php if ($cm): ?>
    <li class="nav-item">
      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-mesmo-mes" type="button">
        Mesmo Mes: <?= h($cm['labelAnterior']) ?> vs <?= h($cm['labelAtual']) ?>
      </button>
    </li>
    <?php endif; ?>
    <?php if ($ca): ?>
    <li class="nav-item">
      <button class="nav-link <?= !$cm ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-mes-anterior" type="button">
        Mes Anterior: <?= h($ca['labelAnterior']) ?> vs <?= h($ca['labelAtual']) ?>
      </button>
    </li>
    <?php endif; ?>
  </ul>

  <div class="tab-content border border-top-0 rounded-bottom p-3 mb-4">

    <!-- TAB: MESMO MES ANO ANTERIOR -->
    <?php if ($cm): ?>
    <div class="tab-pane fade show active" id="tab-mesmo-mes">
      <h6 class="mb-3">Comparando <strong style="color:#8578ef;"><?= h($cm['labelAnterior']) ?></strong> vs <strong style="color:#548235;"><?= h($cm['labelAtual']) ?></strong></h6>

      <!-- Totais por Conta -->
      <?php $totais = $cm['totaisComparativo'] ?? []; ?>
      <?php if ($totais): ?>
      <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered">
          <thead style="background:var(--azul); color:#fff;">
            <tr>
              <th>Secao</th><th>Conta</th>
              <th class="text-end">Total <?= h($cm['labelAnterior']) ?></th>
              <th class="text-end">Total <?= h($cm['labelAtual']) ?></th>
              <th class="text-end">Diferenca</th><th>Obs</th>
            </tr>
          </thead>
          <tbody>
          <?php $loop_idx = 0; foreach ($totais as $t):
            $loop_idx++;
            $diff = ($t['diferenca'] ?? 0);
            $diffClass = $diff > 0 ? 'color:#c62828;' : ($diff < 0 ? 'color:#2e7d32;' : '');
            $rowBg = '';
            if (($t['obs'] ?? '') === 'NOVA') $rowBg = 'background:#fce4ec;';
            elseif (($t['obs'] ?? '') === 'AUSENTE') $rowBg = 'background:#fff2cc;';
            $contaKey = $t['secao'] . ' > ' . $t['conta'];
            $subcats = $cm['subcategoriasPorConta'][$contaKey] ?? [];
          ?>
            <tr class="<?= $subcats ? 'dropdown-subcats' : '' ?>" style="<?= $rowBg ?>">
              <td><?= h($t['secao']) ?></td>
              <td>
                <?php if ($subcats): ?><span class="dropdown-arrow" style="font-size:.7rem; display:inline-block; transition:transform .2s;">&#9654;</span><?php endif; ?>
                <strong><?= h($t['conta']) ?></strong>
              </td>
              <td class="text-end"><?= fmt_brl($t['totalAnterior']) ?></td>
              <td class="text-end"><?= fmt_brl($t['totalAtual']) ?></td>
              <td class="text-end" style="<?= $diffClass ?>"><?= fmt_brl($diff) ?></td>
              <td>
                <?php if (($t['obs'] ?? '') === 'NOVA'): ?><span class="badge-nova">NOVA</span>
                <?php elseif (($t['obs'] ?? '') === 'AUSENTE'): ?><span class="badge-ausente">AUSENTE</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ($subcats): foreach ($subcats as $sc):
              $scBg = '';
              if (($sc['status'] ?? '') === 'NOVA') $scBg = 'background:#fce4ec40;';
              elseif (($sc['status'] ?? '') === 'AUSENTE') $scBg = 'background:#fff2cc40;';
            ?>
            <tr class="subcat-detail" style="<?= $scBg ?>font-size:.82rem;">
              <td></td>
              <td style="padding-left:24px;">
                <?php if (($sc['status'] ?? '') === 'NOVA'): ?><span class="badge-sub-nova">NOVA</span>
                <?php elseif (($sc['status'] ?? '') === 'AUSENTE'): ?><span class="badge-sub-removida">AUSENTE</span>
                <?php endif; ?>
                <?= h($sc['subcategoria']) ?>
              </td>
              <td class="text-end"><?= fmt_brl($sc['totalAnterior']) ?></td>
              <td class="text-end"><?= fmt_brl($sc['totalAtual']) ?></td>
              <td class="text-end" style="<?= ($sc['totalAtual'] - $sc['totalAnterior']) > 0 ? 'color:#c62828;' : 'color:#2e7d32;' ?>">
                <?= fmt_brl($sc['totalAtual'] - $sc['totalAnterior']) ?>
              </td>
              <td></td>
            </tr>
            <?php endforeach; endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <!-- Diferencas -->
      <?php $diffs = $cm['diferencas'] ?? []; ?>
      <?php if ($diffs): ?>
      <h6 class="mt-3">Diferencas Detalhadas (<?= count($diffs) ?>)</h6>
      <?php
        $tipoPrioridade = ['CONTA NOVA'=>0,'SUBCATEGORIA NOVA'=>1,'CONTA AUSENTE'=>2,'SUBCATEGORIA REMOVIDA'=>3];
        usort($diffs, function($a,$b) use ($tipoPrioridade) {
            return ($tipoPrioridade[$a['tipo']] ?? 9) - ($tipoPrioridade[$b['tipo']] ?? 9);
        });
        $tipoClasses = ['CONTA NOVA'=>'diff-card-nova','CONTA AUSENTE'=>'diff-card-ausente',
                        'SUBCATEGORIA NOVA'=>'diff-card-sub-nova','SUBCATEGORIA REMOVIDA'=>'diff-card-sub-removida'];
        $tipoBadges = ['CONTA NOVA'=>'badge-nova','CONTA AUSENTE'=>'badge-ausente',
                       'SUBCATEGORIA NOVA'=>'badge-sub-nova','SUBCATEGORIA REMOVIDA'=>'badge-sub-removida'];
      ?>
      <?php foreach ($diffs as $d):
        $cardClass = $tipoClasses[$d['tipo']] ?? '';
        $badgeClass = $tipoBadges[$d['tipo']] ?? '';
        $ant = ($d['totalAnterior'] ?? 0) ? fmt_brl($d['totalAnterior']) : '-';
        $atu = ($d['totalAtual'] ?? 0) ? fmt_brl($d['totalAtual']) : '-';
      ?>
        <div class="diff-card <?= $cardClass ?>">
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
          <?php if (!empty($d['detalhes'])): ?>
            <div class="text-muted mt-1" style="font-size:.82rem;"><?= h($d['detalhes']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- TAB: MES ANTERIOR -->
    <?php if ($ca): ?>
    <div class="tab-pane fade <?= !$cm ? 'show active' : '' ?>" id="tab-mes-anterior">
      <h6 class="mb-3">Comparando <strong style="color:#0664e4;"><?= h($ca['labelAnterior']) ?></strong> vs <strong style="color:#548235;"><?= h($ca['labelAtual']) ?></strong></h6>

      <?php $totais2 = $ca['totaisComparativo'] ?? []; ?>
      <?php if ($totais2): ?>
      <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered">
          <thead style="background:var(--azul); color:#fff;">
            <tr>
              <th>Secao</th><th>Conta</th>
              <th class="text-end">Total <?= h($ca['labelAnterior']) ?></th>
              <th class="text-end">Total <?= h($ca['labelAtual']) ?></th>
              <th class="text-end">Diferenca</th><th>Obs</th>
            </tr>
          </thead>
          <tbody>
          <?php $loop_idx2 = 0; foreach ($totais2 as $t):
            $diff = ($t['diferenca'] ?? 0);
            $diffClass = $diff > 0 ? 'color:#c62828;' : ($diff < 0 ? 'color:#2e7d32;' : '');
            $rowBg = '';
            if (($t['obs'] ?? '') === 'NOVA') $rowBg = 'background:#fce4ec;';
            elseif (($t['obs'] ?? '') === 'AUSENTE') $rowBg = 'background:#fff2cc;';
            $contaKey2 = $t['secao'] . ' > ' . $t['conta'];
            $subcats2 = $ca['subcategoriasPorConta'][$contaKey2] ?? [];
            $loop_idx2++;
          ?>
            <tr class="<?= $subcats2 ? 'dropdown-subcats' : '' ?>" style="<?= $rowBg ?>">
              <td><?= h($t['secao']) ?></td>
              <td>
                <?php if ($subcats2): ?><span class="dropdown-arrow" style="font-size:.7rem; display:inline-block; transition:transform .2s;">&#9654;</span><?php endif; ?>
                <strong><?= h($t['conta']) ?></strong>
              </td>
              <td class="text-end"><?= fmt_brl($t['totalAnterior']) ?></td>
              <td class="text-end"><?= fmt_brl($t['totalAtual']) ?></td>
              <td class="text-end" style="<?= $diffClass ?>"><?= fmt_brl($diff) ?></td>
              <td>
                <?php if (($t['obs'] ?? '') === 'NOVA'): ?><span class="badge-nova">NOVA</span>
                <?php elseif (($t['obs'] ?? '') === 'AUSENTE'): ?><span class="badge-ausente">AUSENTE</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ($subcats2): foreach ($subcats2 as $sc):
              $scBg = '';
              if (($sc['status'] ?? '') === 'NOVA') $scBg = 'background:#fce4ec40;';
              elseif (($sc['status'] ?? '') === 'AUSENTE') $scBg = 'background:#fff2cc40;';
            ?>
            <tr class="subcat-detail" style="<?= $scBg ?>font-size:.82rem;">
              <td></td>
              <td style="padding-left:24px;">
                <?php if (($sc['status'] ?? '') === 'NOVA'): ?><span class="badge-sub-nova">NOVA</span>
                <?php elseif (($sc['status'] ?? '') === 'AUSENTE'): ?><span class="badge-sub-removida">AUSENTE</span>
                <?php endif; ?>
                <?= h($sc['subcategoria']) ?>
              </td>
              <td class="text-end"><?= fmt_brl($sc['totalAnterior']) ?></td>
              <td class="text-end"><?= fmt_brl($sc['totalAtual']) ?></td>
              <td class="text-end" style="<?= ($sc['totalAtual'] - $sc['totalAnterior']) > 0 ? 'color:#c62828;' : 'color:#2e7d32;' ?>">
                <?= fmt_brl($sc['totalAtual'] - $sc['totalAnterior']) ?>
              </td>
              <td></td>
            </tr>
            <?php endforeach; endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <!-- Diferencas mes anterior -->
      <?php $diffs2 = $ca['diferencas'] ?? []; ?>
      <?php if ($diffs2): ?>
      <h6 class="mt-3">Diferencas Detalhadas (<?= count($diffs2) ?>)</h6>
      <?php
        usort($diffs2, function($a,$b) use ($tipoPrioridade) {
            return ($tipoPrioridade[$a['tipo']] ?? 9) - ($tipoPrioridade[$b['tipo']] ?? 9);
        });
      ?>
      <?php foreach ($diffs2 as $d):
        $cardClass = $tipoClasses[$d['tipo']] ?? '';
        $badgeClass = $tipoBadges[$d['tipo']] ?? '';
        $ant = ($d['totalAnterior'] ?? 0) ? fmt_brl($d['totalAnterior']) : '-';
        $atu = ($d['totalAtual'] ?? 0) ? fmt_brl($d['totalAtual']) : '-';
      ?>
        <div class="diff-card <?= $cardClass ?>">
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
          <?php if (!empty($d['detalhes'])): ?>
            <div class="text-muted mt-1" style="font-size:.82rem;"><?= h($d['detalhes']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div><!-- /tab-content -->
  </div><!-- /tab-comparativo -->

  <!-- DETALHES TECNICOS (admin) -->
  <?php if (auth_is_admin()): ?>
  <div class="card mb-4 mt-4">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:#e9ecef; cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#detalhes-tecnicos">
      <strong style="font-size:.85rem;">Detalhes Tecnicos (admin)</strong>
      <small class="text-muted">clique para expandir</small>
    </div>
    <div class="collapse" id="detalhes-tecnicos">
      <div class="card-body" style="font-size:.78rem;">
        <p><strong>Comando:</strong></p>
        <pre class="bg-light p-2 rounded" style="font-size:.75rem;"><?= h($cmd) ?></pre>
        <p><strong>Saida:</strong></p>
        <pre class="bg-light p-2 rounded" style="font-size:.75rem; max-height:400px; overflow-y:auto;"><?= h($output) ?></pre>
        <p class="mb-1"><strong>XLSX:</strong> <?= h($saidaPath) ?></p>
        <p class="mb-1"><strong>JSON:</strong> <?= h($jsonPath) ?></p>
        <p class="mb-0"><strong>Exit code:</strong> <?= $exitCode ?></p>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php elseif (!$sucesso): ?>
  <!-- ERRO -->
  <div class="alert alert-danger">
    <h5>Erro ao processar PDF</h5>
    <pre style="white-space:pre-wrap; font-size:.82rem;"><?= h($output) ?></pre>
  </div>
  <?php if (auth_is_admin()): ?>
  <div class="card mb-3">
    <div class="card-header"><strong>Diagnostico (admin)</strong></div>
    <div class="card-body">
      <pre style="font-size:.78rem;"><?= h($cmd) ?></pre>
      <pre style="font-size:.78rem;"><?= h($output) ?></pre>
    </div>
  </div>
  <?php endif; ?>
  <a href="prestacao_anual.php" class="btn btn-primary">Tentar novamente</a>
<?php endif; ?>

</div><!-- /container -->

<footer class="text-center text-muted my-4" style="font-size:.8rem;">
  <div>Analise gerada por Inteligencia Artificial. Os resultados sao indicativos e devem ser validados pelo usuario.</div>
  <div class="mt-1">2025 &copy; Desenvolvimento BBZ.</div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Dropdown de subcategorias
document.querySelectorAll('.dropdown-subcats').forEach(function(row) {
  row.addEventListener('click', function() {
    var arrow = this.querySelector('.dropdown-arrow');
    var next = this.nextElementSibling;
    var isExpanding = next && next.classList.contains('subcat-detail') && !next.classList.contains('show');
    while (next && next.classList.contains('subcat-detail')) {
      next.classList.toggle('show');
      next = next.nextElementSibling;
    }
    if (arrow) {
      arrow.style.transform = isExpanding ? 'rotate(90deg)' : 'rotate(0deg)';
    }
  });
});
</script>
</body>
</html>
