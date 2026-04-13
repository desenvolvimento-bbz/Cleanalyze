<?php
/**
 * config/oauth.php
 * Configurações do Google OAuth.
 *
 * Para configurar:
 * 1. Acesse https://console.cloud.google.com/
 * 2. Crie um projeto (ou use existente)
 * 3. Em "APIs & Services" > "Credentials" > "Create Credentials" > "OAuth 2.0 Client ID"
 * 4. Tipo: "Web application"
 * 5. Em "Authorized JavaScript origins", adicione:
 *    - http://localhost:8080 (desenvolvimento)
 *    - https://seu-dominio.com (produção)
 * 6. Copie o "Client ID" e cole abaixo
 */

require_once __DIR__ . '/env.php';

// Google OAuth Client ID (obtido do Google Cloud Console, via .env)
define('GOOGLE_CLIENT_ID', env('GOOGLE_CLIENT_ID', ''));

// Domínios permitidos para login (sem @)
define('ALLOWED_DOMAINS', ['bbz.com.br']);

// Se true, cria automaticamente o usuário no users.json ao primeiro login Google
define('GOOGLE_AUTO_CREATE_USER', true);

// Role padrão para novos usuários criados via Google
define('GOOGLE_DEFAULT_ROLE', 'user');
