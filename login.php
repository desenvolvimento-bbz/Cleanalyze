<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/config/oauth.php';

function build_redirect(string $requested, string $baseUrl): string {
    $default = $baseUrl . '/index.php';
    $requested = trim($requested);
    if ($requested === '' || $requested === '/') return $default;
    if (preg_match('~^(?:https?:)?//~i', $requested)) return $default;
    if (strpos($requested, $baseUrl . '/') === 0 || $requested === $baseUrl) return $requested;
    if ($requested[0] === '/') return rtrim($baseUrl, '/') . $requested;
    return rtrim($baseUrl, '/') . '/' . ltrim($requested, '/');
}

$baseUrl = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$googleClientId = GOOGLE_CLIENT_ID;
$googleEnabled = $googleClientId && $googleClientId !== 'SEU_CLIENT_ID_AQUI.apps.googleusercontent.com';

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    $reqRedir = (string)($_POST['redirect'] ?? '');
    $redir = build_redirect($reqRedir, $baseUrl);

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
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
<?php if ($googleEnabled): ?>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<?php endif; ?>
<style>
:root{ --azul:#04193b; --cinzaClaro:#efeff4; }
body{ background:var(--cinzaClaro); color:var(--azul); font-family:'Manrope',sans-serif; }
.login-card{ max-width:440px; margin:8vh auto; border:none; border-radius:12px; }
.login-card .card-body{ padding:2.5rem; }
.brand-title{ font-size:1.8rem; font-weight:700; color:var(--azul); text-align:center; margin-bottom:.3rem; }
.brand-sub{ text-align:center; color:#888; font-size:.85rem; margin-bottom:2rem; }
.divider{ display:flex; align-items:center; margin:1.5rem 0; }
.divider::before, .divider::after{ content:''; flex:1; border-bottom:1px solid #ddd; }
.divider span{ padding:0 12px; color:#999; font-size:.8rem; }
.btn-primary{ background:var(--azul); border-color:var(--azul); }
.btn-primary:hover{ background:#0a3d7a; border-color:#0a3d7a; }
.google-btn{
  display:flex; align-items:center; justify-content:center; gap:10px;
  width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;
  background:#fff; cursor:pointer; font-size:.95rem; font-weight:500;
  transition:box-shadow .15s, border-color .15s; color:#333;
}
.google-btn:hover{ box-shadow:0 2px 8px rgba(0,0,0,.1); border-color:#bbb; }
.google-btn svg{ flex-shrink:0; }
#google-error{ display:none; }
</style>
</head>
<body>
<div class="card login-card shadow-sm">
  <div class="card-body">
    <div class="brand-title">Cleanalyze</div>
    <div class="brand-sub">Acesse sua conta</div>

    <?php if ($timeout): ?>
      <div class="alert alert-warning py-2 small">Sessão expirada por inatividade.</div>
    <?php endif; ?>
    <?php if ($bye): ?>
      <div class="alert alert-info py-2 small">Você saiu da sua conta.</div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <?php if ($googleEnabled): ?>
    <!-- Google Sign-In -->
    <button type="button" class="google-btn" id="googleLoginBtn">
      <svg width="20" height="20" viewBox="0 0 48 48">
        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
      </svg>
      Entrar com Google
    </button>

    <div id="google-error" class="alert alert-danger py-2 small mt-2"></div>

    <div class="divider"><span>ou entre com e-mail</span></div>
    <?php endif; ?>

    <!-- Login com e-mail/senha -->
    <form method="post" action="login.php" novalidate>
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($hiddenRedirect) ?>">
      <div class="mb-3">
        <label class="form-label small">E-mail</label>
        <input required type="email" name="email" class="form-control" placeholder="seu@email.com">
      </div>
      <div class="mb-3">
        <label class="form-label small">Senha</label>
        <input required type="password" name="password" class="form-control" placeholder="Senha">
      </div>
      <button class="btn btn-primary w-100" type="submit">Entrar</button>
    </form>
  </div>
</div>

<footer class="text-center text-muted my-4" style="font-family:'Manrope',sans-serif;">
  2025 &copy; Desenvolvimento BBZ.
</footer>

<?php if ($googleEnabled): ?>
<script>
const GOOGLE_CLIENT_ID = <?= json_encode($googleClientId) ?>;
const REDIRECT_TO = <?= json_encode($hiddenRedirect) ?>;

// Inicializar Google Identity Services
function initGoogleSignIn() {
  google.accounts.id.initialize({
    client_id: GOOGLE_CLIENT_ID,
    callback: handleGoogleResponse,
    auto_select: false,
    cancel_on_tap_outside: true,
  });
}

// Ao clicar no botão customizado
document.getElementById('googleLoginBtn').addEventListener('click', function() {
  google.accounts.id.prompt((notification) => {
    if (notification.isNotDisplayed() || notification.isSkippedMoment()) {
      // One Tap não apareceu, usar popup
      google.accounts.id.prompt();
    }
  });
});

// Callback após autenticação Google
function handleGoogleResponse(response) {
  const errorEl = document.getElementById('google-error');
  errorEl.style.display = 'none';

  const btn = document.getElementById('googleLoginBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Autenticando...';

  fetch('auth/google-callback.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      credential: response.credential,
      redirect: REDIRECT_TO,
    }),
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      window.location.href = data.redirect || 'index.php';
    } else {
      errorEl.textContent = data.error || 'Erro ao autenticar.';
      errorEl.style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg> Entrar com Google';
    }
  })
  .catch(err => {
    errorEl.textContent = 'Erro de conexão: ' + err.message;
    errorEl.style.display = 'block';
    btn.disabled = false;
  });
}

// Iniciar quando a lib do Google carregar
if (typeof google !== 'undefined' && google.accounts) {
  initGoogleSignIn();
} else {
  window.addEventListener('load', function() {
    if (typeof google !== 'undefined' && google.accounts) {
      initGoogleSignIn();
    }
  });
}
</script>
<?php endif; ?>
</body>
</html>
