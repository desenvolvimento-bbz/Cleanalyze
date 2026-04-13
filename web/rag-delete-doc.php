<?php
/**
 * web/rag-delete-doc.php
 * Remove um documento do usuario: apaga arquivos + registros no SQLite.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/rag_common.php';

$email = rag_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rag_json_response(['ok' => false, 'error' => 'Metodo invalido'], 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;
$docId = trim((string) ($body['doc_id'] ?? ''));
if (!rag_is_uuid($docId)) {
    rag_json_response(['ok' => false, 'error' => 'doc_id invalido'], 400);
}

$dbPath = rag_user_db($email);
if (!is_file($dbPath)) {
    rag_json_response(['ok' => false, 'error' => 'Nenhum documento registrado'], 404);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Verifica existencia
    $stmt = $pdo->prepare("SELECT filename FROM documents WHERE doc_id = :id");
    $stmt->execute([':id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        rag_json_response(['ok' => false, 'error' => 'Documento nao encontrado'], 404);
    }

    // Deleta (nao conseguimos usar sqlite-vec aqui sem extensao em PHP;
    // tabela chunks_vec e virtual e se torna orfa. Um cron Python ou proprio
    // rag_ingest a limpa no proximo acesso. Solucao robusta: chamar Python.)
    // Para garantir consistencia com vec0, delegamos para o CLI Python.
} catch (Throwable $e) {
    rag_json_response(['ok' => false, 'error' => 'Erro SQLite: ' . $e->getMessage()], 500);
}

// Delega a deletion logica ao Python para manter sqlite-vec consistente
$script = <<<PY
import json, sys
from cleanalize_core.rag.store import RagStore, user_files_dir
import shutil
from pathlib import Path

email = sys.argv[1]
doc_id = sys.argv[2]
try:
    with RagStore(email) as store:
        store.delete_document(doc_id)
    files_dir = user_files_dir(email) / doc_id
    if files_dir.exists():
        shutil.rmtree(files_dir, ignore_errors=True)
    print(json.dumps({"ok": True}))
except Exception as e:
    print(json.dumps({"ok": False, "error": f"{type(e).__name__}: {e}"}))
    sys.exit(1)
PY;

$tmpScript = tempnam(sys_get_temp_dir(), 'rag_del_') . '.py';
file_put_contents($tmpScript, $script);
try {
    $result = rag_run_python_script_file($tmpScript, [$email, $docId]);
} finally {
    @unlink($tmpScript);
}

$json = rag_decode_cli_json($result['stdout']);
if ($result['code'] !== 0 || !$json || empty($json['ok'])) {
    app_log('rag.delete.error', [
        'user' => $email,
        'doc_id' => $docId,
        'error' => $json['error'] ?? ($result['stderr'] ?: 'erro'),
    ]);
    rag_json_response([
        'ok' => false,
        'error' => $json['error'] ?? 'Falha ao deletar',
    ], 500);
}

app_log('rag.delete.ok', ['user' => $email, 'doc_id' => $docId]);
rag_json_response(['ok' => true, 'doc_id' => $docId]);

/** Helper para rodar um script Python arbitrario por caminho absoluto. */
function rag_run_python_script_file(string $scriptPath, array $args): array {
    $python = APP_PYTHON;
    $parts = [escapeshellarg($python), escapeshellarg($scriptPath)];
    foreach ($args as $a) $parts[] = escapeshellarg((string) $a);
    $cmd = implode(' ', $parts);
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptor, $pipes, APP_BASE);
    if (!is_resource($proc)) return ['code' => -1, 'stdout' => '', 'stderr' => 'proc_open falhou'];
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['code' => $code, 'stdout' => $out ?: '', 'stderr' => $err ?: ''];
}
