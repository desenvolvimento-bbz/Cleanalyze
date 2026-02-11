<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login(); // exige login
app_log('page.view', ['page'=>basename(__FILE__)]);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Cleanalyze BBZ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --azul:#04193b; --cinza:#b8b8c4; --cinzaClaro:#efeff4; }
    body{ background:var(--cinzaClaro); color:var(--azul); }
    .navbar{ background:var(--azul); }
    .navbar .navbar-brand, .navbar a{ color:#fff !important; }
    .btn-primary{ background:var(--azul); border-color:var(--azul); }
    .card{ border-color:var(--cinza); }
  </style>
</head>
<body>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&display=swap" rel="stylesheet">
<nav class="navbar navbar-expand-lg" style="font-family: 'Manrope', sans-serif;">
  <div class="container">
    <a class="navbar-brand" href="index.php">Cleanalyze BBZ</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
      aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? ' active' : '' ?>" href="index.php">
            Extrair
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= basename($_SERVER['PHP_SELF']) === 'comparar.php' ? ' active' : '' ?>" href="comparar.php">
            Comparar
          </a>
        </li>
        <?php if (auth_is_admin()): ?>
        <li class="nav-item">
          <a class="nav-link<?= basename($_SERVER['PHP_SELF']) === 'invite.php' ? ' active' : '' ?>" href="auth/invite.php">
            Gerenciar Convites
          </a>
        </li>
        <?php endif; ?>
          <li class="nav-item">
            <span class="nav-link disabled" style="opacity:.85; cursor:default;">
              <?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>
            </span>
          </li>

          <!-- Sair -->
          <li class="nav-item">
            <a class="nav-link<?= basename($_SERVER['PHP_SELF']) === 'logout.php' ? ' active' : '' ?>" href="auth/logout.php">
              Sair
            </a>
          </li>
      </ul>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="row g-4">
    <!-- Sidebar -->
    <aside class="col-md-3" id="sidebarCol">
      <h4 class="m-0">Menu</h4>
      <div class="list-group" style="font-family: 'Manrope', sans-serif;">
        <a href="index.php"
           class="list-group-item list-group-item-action<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? ' active' : '' ?>">
          📄 Extrair (PDF → XLSX)
        </a>
        <a href="comparar.php"
           class="list-group-item list-group-item-action<?= basename($_SERVER['PHP_SELF']) === 'comparar.php' ? ' active' : '' ?>">
          🔍 Comparar (A × B)
        </a>
        <?php if (auth_is_admin()): ?>
        <a href="auth/invite.php"
           class="list-group-item list-group-item-action<?= basename($_SERVER['PHP_SELF']) === 'invite.php' ? ' active' : '' ?>">
          🛠️ Gerenciar Convites
        </a>
        <?php endif; ?>
      </div>
    </aside>

    <!-- Main -->
    <main class="col-md-9" id="contentCol">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary">⮜ Ocultar menu</button>
      </div>

      <div class="card h-100">
        <div class="card-body">
          <h4 class="m-0">📄 Extrair do PDF → XLSX</h4>
          <p class="card-text text-secondary">Envie um PDF padronizado para gerar a planilha de importação.</p>

<form action="/Cleanalyze/web/executar_extracao.php" method="post" enctype="multipart/form-data" class="row g-3">

  <!-- arquivo PDF (o name PRECISA ser "pdf") -->
  <div class="col-md-8">
    <input type="file" name="pdf" accept="application/pdf" class="form-control" required>
  </div>

  <!-- dropdown de tipo (o name PRECISA ser "tipo") -->
  <div class="col-md-4">
    <select name="tipo" class="form-select" required>
      <option value="ahreas">Ahreas (Unidades)</option>
      <option value="inadimplencia">Inadimplência</option>
    </select>
  </div>

  <!-- nome opcional do XLSX -->
  <div class="col-md-6">
    <input type="text" name="saidaBase" class="form-control" placeholder="Nome do XLSX (opcional)">
  </div>

  <div class="col-md-6 text-end">
    <button class="btn btn-primary" type="submit">Extrair Dados</button>
  </div>
</form>
        </div>
      </div>
    </main>
  </div>
</div>

<script>
(function() {
  const btn  = document.getElementById('toggleSidebar');
  const side = document.getElementById('sidebarCol');
  const main = document.getElementById('contentCol');

  if (!btn || !side || !main) return;

  let hidden = false;
  btn.addEventListener('click', function () {
    hidden = !hidden;

    if (hidden) {
      side.classList.add('d-none');
      main.classList.remove('col-md-9');
      main.classList.add('col-md-12');
      btn.textContent = '⮞ Mostrar menu';
    } else {
      side.classList.remove('d-none');
      main.classList.remove('col-md-12');
      main.classList.add('col-md-9');
      btn.textContent = '⮜ Ocultar menu';
    }
  });
})();
</script>

  <footer class="text-center text-muted my-4">
  2025 © Desenvolvimento BBZ.
</footer>
</body>

</html>
