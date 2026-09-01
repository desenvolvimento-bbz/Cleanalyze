<?php
// web/extrair_relatorios.php
// Formulário unificado, no estilo Cleanalyze, para escolher o tipo e enviar o PDF.
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>💾 Extrair Relatórios</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
  <link href="../assets/css/bbz.css" rel="stylesheet">
  <style>
    body { background:var(--bbz-cinza-claro); }
    h1 { color:#04193b; }
    .btn-outline-secondary{ color:#04193b; border-color:#b8b8c4; }
  </style>
</head>
<body>
<div class="container">
  <h1 class="mt-3">💾 Extrair Relatórios</h1>

  <div class="mb-3">
    <a href="../index.php" class="btn btn-outline-secondary">⬅️ Voltar</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form action="executar_extracao.php" method="post" enctype="multipart/form-data" class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Tipo de relatório</label>
          <select name="tipo" class="form-select" required>
            <option value="">-- Selecione --</option>
            <option value="inadimplencia">Ahreas (Inadimplência)</option>
            <option value="ahreas">Ahreas (Unidades)</option>
            <option value="lello_inadimplencia">Lello (Inadimplência)</option>
            <option value="lello">Lello (Unidades)</option>
          </select>
          <div class="form-text">Ambos rodam na mesma pipeline Python (CLI).</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Arquivo PDF</label>
          <input type="file" name="pdf" accept="application/pdf" class="form-control" required>
        </div>

        <div class="col-md-6">
          <label class="form-label">Nome do arquivo de saída (opcional)</label>
          <input type="text" name="saidaBase" class="form-control" placeholder="ex.: Unidades_agosto">
        </div>

        <div class="col-12">
          <button class="btn btn-primary" type="submit">Extrair e gerar planilha</button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
