<?php
/**
 * Cleanalyze – extrair.php
 *
 * Página de extração com identidade visual do sistema.
 */
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Extração de PDF - Cleanalyze</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
  <link href="../assets/css/bbz.css" rel="stylesheet">
  <style>
    :root{ --azul:#04193b; --cinza:#b8b8c4; --cinzaClaro:#efeff4; }
    body{ background:var(--cinzaClaro); color:var(--azul); font-family: 'Manrope', sans-serif; }
    .navbar{ background:var(--azul); }
    .navbar .navbar-brand, .navbar a{ color:#fff !important; }
    .card{ border-color:var(--cinza); }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg">
  <div class="container">
    <a class="navbar-brand" href="../index.php">Cleanalyze</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link active" href="../index.php">Extrair</a></li>
        <li class="nav-item"><a class="nav-link" href="../comparar.php">Comparar</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="row g-4">
    <!-- Sidebar -->
    <aside class="col-md-3">
      <h4 class="m-0">Menu</h4>
      <div class="list-group">
        <a href="../index.php" class="list-group-item list-group-item-action active">
          📄 Extrair (PDF → XLSX)
        </a>
        <a href="../comparar.php" class="list-group-item list-group-item-action">
          🔍 Comparar (A × B)
        </a>
      </div>
    </aside>

    <!-- Main -->
    <main class="col-md-9">
      <div class="card">
        <div class="card-body">
          <h4 class="mb-3">📄 Extração de PDF</h4>
          <p class="text-secondary">Selecione o tipo de relatório e envie o arquivo PDF para gerar a planilha.</p>

          <form action="executar_extracao.php" method="post" enctype="multipart/form-data" class="row g-3">
            <!-- Tipo -->
            <div class="col-md-6">
              <label class="form-label">Tipo de relatório</label>
              <select name="tipo" class="form-select" required>
                <option value="">-- Selecione --</option>
                <option value="inadimplencia">Ahreas (Inadimplência)</option>
                <option value="ahreas">Ahreas (Unidades)</option>
                <option value="lello_inadimplencia">Lello (Inadimplência)</option>
                <option value="lello">Lello (Unidades)</option>
              </select>
              <div class="form-text">Define qual plugin e planilha-modelo serão usados.</div>
            </div>

            <!-- PDF -->
            <div class="col-md-6">
              <label class="form-label">Arquivo PDF</label>
              <input type="file" name="pdf" accept="application/pdf" class="form-control" required>
              <div class="form-text">Envie o PDF exportado do sistema.</div>
            </div>

            <!-- Nome do XLSX (opcional) -->
            <div class="col-md-6">
              <label class="form-label">Nome do arquivo de saída (opcional)</label>
              <input type="text" name="saidaBase" class="form-control" placeholder="ex.: Unidades_agosto_2025">
              <div class="form-text">Se não preencher, o sistema cria um nome automático.</div>
            </div>

            <!-- Botão -->
            <div class="col-md-6 d-flex align-items-end">
              <button class="btn btn-primary" type="submit">Extrair Dados</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Card de ajuda -->
      <div class="card mt-3">
        <div class="card-header">Dicas</div>
        <div class="card-body">
          <ul class="mb-0">
            <li><strong>Ahreas (Unidades)</strong>: usa <code>config/ahreas.json</code> + <code>modelo_planilha_importacao.xlsx</code></li>
            <li><strong>Lello (Unidades)</strong>: relação de endereçamento; usa <code>config/lello.json</code> + <code>modelo_planilha_importacao.xlsx</code>. Unidades com mais de um condômino saem em uma única linha, com os dados separados por <code>|</code></li>
            <li><strong>Lello (Inadimplência)</strong>: relatório "Cotas Atrasadas"; gera uma linha por conta contábil de cada cobrança</li>
            <li><strong>Ahreas (Inadimplência)</strong>: usa <code>config/inadimplencia.json</code> + <code>modelo_planilha_inadimplencia.xlsx</code></li>
            <li>Os arquivos gerados ficam na pasta <code>uploads/</code></li>
          </ul>
        </div>
      </div>
    </main>
  </div>
</div>

<footer class="text-center text-muted my-4">
  2025 © Desenvolvimento BBZ.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
