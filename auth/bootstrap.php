<?php
// auth/bootstrap.php
// Sessão, timeout, helpers, leitura de users.json/invites.json e checagem de admin.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ====== Config ====== */
define('APP_IDLE_SECONDS', 15 * 60); // 15 min
define('AUTH_DIR', __DIR__);
define('USERS_FILE', AUTH_DIR . '/users.json');
define('INVITES_FILE', AUTH_DIR . '/invites.json');
define('LOG_DIR', dirname(__DIR__) . '/logs');
define('LOG_FILE', LOG_DIR . '/app.log');

/* ====== Utils ====== */
function app_log($event, array $data = []) {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0777, true);
    $row = [
        'ts' => date('c'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'event' => $event,
        'data' => $data,
    ];
    @file_put_contents(LOG_FILE, json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

function json_load($path) {
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}
function json_save($path, array $data) {
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, $path);
}

function users_load() { return json_load(USERS_FILE); }
function users_save(array $u) { json_save(USERS_FILE, $u); }
function invites_load() { return json_load(INVITES_FILE); }
function invites_save(array $i) { json_save(INVITES_FILE, $i); }

/* ====== Sessão: idle timeout ====== */
if (!empty($_SESSION['auth'])) {
    $last = $_SESSION['auth']['last'] ?? time();
    if ((time() - $last) > APP_IDLE_SECONDS) {
        $email = $_SESSION['auth']['email'] ?? null;
        app_log('session.timeout', ['email'=>$email]);
        $_SESSION = [];
        session_destroy();
        $base = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        header('Location: ' . $base . '/login.php?timeout=1');
        exit;
    } else {
        $_SESSION['auth']['last'] = time();
    }
}

/* ====== Auth helpers ====== */
function auth_login(string $email) {
    $_SESSION['auth'] = [
        'email' => $email,
        'last'  => time(),
    ];
}

function auth_logout() {
    $email = $_SESSION['auth']['email'] ?? null;
    app_log('session.logout', ['email'=>$email]);
    $_SESSION = [];
    session_destroy();
}

function auth_user_email() {
    return $_SESSION['auth']['email'] ?? null;
}

function auth_is_admin(): bool {
    $email = auth_user_email();
    if (!$email) return false;
    $users = users_load();
    $role = $users[$email]['role'] ?? 'user';
    return $role === 'admin';
}

function auth_require_login() {
    if (!auth_user_email()) {
        $base = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $here = $_SERVER['REQUEST_URI'] ?? ($base . '/index.php');
        header('Location: ' . $base . '/login.php?redirect=' . urlencode($here));
        exit;
    }
}

function auth_require_admin() {
    auth_require_login();
    if (!auth_is_admin()) {
        http_response_code(403);
        echo "<h1>Acesso negado</h1>";
        exit;
    }
}

/* ====== Compat com código antigo ($USERS como [email => hash]) ====== */
$__users = users_load();
$USERS = [];
foreach ($__users as $mail => $obj) {
    if (isset($obj['hash'])) $USERS[$mail] = $obj['hash'];
}
