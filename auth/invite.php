<?php
/**
 * auth/invite.php — Gerenciamento de Usuários
 *
 * Permite ao admin ver todos os usuários, alterar roles (admin/user),
 * redefinir senhas e remover usuários.
 */
require_once __DIR__ . '/bootstrap.php';
auth_require_admin();

$msg = $msgType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetEmail = trim($_POST['email'] ?? '');
    $currentAdmin = auth_user_email();
    $users = users_load();

    if ($action === 'change_role' && $targetEmail) {
        $newRole = $_POST['role'] ?? 'user';
        if (!in_array($newRole, ['admin', 'user'], true)) $newRole = 'user';

        if ($targetEmail === $currentAdmin && $newRole !== 'admin') {
            $msg = 'Você não pode remover seu próprio acesso de administrador.';
            $msgType = 'danger';
        } elseif (isset($users[$targetEmail])) {
            $users[$targetEmail]['role'] = $newRole;
            users_save($users);
            app_log('user.role_change', ['by' => $currentAdmin, 'email' => $targetEmail, 'new_role' => $newRole]);
            $msg = "Papel de {$targetEmail} alterado para {$newRole}.";
            $msgType = 'success';
        }
    }

    if ($action === 'set_password' && $targetEmail) {
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) < 6) {
            $msg = 'A senha deve ter pelo menos 6 caracteres.';
            $msgType = 'danger';
        } elseif (isset($users[$targetEmail])) {
            $users[$targetEmail]['hash'] = password_hash($newPass, PASSWORD_DEFAULT);
            users_save($users);
            app_log('user.password_reset', ['by' => $currentAdmin, 'email' => $targetEmail]);
            $msg = "Senha de {$targetEmail} redefinida com sucesso.";
            $msgType = 'success';
        }
    }

    if ($action === 'remove' && $targetEmail) {
        if ($targetEmail === $currentAdmin) {
            $msg = 'Você não pode remover sua própria conta.';
            $msgType = 'danger';
        } elseif (isset($users[$targetEmail])) {
            unset($users[$targetEmail]);
            users_save($users);
            app_log('user.remove', ['by' => $currentAdmin, 'email' => $targetEmail]);
            $msg = "Usuário {$targetEmail} removido.";
            $msgType = 'warning';
        }
    }
}

$users = users_load();
$currentEmail = auth_user_email();

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Usuários — Cleanalyze</title>
  <?php include __DIR__ . '/../includes/head.php'; ?>
  <style>
    .role-badge-admin{ background:#7B2D8E; color:#fff; padding:3px 10px; border-radius:4px; font-size:.8rem; font-weight:600; }
    .role-badge-user{ background:#4472C4; color:#fff; padding:3px 10px; border-radius:4px; font-size:.8rem; font-weight:600; }
    .via-google{ color:#34A853; font-size:.8rem; }
    .via-password{ color:#888; font-size:.8rem; }
    .pass-form{ display:none; margin-top:6px; }
    .pass-form.show{ display:flex; }
    .user-row td{ vertical-align:middle; }
  </style>
</head>
<body>
<?php $activePage = 'convites'; include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-md-10">

      <h4 class="mb-3">Gerenciar Usuários</h4>
      <p class="text-secondary">
        Usuários com login via Google são criados automaticamente no primeiro acesso.
        Aqui você pode definir administradores, redefinir senhas e remover acessos.
      </p>

      <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> py-2"><?= h($msg) ?></div>
      <?php endif; ?>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr style="background:var(--azul); color:#fff;">
                  <th>E-mail</th>
                  <th>Papel</th>
                  <th>Origem</th>
                  <th>Criado em</th>
                  <th>Senha</th>
                  <th style="width:200px;">Ações</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($users as $email => $data):
                  $role = $data['role'] ?? 'user';
                  $hasPassword = !empty($data['hash']);
                  $via = ($data['created_via'] ?? '') === 'google_oauth' ? 'google' : ($hasPassword ? 'password' : 'unknown');
                  $createdAt = $data['created_at'] ?? null;
                  $isCurrentUser = ($email === $currentEmail);
                  $rowId = 'row-' . md5($email);
              ?>
                <tr class="user-row<?= $isCurrentUser ? ' table-active' : '' ?>">
                  <td>
                    <strong><?= h($email) ?></strong>
                    <?= $isCurrentUser ? '<small class="text-muted">(você)</small>' : '' ?>
                  </td>
                  <td>
                    <span class="role-badge-<?= $role ?>"><?= $role === 'admin' ? 'Admin' : 'Usuário' ?></span>
                  </td>
                  <td>
                    <?php if ($via === 'google'): ?>
                      <span class="via-google">
                        <svg width="14" height="14" viewBox="0 0 48 48" style="vertical-align:middle;">
                          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        </svg>
                        Google
                      </span>
                    <?php else: ?>
                      <span class="via-password">🔑 Senha</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($createdAt):
                        echo h(is_numeric($createdAt) ? date('d/m/Y H:i', $createdAt) : substr($createdAt, 0, 16));
                    else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($hasPassword): ?>
                      <span class="text-success" title="Senha definida">Definida</span>
                    <?php else: ?>
                      <span class="text-muted" title="Sem senha (login apenas por Google)">Não definida</span>
                    <?php endif; ?>
                    <br>
                    <a href="#" class="small" onclick="togglePassForm('<?= $rowId ?>'); return false;">
                      <?= $hasPassword ? 'Redefinir' : 'Criar senha' ?>
                    </a>
                    <div class="pass-form" id="<?= $rowId ?>">
                      <form method="post" class="d-flex gap-1 align-items-center">
                        <input type="hidden" name="action" value="set_password">
                        <input type="hidden" name="email" value="<?= h($email) ?>">
                        <input type="password" name="new_password" class="form-control form-control-sm" placeholder="Nova senha (min. 6)" style="width:160px;" required minlength="6">
                        <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                      </form>
                    </div>
                  </td>
                  <td>
                    <?php if (!$isCurrentUser): ?>
                      <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="change_role">
                        <input type="hidden" name="email" value="<?= h($email) ?>">
                        <?php if ($role === 'user'): ?>
                          <input type="hidden" name="role" value="admin">
                          <button type="submit" class="btn btn-sm btn-outline-primary">Tornar Admin</button>
                        <?php else: ?>
                          <input type="hidden" name="role" value="user">
                          <button type="submit" class="btn btn-sm btn-outline-secondary">Remover Admin</button>
                        <?php endif; ?>
                      </form>
                      <form method="post" style="display:inline;" onsubmit="return confirm('Remover <?= h($email) ?>?')">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="email" value="<?= h($email) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Remover</button>
                      </form>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-body small text-secondary">
          <strong>Como funciona?</strong>
          <ul class="mb-0 mt-1">
            <li>Qualquer pessoa com e-mail <strong>@bbz.com.br</strong> pode entrar via Google.</li>
            <li>No primeiro login, o usuário é criado automaticamente com papel <strong>Usuário</strong>.</li>
            <li>Apenas <strong>Administradores</strong> podem acessar esta página e alterar papéis.</li>
            <li>Você pode criar ou redefinir senhas para permitir login com e-mail/senha além do Google.</li>
          </ul>
        </div>
      </div>

    </div>
  </div>
</div>

<footer class="text-center text-muted my-4">2025 &copy; Desenvolvimento BBZ.</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassForm(id) {
  const el = document.getElementById(id);
  if (el) el.classList.toggle('show');
}
</script>
</body>
</html>
