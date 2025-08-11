<?php
require_once __DIR__ . '/bootstrap.php';

// capture o e-mail antes de limpar a sessão (para log)
$email = $_SESSION['user_email'] ?? null;
app_log('session.logout', ['email' => $email]);

auth_logout(); // limpa sessão

// volta para login.php na pasta raiz do app
header('Location: ../login.php?bye=1');
exit;