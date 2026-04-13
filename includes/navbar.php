<?php
/**
 * Navbar padrão do Cleanalyze.
 * Inclua com: <?php $activePage = 'index'; include __DIR__ . '/includes/navbar.php'; ?>
 * Funciona de qualquer subdiretório (auth/, web/, etc.)
 */
$activePage = $activePage ?? '';
$_navEmail = auth_user_email() ?? '';

// Versão atual lida do changelog
$_navVersion = '';
$_changelogPath = dirname(__DIR__) . '/changelog.json';
if (file_exists($_changelogPath)) {
    $_cl = json_decode(file_get_contents($_changelogPath), true);
    if ($_cl && isset($_cl[0]['versao'])) $_navVersion = $_cl[0]['versao'];
}

// Calcula base URL relativo ao diretório do includes/
// __DIR__ aqui é /includes, então o pai é a raiz do app
$_navBase = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$_docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$_navBaseUrl = '/' . ltrim(str_replace($_docRoot, '', $_navBase), '/');
$_navBaseUrl = rtrim($_navBaseUrl, '/');
// Se estiver na raiz (Docker), $_navBaseUrl será vazio -> usar "/"
if ($_navBaseUrl === '') $_navBaseUrl = '';

// Estado ativo dos dropdowns (pai destacado quando filho corresponde a pagina atual)
$_cadastroActive  = in_array($activePage, ['extrair', 'comparar'], true);
$_prestacaoActive = $activePage === 'prestacao';
$_adminActive     = in_array($activePage, ['convites', 'admin-docs-bbz'], true);
?>
<style>
  /* Dropdowns do navbar: bate com a paleta Cleanalyze (--azul #04193b) */
  .navbar .dropdown-menu {
    border: 1px solid rgba(4,25,59,.15);
    box-shadow: 0 6px 20px rgba(0,0,0,.08);
    border-radius: 8px;
    padding: 6px;
    min-width: 220px;
    background-color: #fff;
  }
  /* Forca cor escura nos itens do dropdown — algumas paginas definem
     `.navbar a { color:#fff !important }` que vazaria pra ca e deixaria
     o texto branco sobre fundo branco. Sobrescrevemos com especificidade
     maior + !important. */
  .navbar .dropdown-menu .dropdown-item,
  .navbar .dropdown-menu .dropdown-item:link,
  .navbar .dropdown-menu .dropdown-item:visited {
    color: #04193b !important;
    background-color: transparent;
    border-radius: 6px;
    padding: 8px 12px;
    font-weight: 500;
  }
  .navbar .dropdown-menu .dropdown-item:hover,
  .navbar .dropdown-menu .dropdown-item:focus {
    color: #04193b !important;
    background-color: #eef1f8 !important;
  }
  .navbar .dropdown-menu .dropdown-item.active,
  .navbar .dropdown-menu .dropdown-item:active {
    color: #ffffff !important;
    background-color: #04193b !important;
  }
</style>
<nav class="navbar navbar-expand-lg" style="font-family:'Manrope',sans-serif;">
  <div class="container">
    <a class="navbar-brand" href="<?= $_navBaseUrl ?>/index.php" style="font-weight:700; font-size:1.3rem;">Cleanalyze<?php if ($_navVersion): ?><span style="font-size:.6rem; font-weight:400; color:rgba(255,255,255,.45); margin-left:6px; vertical-align:middle;">v<?= htmlspecialchars($_navVersion) ?></span><?php endif; ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">

        <!-- Assistente IA (destaque) -->
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center gap-2<?= $activePage === 'rag' ? ' active' : '' ?>"
             href="<?= $_navBaseUrl ?>/rag-assistente.php">
            Assistente IA
            <span class="badge rounded-pill bg-success" style="font-size:.6rem; padding:.25em .5em;">Novo</span>
          </a>
        </li>

        <!-- Cadastro -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $_cadastroActive ? ' active' : '' ?>"
             href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Cadastro
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <a class="dropdown-item<?= $activePage === 'extrair' ? ' active' : '' ?>"
                 href="<?= $_navBaseUrl ?>/extrair-form.php">Extrair</a>
            </li>
            <li>
              <a class="dropdown-item<?= $activePage === 'comparar' ? ' active' : '' ?>"
                 href="<?= $_navBaseUrl ?>/comparar.php">Comparar</a>
            </li>
          </ul>
        </li>

        <!-- Prestação de Contas -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $_prestacaoActive ? ' active' : '' ?>"
             href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Prestação de Contas
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <a class="dropdown-item d-flex align-items-center gap-2<?= $activePage === 'prestacao' ? ' active' : '' ?>"
                 href="<?= $_navBaseUrl ?>/prestacao_anual.php">
                Prestação de Contas IA
                <span class="badge rounded-pill bg-success" style="font-size:.6rem; padding:.25em .5em;">Novo</span>
              </a>
            </li>
          </ul>
        </li>

        <?php if (function_exists('auth_is_admin') && auth_is_admin()): ?>
        <!-- Admin (apenas administradores) -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $_adminActive ? ' active' : '' ?>"
             href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Admin
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <a class="dropdown-item<?= $activePage === 'convites' ? ' active' : '' ?>"
                 href="<?= $_navBaseUrl ?>/auth/invite.php">Usuários</a>
            </li>
            <li>
              <a class="dropdown-item d-flex align-items-center gap-2<?= $activePage === 'admin-docs-bbz' ? ' active' : '' ?>"
                 href="<?= $_navBaseUrl ?>/rag-admin-docs.php">
                Documentos BBZ
                <span class="badge rounded-pill bg-success" style="font-size:.6rem; padding:.25em .5em;">Novo</span>
              </a>
            </li>
          </ul>
        </li>
        <?php endif; ?>

        <!-- Email + logout -->
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
