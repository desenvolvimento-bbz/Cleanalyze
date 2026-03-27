<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login();
app_log('page.view', ['page'=>basename(__FILE__)]);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Prestacao de Contas v2 — Cleanalyze</title>
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
          <h4 class="m-0">Comparar Prestacao de Contas <span class="badge bg-info">v2 teste</span></h4>
          <p class="card-text text-secondary">
            Envie dois PDFs de Prestacao de Contas (periodo anterior e mes atual) para gerar um
            comparativo detalhado com as diferencas encontradas.
          </p>

          <form action="web/executar_prestacao_v2.php" method="post" enctype="multipart/form-data">
            <div class="row g-3">
              <!-- PDF Periodo Anterior -->
              <div class="col-md-6">
                <div class="upload-zone" id="zone-anterior">
                  <label for="pdf_anterior">
                    <span style="font-size:2rem;">📄</span><br>
                    <strong>Periodo Anterior</strong><br>
                    <small class="text-muted">Pode ser anual ou mensal</small>
                  </label>
                  <input type="file" name="pdf_anterior" id="pdf_anterior" accept="application/pdf" required>
                  <div class="file-name" id="name-anterior"></div>
                </div>
              </div>

              <!-- PDF Mes Atual -->
              <div class="col-md-6">
                <div class="upload-zone" id="zone-atual">
                  <label for="pdf_atual">
                    <span style="font-size:2rem;">📄</span><br>
                    <strong>Mes Atual</strong><br>
                    <small class="text-muted">Clique para selecionar o PDF</small>
                  </label>
                  <input type="file" name="pdf_atual" id="pdf_atual" accept="application/pdf" required>
                  <div class="file-name" id="name-atual"></div>
                </div>
              </div>

              <!-- Nome opcional do XLSX -->
              <div class="col-md-8">
                <input type="text" name="saidaBase" class="form-control"
                       placeholder="Nome do arquivo de saida (opcional)">
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
          <h6>Novidades da v2</h6>
          <ul class="small text-secondary mb-0">
            <li><strong>Fundo de Reserva</strong>: Alerta automatico se houver debitos no Fundo de Reserva</li>
            <li><strong>Cores corrigidas</strong>: Contas novas em <span style="color:#c62828; font-weight:600;">vermelho</span> (provavel erro) e ausentes em <span style="color:#856404; font-weight:600;">amarelo</span> (atencao)</li>
            <li><strong>Dropdown por conta</strong>: Clique na conta para ver as subcategorias com totais detalhados</li>
            <li><strong>Periodo anterior flexivel</strong>: Aceita PDF anual ou mensal como base de comparacao</li>
          </ul>
        </div>
      </div>

    </div>
  </div>
</div>

<footer class="text-center text-muted my-4">2025 &copy; Desenvolvimento BBZ.</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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

document.querySelector('form').addEventListener('submit', function() {
  const btn = document.getElementById('btnSubmit');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processando...';
});
</script>
</body>
</html>
