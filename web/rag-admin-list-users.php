<?php
/**
 * web/rag-admin-list-users.php
 * Admin — lista todos os emails conhecidos pelo sistema (users.json)
 * para popular o dropdown de "Visualizar como" do Assistente IA.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/rag_common.php';

$adminEmail = rag_require_admin();

$users = function_exists('users_load') ? users_load() : [];
$out = [];
foreach ($users as $email => $info) {
    $role = is_array($info) ? ($info['role'] ?? 'user') : 'user';
    $out[] = [
        'email' => $email,
        'role' => $role,
        'is_self' => strtolower($email) === strtolower($adminEmail),
    ];
}
// Ordena: self primeiro, depois alfabetico
usort($out, function ($a, $b) {
    if ($a['is_self'] !== $b['is_self']) return $a['is_self'] ? -1 : 1;
    return strcmp($a['email'], $b['email']);
});

rag_json_response([
    'ok' => true,
    'admin' => $adminEmail,
    'users' => $out,
]);
