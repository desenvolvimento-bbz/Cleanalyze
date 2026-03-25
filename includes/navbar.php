<?php
/**
 * Navbar padrão do Cleanalyze.
 * Inclua com: <?php $activePage = 'index'; include __DIR__ . '/includes/navbar.php'; ?>
 * Funciona de qualquer subdiretório (auth/, web/, etc.)
 */
$activePage = $activePage ?? '';
$_navEmail = auth_user_email() ?? '';

// Calcula base URL relativo ao diretório do includes/
// __DIR__ aqui é /includes, então o pai é a raiz do app
$_navBase = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$_docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$_navBaseUrl = '/' . ltrim(str_replace($_docRoot, '', $_navBase), '/');
$_navBaseUrl = rtrim($_navBaseUrl, '/');
// Se estiver na raiz (Docker), $_navBaseUrl será vazio -> usar "/"
if ($_navBaseUrl === '') $_navBaseUrl = '';
?>
<nav class="navbar navbar-expand-lg" style="font-family:'Manrope',sans-serif;">
  <div class="container">
    <a class="navbar-brand" href="<?= $_navBaseUrl ?>/index.php" style="font-weight:700; font-size:1.3rem;">Cleanalyze</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link<?= $activePage === 'index' ? ' active' : '' ?>" href="<?= $_navBaseUrl ?>/index.php">Início</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= $activePage === 'extrair' ? ' active' : '' ?>" href="<?= $_navBaseUrl ?>/extrair-form.php">Extrair</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= $activePage === 'comparar' ? ' active' : '' ?>" href="<?= $_navBaseUrl ?>/comparar.php">Comparar</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= $activePage === 'prestacao' ? ' active' : '' ?>" href="<?= $_navBaseUrl ?>/prestacao.php">Prestação de Contas</a>
        </li>
        <?php if (function_exists('auth_is_admin') && auth_is_admin()): ?>
        <li class="nav-item">
          <a class="nav-link<?= $activePage === 'convites' ? ' active' : '' ?>" href="<?= $_navBaseUrl ?>/auth/invite.php">Usuários</a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <span class="nav-link disabled" style="opacity:.85; cursor:default;">
            <?= htmlspecialchars($_navEmail) ?>
          </span>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?= $_navBaseUrl ?>/auth/logout.php">Sair</a>
        </li>
      </ul>
    </div>
  </div>
</nav>
