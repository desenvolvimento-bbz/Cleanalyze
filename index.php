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
      background: linear-gradient(135deg, var(--bbz-azul-escuro) 0%, var(--bbz-azul) 100%);
      color:#fff; border-radius:var(--bbz-raio); padding:2rem 2.5rem; margin-bottom:2rem;
    }
    .welcome-section h2{ font-weight:300; margin:0; }
    .welcome-section p{ opacity:.8; margin:.5rem 0 0; }

    .nav-card{
      border:none; border-radius:var(--bbz-raio); transition:transform .15s, box-shadow .15s;
      cursor:pointer; text-decoration:none; color:var(--azul); height:100%;
    }
    .nav-card:hover{
      transform:translateY(-4px);
      box-shadow:0 8px 24px rgba(0,0,0,.12);
    }
    .nav-card .card-body{ padding:1.8rem; }
    .nav-card .icon{ font-size:2.5rem; margin-bottom:.8rem; display:block; }
    .nav-card h5{ font-weight:700; margin-bottom:.4rem; }
    .nav-card p{ color:var(--bbz-cinza-escuro); font-size:.85rem; margin:0; }
    /* Sequencia da paleta BBZ: azul (principal) -> azul medio -> azul claro -> roxo.
       Convites e area administrativa, entao fica no cinza de apoio. */
    .nav-card-extrair{ border-left:5px solid var(--bbz-azul); }
    .nav-card-comparar{ border-left:5px solid var(--bbz-azul-medio); }
    .nav-card-prestacao{ border-left:5px solid var(--bbz-azul-claro); }
    .nav-card-rag{ border-left:5px solid var(--bbz-roxo); }
    .nav-card-convites{ border-left:5px solid var(--bbz-cinza-escuro); }
    .nav-card .badge-novo{
      position:absolute; top:12px; right:12px;
      font-size:.62rem; letter-spacing:.3px;
    }

    .changelog-card{ border-color:var(--bbz-cinza-medio); border-radius:var(--bbz-raio); }
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
      <a href="rag-assistente.php" class="nav-card card nav-card-rag" style="position:relative;">
        <span class="badge rounded-pill bg-success badge-novo">Novo</span>
        <div class="card-body">
          <span class="icon">🤖</span>
          <h5>Assistente IA</h5>
          <p>Envie um documento e converse com ele: pergunte, resuma e tire duvidas com citacao de pagina.</p>
        </div>
      </a>
    </div>
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
      <a href="prestacao_anual.php" class="nav-card card nav-card-prestacao" style="position:relative;">
        <span class="badge rounded-pill bg-success badge-novo">Novo</span>
        <div class="card-body">
          <span class="icon">📊</span>
          <h5>Prestacao de Contas IA</h5>
          <p>Analise de Prestacao de Contas por Inteligencia Artificial — anomalias, padroes e comparativos.</p>
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
