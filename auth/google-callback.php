<?php
/**
 * auth/google-callback.php
 *
 * Recebe o ID token do Google Sign-In via POST,
 * valida, verifica domínio, e autentica o usuário.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config/oauth.php';

$response = ['ok' => false, 'error' => '', 'redirect' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido.');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $credential = $input['credential'] ?? '';
    $redirectTo = $input['redirect'] ?? 'index.php';

    if (!$credential) {
        throw new Exception('Token não recebido.');
    }

    // Decodificar e validar o ID token via Google API
    $tokenPayload = validateGoogleToken($credential);

    if (!$tokenPayload) {
        throw new Exception('Token inválido ou expirado.');
    }

    $email = strtolower($tokenPayload['email'] ?? '');
    $emailVerified = $tokenPayload['email_verified'] ?? false;

    if (!$email || !$emailVerified) {
        throw new Exception('Email não verificado pelo Google.');
    }

    // Verificar domínio
    $domain = substr($email, strrpos($email, '@') + 1);
    if (!in_array($domain, ALLOWED_DOMAINS, true)) {
        throw new Exception("Domínio @{$domain} não autorizado. Apenas @" . implode(', @', ALLOWED_DOMAINS) . " podem acessar.");
    }

    // Verificar/criar usuário no users.json
    $users = users_load();
    if (!isset($users[$email])) {
        if (!GOOGLE_AUTO_CREATE_USER) {
            throw new Exception('Usuário não cadastrado. Solicite acesso ao administrador.');
        }
        // Auto-criar usuário
        $users[$email] = [
            'hash' => '', // sem senha (login apenas por Google)
            'role' => GOOGLE_DEFAULT_ROLE,
            'created_via' => 'google_oauth',
            'created_at' => date('c'),
        ];
        users_save($users);
        app_log('user.autocreate', ['email' => $email, 'via' => 'google_oauth']);
    }

    // Autenticar
    auth_login($email);
    app_log('session.login', ['email' => $email, 'via' => 'google_oauth']);

    $response['ok'] = true;
    $response['redirect'] = $redirectTo;
    $response['email'] = $email;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    app_log('auth.google.error', ['error' => $e->getMessage()]);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;


/**
 * Valida um Google ID token chamando a API tokeninfo do Google.
 * Retorna o payload decodificado ou null se inválido.
 */
function validateGoogleToken(string $idToken): ?array {
    // Usar a API tokeninfo do Google (simples, sem bibliotecas extras)
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$body) {
        return null;
    }

    $payload = json_decode($body, true);
    if (!$payload || !isset($payload['email'])) {
        return null;
    }

    // Verificar audience (client_id)
    if (($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
        app_log('auth.google.aud_mismatch', [
            'expected' => GOOGLE_CLIENT_ID,
            'got' => $payload['aud'] ?? '(empty)',
        ]);
        return null;
    }

    return $payload;
}
