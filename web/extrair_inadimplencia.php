<?php
// Página no estilo Cleanalyze para enviar o PDF de Inadimplência.
// Envia para processa_inadimplencia.php

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>💾 Extração de Inadimplência</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap (mesma linha visual do Cleanalyze) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
  <link href="../assets/css/bbz.css" rel="stylesheet">
  <style>
    body { background:var(--bbz-cinza-claro); }
    h1,h3 { color:#04193b; }
    .btn-outline-secondary{ color:#04193b; border-color:#b8b8c4; }
  </style>
</head>
<body>
<div class="container">
  <h1 class="mt-3">💾 Extração de Inadimplência</h1>

  <div class="mb-3">
    <a href="../index.php" class="btn btn-outline-secondary">⬅️ Voltar</a>
    <a href="extrair_relatorios.php" class="btn btn-outline-secondary ms-2">📚 Outros relatórios</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form action="processa_inadimplencia.php" method="post" enctype="multipart/form-data" class="row g-3">
        <div class="col-md-8">
          <label class="form-label">Selecione o PDF</label>
          <input type="file" name="pdf" accept="application/pdf" class="form-control" required>
          <div class="form-text">Use o PDF exportado pelo sistema (ex.: “INADIMPLENCIA SISTEMA.pdf”).</div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Nome do arquivo de saída (opcional)</label>
          <input type="text" name="saidaBase" class="form-control" placeholder="ex.: Inadimplencia_agosto">
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
