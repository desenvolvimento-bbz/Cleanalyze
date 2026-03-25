<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login();
app_log('page.view', ['page'=>basename(__FILE__)]);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Prestação de Contas — Cleanalyze</title>
  <?php include __DIR__ . '/includes/head.php'; ?>
  <style>
    .upload-zone{
      border:2px dashed var(--cinza); border-radius:8px; padding:2rem;
      text-align:center; transition:border-color .2s;
    }
    .upload-zone:hover{ border-color:var(--azul); }
    .upload-zone input[type="file"]{ display:none; }
    .upload-zone label{ cursor:pointer; display:block; }
    .file-name{ font-size:.85rem; color:#666; margin-top:.5rem; }
  </style>
</head>
<body>
<?php $activePage = 'prestacao'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-md-10">
      <div class="card">
        <div class="card-body">
          <h4 class="m-0">📊 Comparar Prestação de Contas</h4>
          <p class="card-text text-secondary">
            Envie dois PDFs de Prestação de Contas (mês anterior e mês atual) para gerar um
            comparativo detalhado com as diferenças encontradas.
          </p>

          <form action="web/executar_prestacao.php" method="post" enctype="multipart/form-data">
            <div class="row g-3">
              <!-- PDF Mês Anterior -->
              <div class="col-md-6">
                <div class="upload-zone" id="zone-anterior">
                  <label for="pdf_anterior">
                    <span style="font-size:2rem;">📄</span><br>
                    <strong>Mês Anterior</strong><br>
                    <small class="text-muted">Clique para selecionar o PDF</small>
                  </label>
                  <input type="file" name="pdf_anterior" id="pdf_anterior" accept="application/pdf" required>
                  <div class="file-name" id="name-anterior"></div>
                </div>
              </div>

              <!-- PDF Mês Atual -->
              <div class="col-md-6">
                <div class="upload-zone" id="zone-atual">
                  <label for="pdf_atual">
                    <span style="font-size:2rem;">📄</span><br>
                    <strong>Mês Atual</strong><br>
                    <small class="text-muted">Clique para selecionar o PDF</small>
                  </label>
                  <input type="file" name="pdf_atual" id="pdf_atual" accept="application/pdf" required>
                  <div class="file-name" id="name-atual"></div>
                </div>
              </div>

              <!-- Nome opcional do XLSX -->
              <div class="col-md-8">
                <input type="text" name="saidaBase" class="form-control"
                       placeholder="Nome do arquivo de saída (opcional)">
              </div>

              <div class="col-md-4 text-end">
                <button class="btn btn-primary btn-lg w-100" type="submit" id="btnSubmit">
                  Gerar Comparativo
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-body">
          <h6>Como funciona?</h6>
          <ol class="small text-secondary mb-0">
            <li>Envie o PDF da <strong>Prestação de Contas do mês anterior</strong> (ex: Fevereiro)</li>
            <li>Envie o PDF da <strong>Prestação de Contas do mês atual</strong> (ex: Março)</li>
            <li>O sistema irá comparar as despesas e gerar um XLSX com:
              <ul>
                <li>Lançamentos detalhados de cada mês</li>
                <li>Resumo financeiro comparativo</li>
                <li>Totais por conta com variações</li>
                <li><strong>Diferenças detalhadas</strong>: contas novas/ausentes, subcategorias novas/removidas</li>
              </ul>
            </li>
          </ol>
        </div>
      </div>

    </div>
  </div>
</div>

<footer class="text-center text-muted my-4">2025 &copy; Desenvolvimento BBZ.</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// File name display
document.getElementById('pdf_anterior').addEventListener('change', function() {
  const name = this.files[0] ? this.files[0].name : '';
  document.getElementById('name-anterior').textContent = name;
  if (name) document.getElementById('zone-anterior').style.borderColor = '#548235';
});
document.getElementById('pdf_atual').addEventListener('change', function() {
  const name = this.files[0] ? this.files[0].name : '';
  document.getElementById('name-atual').textContent = name;
  if (name) document.getElementById('zone-atual').style.borderColor = '#548235';
});

// Loading state on submit
document.querySelector('form').addEventListener('submit', function() {
  const btn = document.getElementById('btnSubmit');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processando...';
});
</script>
</body>
</html>
