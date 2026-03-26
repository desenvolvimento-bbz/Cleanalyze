<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login();
app_log('page.view', ['page'=>basename(__FILE__)]);
$userEmail = auth_user_email() ?? 'Usuário';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Cleanalyze</title>
  <?php include __DIR__ . '/includes/head.php'; ?>
  <style>
    .welcome-section{
      background: linear-gradient(135deg, #04193b 0%, #0a3d7a 100%);
      color:#fff; border-radius:12px; padding:2rem 2.5rem; margin-bottom:2rem;
    }
    .welcome-section h2{ font-weight:700; margin:0; }
    .welcome-section p{ opacity:.8; margin:.5rem 0 0; }

    .nav-card{
      border:none; border-radius:12px; transition:transform .15s, box-shadow .15s;
      cursor:pointer; text-decoration:none; color:var(--azul); height:100%;
    }
    .nav-card:hover{
      transform:translateY(-4px);
      box-shadow:0 8px 24px rgba(0,0,0,.12);
    }
    .nav-card .card-body{ padding:1.8rem; }
    .nav-card .icon{ font-size:2.5rem; margin-bottom:.8rem; display:block; }
    .nav-card h5{ font-weight:700; margin-bottom:.4rem; }
    .nav-card p{ color:#666; font-size:.85rem; margin:0; }
    .nav-card-extrair{ border-left:5px solid #4472C4; }
    .nav-card-comparar{ border-left:5px solid #ED7D31; }
    .nav-card-prestacao{ border-left:5px solid #548235; }
    .nav-card-convites{ border-left:5px solid #7B2D8E; }

    .changelog-card{ border-color:var(--cinza); border-radius:10px; }
    .changelog-card .card-header{ background:#fff; border-bottom:1px solid var(--cinza); }
  </style>
</head>
<body>
<?php $activePage = 'index'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4">

  <!-- Welcome -->
  <div class="welcome-section">
    <h2>Bem-vindo, <?= htmlspecialchars($userEmail) ?></h2>
    <p>Selecione uma ferramenta abaixo para começar.</p>
  </div>

  <!-- Navigation Cards -->
  <div class="row g-4 mb-4">
    <div class="col-md-4">
      <a href="extrair-form.php" class="nav-card card nav-card-extrair">
        <div class="card-body">
          <span class="icon">📄</span>
          <h5>Extrair</h5>
          <p>Extraia dados de um PDF padronizado e gere a planilha XLSX de importação.</p>
        </div>
      </a>
    </div>
    <div class="col-md-4">
      <a href="comparar.php" class="nav-card card nav-card-comparar">
        <div class="card-body">
          <span class="icon">🔍</span>
          <h5>Comparar Planilhas</h5>
          <p>Compare duas planilhas XLSX lado a lado e identifique diferenças célula a célula.</p>
        </div>
      </a>
    </div>
    <div class="col-md-4">
      <a href="prestacao.php" class="nav-card card nav-card-prestacao" style="position:relative;">
        <span class="badge bg-success" style="position:absolute; top:10px; right:10px; font-size:.65rem; font-weight:600;">New!</span>
        <div class="card-body">
          <span class="icon">📊</span>
          <h5>Prestação de Contas</h5>
          <p>Compare dois PDFs de Prestação de Contas e veja as diferenças entre meses.</p>
        </div>
      </a>
    </div>
    <?php if (auth_is_admin()): ?>
    <div class="col-md-4">
      <a href="auth/invite.php" class="nav-card card nav-card-convites">
        <div class="card-body">
          <span class="icon">🛠️</span>
          <h5>Gerenciar Usuários</h5>
          <p>Gerencie acessos e defina administradores do sistema.</p>
        </div>
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Changelog -->
  <?php
  $changelogFile = __DIR__ . '/changelog.json';
  if (file_exists($changelogFile)):
      $changelog = json_decode(file_get_contents($changelogFile), true);
      if ($changelog):
          usort($changelog, function($a, $b) {
              return version_compare($b['versao'], $a['versao']);
          });
          $ultimaVersao = $changelog[0]['versao'] ?? '';
  ?>
  <div class="card changelog-card">
    <div class="card-header d-flex justify-content-between align-items-center"
         style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#changelogBody" aria-expanded="false">
      <span>
        <strong>Novidades</strong>
        <span class="badge bg-primary ms-2">v<?= htmlspecialchars($ultimaVersao) ?></span>
      </span>
      <small class="text-muted">clique para expandir</small>
    </div>
    <div class="collapse" id="changelogBody">
      <div class="card-body" style="max-height:400px; overflow-y:auto;">
        <?php foreach ($changelog as $release): ?>
        <div class="mb-3">
          <h6 class="mb-1">
            v<?= htmlspecialchars($release['versao']) ?>
            <small class="text-muted ms-2"><?= htmlspecialchars($release['data']) ?></small>
          </h6>
          <?php if (!empty($release['titulo'])): ?>
          <small class="text-muted d-block mb-1"><?= htmlspecialchars($release['titulo']) ?></small>
          <?php endif; ?>
          <ul class="mb-0 small">
            <?php foreach ($release['itens'] as $item): ?>
            <li><?= htmlspecialchars($item) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php
      endif;
  endif;
  ?>

</div>

<footer class="text-center text-muted my-4">2025 &copy; Desenvolvimento BBZ.</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
