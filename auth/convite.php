<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/auth/bootstrap.php';

$base = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');    // ex: /cleanalyze-app
$err = $ok = null;

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$token = preg_replace('/[^a-f0-9]/i', '', $token); // sanitiza

$invites = invites_load();
$invite  = $invites[$token] ?? null;
$now     = time();

if (!$invite) {
    $err = 'Convite inválido.';
} else {
    $expired = ($invite['expires_at'] ?? 0) < $now;
    $used    = !empty($invite['used']);
    if ($expired) $err = 'Convite expirado.';
    if ($used)    $err = 'Convite já utilizado.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (strlen($pass) < 6) {
        $err = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($pass !== $pass2) {
        $err = 'As senhas não conferem.';
    } else {
        $users = users_load();
        $email = $invite['email'];
        $role  = $invite['role'] ?? 'user';

        $users[$email] = [
            'hash' => password_hash($pass, PASSWORD_DEFAULT),
            'role' => $role,
        ];
        users_save($users);

        // marca convite como usado
        $invites[$token]['used'] = true;
        $invites[$token]['used_at'] = $now;
        invites_save($invites);

        app_log('invite.accept', ['email'=>$email, 'role'=>$role]);

        // autentica e manda para index
        auth_login($email);
        header('Location: ' . $base . '/index.php');
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Convite — Cleanalyze</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{ --azul:#04193b; --cinzaClaro:#efeff4; }
body{ background:var(--cinzaClaro); color:var(--azul); font-family:'Manrope',sans-serif; }
.navbar{ background:#04193b; }
.navbar .navbar-brand, .navbar a{ color:#fff !important; }
.card{ max-width:520px; margin:7vh auto; border:1px solid #b8b8c4; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container">
    <a class="navbar-brand" href="index.php">Cleanalyze BBZ</a>
  </div>
</nav>

<div class="card shadow-sm">
  <div class="card-body">
    <h4 class="mb-3">Ativar acesso</h4>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <?php if (!$err): ?>
      <p class="mb-3">Convite para: <strong><?= htmlspecialchars($invite['email']) ?></strong></p>
      <form method="post" action="convite.php?token=<?= urlencode($token) ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="mb-3">
          <label class="form-label">Crie sua senha</label>
          <input type="password" name="password" class="form-control" required minlength="6" placeholder="Mínimo 6 caracteres">
        </div>
        <div class="mb-3">
          <label class="form-label">Repita a senha</label>
          <input type="password" name="password2" class="form-control" required minlength="6">
        </div>
        <button class="btn btn-primary" type="submit">Ativar</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<footer class="text-center text-muted my-4">
  2025 © BBZ Administração de Condomínio Ltda. | Todos os direitos reservados.
</footer>

</body>
</html>
