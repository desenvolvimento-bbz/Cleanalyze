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

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-md-8">
      <div class="card">
        <div class="card-body">
          <h4 class="m-0">📄 Extrair do PDF → XLSX</h4>
          <p class="card-text text-secondary">Envie um PDF padronizado para gerar a planilha de importação.</p>

          <form action="web/executar_extracao.php" method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-8">
              <input type="file" name="pdf" accept="application/pdf" class="form-control" required>
            </div>
            <div class="col-md-4">
              <select name="tipo" class="form-select" required>
                <option value="ahreas">Ahreas (Unidades)</option>
                <option value="inadimplencia">Inadimplência</option>
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

<footer class="text-center text-muted my-4">2025 &copy; Desenvolvimento BBZ.</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
