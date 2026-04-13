<?php
/**
 * config/env.php
 * Carrega variáveis do arquivo .env (na raiz do projeto) uma única vez
 * e expõe o helper env($key, $default) para leitura segura.
 *
 * Uso:
 *   require_once __DIR__ . '/../config/env.php';
 *   $key = env('OPENAI_API_KEY');
 */

if (defined('CLEANALYZE_ENV_LOADED')) {
    return;
}
define('CLEANALYZE_ENV_LOADED', true);

$__envRoot = dirname(__DIR__);
$__autoload = $__envRoot . '/vendor/autoload.php';

if (is_file($__autoload)) {
    require_once $__autoload;
    if (class_exists(\Dotenv\Dotenv::class)) {
        try {
            $dotenv = \Dotenv\Dotenv::createImmutable($__envRoot);
            $dotenv->safeLoad();
        } catch (\Throwable $e) {
            // Silencioso: se .env não existir, segue com getenv() do sistema.
        }
    }
} else {
    // Fallback minimalista: parser simples do .env caso vendor/ ainda não exista
    // (ex: primeiro build antes do composer install).
    $__envFile = $__envRoot . '/.env';
    if (is_file($__envFile)) {
        $__lines = @file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($__lines as $__line) {
            $__line = trim($__line);
            if ($__line === '' || $__line[0] === '#') continue;
            if (strpos($__line, '=') === false) continue;
            [$__k, $__v] = array_map('trim', explode('=', $__line, 2));
            if ($__k === '') continue;
            // Remove aspas simples/duplas ao redor
            if (strlen($__v) >= 2) {
                $__first = $__v[0];
                $__last  = substr($__v, -1);
                if (($__first === '"' && $__last === '"') || ($__first === "'" && $__last === "'")) {
                    $__v = substr($__v, 1, -1);
                }
            }
            if (getenv($__k) === false) {
                putenv("$__k=$__v");
                $_ENV[$__k]    = $__v;
                $_SERVER[$__k] = $__v;
            }
        }
    }
}

if (!function_exists('env')) {
    /**
     * Lê uma variável de ambiente, com fallback para um valor default.
     * Considera $_ENV, $_SERVER e getenv(), nessa ordem.
     */
    function env(string $key, $default = null) {
        if (array_key_exists($key, $_ENV))    return $_ENV[$key];
        if (array_key_exists($key, $_SERVER)) return $_SERVER[$key];
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}
