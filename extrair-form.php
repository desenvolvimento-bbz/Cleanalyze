<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login();
app_log('page.view', ['page'=>basename(__FILE__)]);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Extrair — Cleanalyze</title>
  <?php include __DIR__ . '/includes/head.php'; ?>
</head>
<body>
<?php $activePage = 'extrair'; include __DIR__ . '/includes/navbar.php'; ?>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingExtrair">
  <div class="loading-logo">Cleanalyze <span>IA</span></div>
  <div class="loading-steps">
    <div class="loading-step" id="ex-upload">
      <div class="l-icon">&#8593;</div>
      <div class="l-text">Enviando PDF<span class="l-dots"></span></div>
    </div>
    <div class="loading-step" id="ex-read">
      <div class="l-icon">&#9783;</div>
      <div class="l-text">Extraindo texto do PDF<span class="l-dots"></span><div class="l-detail" id="ex-read-d"></div></div>
    </div>
    <div class="loading-step" id="ex-parse">
      <div class="l-icon">&#9881;</div>
      <div class="l-text">Identificando registros<span class="l-dots"></span><div class="l-detail" id="ex-parse-d"></div></div>
    </div>
    <div class="loading-step" id="ex-norm">
      <div class="l-icon">&#128270;</div>
      <div class="l-text">Normalizando dados<span class="l-dots"></span><div class="l-detail" id="ex-norm-d"></div></div>
    </div>
    <div class="loading-step" id="ex-xlsx">
      <div class="l-icon">&#9998;</div>
      <div class="l-text">Gerando planilha XLSX<span class="l-dots"></span><div class="l-detail" id="ex-xlsx-d"></div></div>
    </div>
  </div>
  <div class="loading-footer">2025 &copy; Desenvolvimento BBZ.</div>
</div>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-md-8">
      <div class="card">
        <div class="card-body">
          <h4 class="m-0">Extrair do PDF para XLSX</h4>
          <p class="card-text text-secondary">Envie um PDF padronizado para gerar a planilha de importacao.</p>

          <form action="web/executar_extracao.php" method="post" enctype="multipart/form-data" class="row g-3" id="formExtrair">
            <div class="col-md-8">
              <input type="file" name="pdf" id="pdfExtrair" accept="application/pdf" class="form-control" required>
            </div>
            <div class="col-md-4">
              <select name="tipo" id="tipoExtrair" class="form-select" required>
                <option value="">-- Selecione --</option>
                <option value="inadimplencia">Ahreas (Inadimplência)</option>
                <option value="ahreas">Ahreas (Unidades)</option>
                <option value="lello_inadimplencia">Lello (Inadimplência)</option>
                <option value="lello">Lello (Unidades)</option>
              </select>
            </div>
            <div class="col-md-6">
              <input type="text" name="saidaBase" class="form-control" placeholder="Nome do XLSX (opcional)">
            </div>
            <div class="col-md-6 text-end">
              <button class="btn btn-primary" type="submit">Extrair Dados</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<footer class="text-center text-muted my-4" style="font-size:.8rem;">2025 &copy; Desenvolvimento BBZ.</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/loading-overlay.php'; ?>
<script>
(function() {
  var fileInput = document.getElementById('pdfExtrair');
  var tipoSelect = document.getElementById('tipoExtrair');

  var fileName = function() { return fileInput.files[0] ? fileInput.files[0].name : 'PDF'; };
  var tipoName = function() { return tipoSelect.options[tipoSelect.selectedIndex].text; };

  initLoadingOverlay('formExtrair', 'loadingExtrair', [
    { id: 'ex-upload', delay: 0, doneText: 'PDF enviado' },
    { id: 'ex-read', delay: 1000, detailId: 'ex-read-d', detail: 'Lendo ' + fileName(), doneText: 'Texto extraido' },
    { id: 'ex-parse', delay: 2500, detailId: 'ex-parse-d',
      details: [
        { at: 0, text: 'Aplicando plugin ' + tipoName() + '...' },
        { at: 1500, text: 'Extraindo campos e registros...' },
      ],
      doneText: 'Registros identificados'
    },
    { id: 'ex-norm', delay: 5000, detailId: 'ex-norm-d', detail: 'Convertendo datas, valores e enderecos...', doneText: 'Dados normalizados' },
    { id: 'ex-xlsx', delay: 7000, detailId: 'ex-xlsx-d', detail: 'Mapeando colunas e escrevendo XLSX...', doneText: 'Planilha pronta!' },
  ]);
})();
</script>
</body>
</html>
