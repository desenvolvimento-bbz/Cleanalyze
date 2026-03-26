<?php
/**
 * Cleanalyze – extrair.php
 *
 * Página de extração com identidade visual do sistema.
 */
require_once __DIR__ . '/../auth/bootstrap.php';
auth_require_login();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Extração de PDF - Cleanalyze</title>
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
    .form-text{ color:#6c757d; }
  </style>
</head>
<body>

<?php $activePage = 'extrair'; include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">
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
                <option value="ahreas">Ahreas (Unidades)</option>
                <option value="inadimplencia">Inadimplência</option>
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
            <li><strong>Unidades (Ahreas)</strong>: usa <code>config/ahreas.json</code> + <code>modelo_planilha_importacao.xlsx</code></li>
            <li><strong>Inadimplência</strong>: usa <code>config/inadimplencia.json</code> + <code>modelo_planilha_inadimplencia.xlsx</code></li>
            <li>Os arquivos gerados ficam na pasta <code>uploads/</code></li>
          </ul>
        </div>
      </div>
</div>

<footer class="text-center text-muted my-4">
  2025 © Desenvolvimento BBZ.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
