<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

auth_require_admin();

$err = $ok = null;
$baseUrl = rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); // ex: /cleanalyze-app/auth
$siteBase = rtrim(dirname($baseUrl), '/\\');            // ex: /cleanalyze-app

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $role  = $_POST['role'] ?? 'user';
    $days  = max(1, min(14, (int)($_POST['expires_days'] ?? 2))); // expira em até 14 dias

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'E-mail inválido.';
    } else {
        $invites = invites_load();
        $token = bin2hex(random_bytes(16));
        $invites[$token] = [
            'email' => $email,
            'role'  => in_array($role, ['admin','user'], true) ? $role : 'user',
            'created_at' => time(),
            'expires_at' => time() + ($days * 86400),
            'used' => false,
            'used_at' => null,
        ];
        invites_save($invites);
        app_log('invite.create', ['by'=>auth_user_email(), 'email'=>$email, 'role'=>$role]);

        $ok = 'Convite criado com sucesso.';
        // Exibe abaixo a URL de convite
    }
}

$invites = invites_load();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Convites — Cleanalyze</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{ --azul:#04193b; --cinzaClaro:#efeff4; }
body{ background:var(--cinzaClaro); color:var(--azul); font-family:'Manrope',sans-serif; }
.navbar{ background:#04193b; }
.navbar .navbar-brand, .navbar a{ color:#fff !important; }
.card{ border:1px solid #b8b8c4; }
.code{ font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container">
    <a class="navbar-brand" href="<?= htmlspecialchars($siteBase) ?>/index.php">
      Cleanalyze BBZ
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Alternar">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($siteBase) ?>/index.php">Extrair</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($siteBase) ?>/comparar.php">Comparar</a></li>
        <li class="nav-item"><a class="nav-link active" href="<?= htmlspecialchars($siteBase) ?>/auth/invite.php">Gerencias Convites</a></li>
        <li class="nav-item">
          <span class="nav-link disabled" style="opacity:.85; cursor:default;">
          <?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>
          </span>
        </li>

        <!-- Sair -->
        <li class="nav-item">
          <a class="nav-link<?= basename($_SERVER['PHP_SELF']) === 'logout.php' ? ' active' : '' ?>" href="logout.php">
          Sair
          </a>
        </li>
      </ul>
    </div>
  </div>
</nav>

<div class="container py-4">
  <h1 class="mb-3">Convites</h1>

  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <form method="post" class="row g-3">
        <div class="col-md-5">
          <label class="form-label">E-mail</label>
          <input type="email" name="email" class="form-control" placeholder="nome@bbz.com.br" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Papel</label>
          <select name="role" class="form-select">
            <option value="user">Usuário</option>
            <option value="admin">Administrador</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Expira (dias)</label>
          <input type="number" name="expires_days" class="form-control" value="2" min="1" max="14">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit">Gerar convite</button>
        </div>
      </form>
    </div>
  </div>

  <h5 class="mb-2">Convites pendentes</h5>
  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>E-mail</th>
          <th>Papel</th>
          <th>Criado</th>
          <th>Expira</th>
          <th>Status</th>
          <th>Link</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $now = time();
        foreach ($invites as $token => $iv):
            $expired = ($iv['expires_at'] ?? 0) < $now;
            $used    = !empty($iv['used']);
            $status  = $used ? 'Usado' : ($expired ? 'Expirado' : 'Ativo');
            $inviteUrl = rtrim(dirname($siteBase), '/'); // sobe mais um? se app está na raiz use $siteBase
            $inviteUrl = $siteBase . '/convite.php?token=' . urlencode($token);
        ?>
        <tr>
          <td><?= htmlspecialchars($iv['email'] ?? '') ?></td>
          <td><?= htmlspecialchars($iv['role'] ?? 'user') ?></td>
          <td><?= date('d/m/Y H:i', $iv['created_at'] ?? $now) ?></td>
          <td><?= date('d/m/Y H:i', $iv['expires_at'] ?? $now) ?></td>
          <td><?= htmlspecialchars($status) ?></td>
          <td>
            <?php if (!$used && !$expired): ?>
              <div class="input-group input-group-sm">
                <input type="text" class="form-control code" readonly value="<?= htmlspecialchars($inviteUrl) ?>">
                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($inviteUrl, ENT_QUOTES) ?>')">Copiar</button>
              </div>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<footer class="text-center text-muted my-4">
  2025 © Desenvolvimento BBZ.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
