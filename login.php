<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/auth/bootstrap.php';

/**
 * Normaliza o destino de redirecionamento para ficar SEMPRE dentro do projeto atual,
 * evitando cair na raiz do Apache (xampp/htdocs) e prevenindo open redirect.
 */
function build_redirect(string $requested, string $baseUrl): string {
    $default = $baseUrl . '/index.php';

    $requested = trim($requested);
    if ($requested === '' || $requested === '/') {
        return $default;
    }
    // bloqueia URLs absolutas externas
    if (preg_match('~^(?:https?:)?//~i', $requested)) {
        return $default;
    }
    // Se já começar com o baseUrl (ex: /cleanalyze-app/...), usa direto
    if (strpos($requested, $baseUrl . '/') === 0 || $requested === $baseUrl) {
        return $requested;
    }
    // Se começar com "/" (ex: /comparar.php), prefixa com baseUrl
    if ($requested[0] === '/') {
        return rtrim($baseUrl, '/') . $requested;
    }
    // Caminho relativo (ex: comparar.php)
    return rtrim($baseUrl, '/') . '/' . ltrim($requested, '/');
}

$baseUrl = rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); // ex: /cleanalyze-app

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    $reqRedir = (string)($_POST['redirect'] ?? '');
    $redir = build_redirect($reqRedir, $baseUrl);

    // validações básicas
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'E-mail inválido.';
    } elseif (!isset($USERS[$email])) {
        $err = 'Usuário não encontrado.';
    } elseif (!password_verify($pass, $USERS[$email])) {
        $err = 'Senha incorreta.';
    } else {
        auth_login($email);
        app_log('session.login', ['email'=>$email]);
        header('Location: ' . $redir);
        exit;
    }
}

// valor que vai no campo hidden "redirect" (sanitizado)
$redirectGet = (string)($_GET['redirect'] ?? ($baseUrl . '/index.php'));
$hiddenRedirect = build_redirect($redirectGet, $baseUrl);

$timeout  = isset($_GET['timeout']);
$bye      = isset($_GET['bye']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Login — Cleanalyze</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{ --azul:#04193b; --cinzaClaro:#efeff4; }
body{ background:var(--cinzaClaro); color:var(--azul); }
.card{ max-width:420px; margin:7vh auto; }
</style>
</head>
<body>
<div class="card shadow-sm">
  <div class="card-body">
    <h4 class="mb-3">Acessar</h4>

    <?php if ($timeout): ?>
      <div class="alert alert-warning">Sessão expirada por inatividade.</div>
    <?php endif; ?>
    <?php if ($bye): ?>
      <div class="alert alert-info">Você saiu da sua conta.</div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" novalidate>
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($hiddenRedirect) ?>">
      <div class="mb-3">
        <label class="form-label">E-mail</label>
        <input required type="email" name="email" class="form-control" placeholder="E-mail">
      </div>
      <div class="mb-3">
        <label class="form-label">Senha</label>
        <input required type="password" name="password" class="form-control" placeholder="Senha">
      </div>
      <button class="btn btn-primary" type="submit">Entrar</button>
    </form>
  </div>
</div>

<footer class="text-center text-muted my-4">
  2025 © BBZ Administração de Condomínio Ltda. | Todos os direitos reservados.
</footer>
</body>
</html>
