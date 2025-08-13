<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login(); // exige login
app_log('page.view', ['page'=>basename(__FILE__)]);

// >>> Força o Python no Windows (usa "python" do PATH; ajuste se quiser o caminho completo)
if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
    putenv('PYTHON_BIN=python'); 
    // Se preferir apontar direto pro executável, use:
    // putenv('PYTHON_BIN=C:\\Users\\SeuUsuario\\AppData\\Local\\Programs\\Python\\Python312\\python.exe');
}

require_once __DIR__ . '/vendor/autoload.php';  // <<< importa as classes instaladas pelo Composer
use PhpOffice\PhpSpreadsheet\IOFactory;

function exibirPlanilhaComoTabelaHTML($arquivo_xlsx) {
    $spreadsheet = IOFactory::load($arquivo_xlsx);
    $sheet = $spreadsheet->getActiveSheet();
    // Mantém vazios e chaves A,B,C,...
    $dados = $sheet->toArray(null, true, true, true);

    if (empty($dados)) {
        echo "<div class='alert alert-warning'>Planilha sem dados.</div>";
        return;
    }

    // Cabeçalho = primeira linha
    $headerRow = $dados[1] ?? [];
    $headers   = array_values($headerRow);
    $colCount  = count($headers);

    echo '<div class="table-responsive mt-4">';
    echo '<table id="tabelaPreview" class="table table-bordered table-hover table-sm">';

    // THEAD
    echo '<thead><tr>';
    foreach ($headers as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead>';

    // TBODY
    echo '<tbody>';
    $totalRows = count($dados);
    for ($i = 2; $i <= $totalRows; $i++) {
        if (!isset($dados[$i])) continue;
        $linhaAssoc = $dados[$i];

        // Reindexa para numérico e garante colCount
        $cells = array_values($linhaAssoc);
        $len   = count($cells);
        if ($len < $colCount) {
            $cells = array_pad($cells, $colCount, '');
        } elseif ($len > $colCount) {
            $cells = array_slice($cells, 0, $colCount);
        }

        echo '<tr>';
        foreach ($cells as $c) {
            echo '<td>' . htmlspecialchars($c) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody>';

    echo '</table>';
    echo '</div>';
}


if ($_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = __DIR__ . '/uploads/';
    $output_dir = __DIR__ . '/output/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    if (!is_dir($output_dir)) mkdir($output_dir, 0777, true);

    $nome_pdf = uniqid('relatorio_') . '.pdf';
    $pdf_path = $upload_dir . $nome_pdf;
    move_uploaded_file($_FILES['pdf']['tmp_name'], $pdf_path);

    $modelo_path = __DIR__ . '/modelo_planilha_importacao.xlsx';
    $modelo_nome = 'ahreas';

    // ===== Execução robusta do Python =====
    $base       = __DIR__;
    $py         = (stripos(PHP_OS, 'WIN') === 0) ? 'python' : 'python3';
    $script     = $base . '/extractor_pdf.py';                      // ajuste o nome se necessário
    $modelo_path= $base . '/modelo_planilha_importacao.xlsx';       // já existente no seu código
    $output_dir = $output_dir;                                      // já definido acima (absoluto)
    $xlsx_gerado= $output_dir . 'relatorio_unidades_extraido.xlsx'; // onde esperamos o XLSX

    // sanity checks ajudam quando o repo é clonado numa máquina nova
    $preFlightError = null;
    if (!file_exists($script))        $preFlightError = "Backend Python não encontrado: $script";
    elseif (!file_exists($modelo_path)) $preFlightError = "Modelo XLSX não encontrado: $modelo_path";
    elseif (!is_dir($output_dir))       $preFlightError = "Pasta de saída não existe: $output_dir";

    $stdout = ''; $stderr = ''; $code = 1;

    if ($preFlightError) {
        $stderr = $preFlightError;
    } else {
        // monta argumentos com escapes
        $args = [
            escapeshellarg($script),
            '--pdf',         escapeshellarg($pdf_path),
            '--modelo',      escapeshellarg($modelo_path),
            '--saida',       escapeshellarg($output_dir),
            '--modelo_nome', escapeshellarg($modelo_nome)
        ];

        // Se a env POPPLER_PDFTOTEXT estiver definida, passamos explicitamente
        $pdftotext = getenv('POPPLER_PDFTOTEXT') ?: ($_ENV['POPPLER_PDFTOTEXT'] ?? null);
        if (!empty($pdftotext)) {
            $args[] = '--pdftotext';
            $args[] = escapeshellarg($pdftotext);
        }

        $cmd = escapeshellcmd($py) . ' ' . implode(' ', $args);

        // captura stdout/stderr
        $descriptors = [
            1 => ['pipe','w'], // stdout
            2 => ['pipe','w'], // stderr
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $base);
        if (is_resource($proc)) {
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $code   = proc_close($proc);
        } else {
            $stderr = 'Falha ao iniciar processo Python.';
            $code = 1;
        }
    }

    $comando = "python3 extractor_pdf.py"
        . " --pdf " . escapeshellarg($pdf_path)
        . " --modelo " . escapeshellarg($modelo_path)
        . " --saida " . escapeshellarg($output_dir)
        . " --modelo_nome " . escapeshellarg($modelo_nome);

    $saida = shell_exec($comando);
    $xlsx_gerado = $output_dir . 'relatorio_unidades_extraido.xlsx';
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <title>Prévia dos Dados</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
        <style>
            body {
                background-color: #efeff4;
                color: #04193b;
                padding: 20px;
            }
            h1, h3 {
                color: #04193b;
            }
            .btn-primary {
                background-color: #04193b;
                border-color: #04193b;
            }
            .btn-outline-secondary {
                color: #04193b;
                border-color: #b8b8c4;
            }
            table thead th {
                background-color: #b8b8c4;
                color: #04193b;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>✅ Extração Concluída</h1>
            <p>Abaixo está a prévia dos dados extraídos do PDF enviado:</p>
            <a href="output/relatorio_unidades_extraido.xlsx" class="btn btn-primary mb-3" download>📥 Baixar Planilha XLSX</a><br>
            <a href="index.php" class="btn btn-outline-secondary">🔁 Voltar ao Início</a>
            <?php
            if (file_exists($xlsx_gerado) && $code === 0) {
                exibirPlanilhaComoTabelaHTML($xlsx_gerado);
            } else {
                echo "<div class='alert alert-danger'>Falha na extração.</div>";
                if ($code !== 0) {
                    echo "<p class='text-muted'>O processo Python retornou código $code.</p>";
                }
                // mostra stderr (ou stdout) para diagnosticar (ex.: pdftotext não encontrado)
                $log = trim($stderr) ?: trim($stdout);
                if ($log) {
                    echo "<pre style='white-space:pre-wrap;'>".htmlspecialchars($log)."</pre>";
                } else {
                    echo "<pre style='white-space:pre-wrap;'>Sem logs do processo.</pre>";
                }
                // dica rápida
                echo "<div class='alert alert-secondary mt-2'>Dica: rode <code>diag.php</code> para checar se o <code>pdftotext</code> está instalado/visível.</div>";
            }
            ?>
            <hr>
            <a href="index.php" class="btn btn-outline-secondary">🔁 Voltar ao Início</a>
        </div>

        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
        <script>
            $(document).ready(function () {
                $('#tabelaPreview').DataTable({
                    language: {
                        url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/pt-BR.json"
                    }
                });
            });
        </script>
        <footer class="text-center text-muted my-4">
        2025 © Desenvolvimento BBZ.
        </footer>
    </body>
    </html>
    <?php
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['pdf'])) {
        $err = "Nenhum arquivo recebido (verifique se o formulário tem enctype='multipart/form-data').";
    } else {
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
        $err = "Erro no upload do PDF! Código=$code — " . ($map[$code] ?? 'Desconhecido');

        // Diagnóstico rápido dos limites atuais
        $err .= " (upload_max_filesize=" . ini_get('upload_max_filesize')
              . ", post_max_size=" . ini_get('post_max_size') . ")";
    }
}
}
