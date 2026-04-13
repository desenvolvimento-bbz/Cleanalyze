<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login();
app_log('page.view', ['page' => basename(__FILE__)]);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Assistente IA — Cleanalyze</title>
  <?php include __DIR__ . '/includes/head.php'; ?>
  <link rel="stylesheet" href="assets/css/rag-chat.css">
</head>
<body>
<?php $activePage = 'rag'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-4">
  <div class="row g-4">
    <!-- Coluna esquerda: lista de documentos + upload -->
    <div class="col-lg-4">
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title mb-3">Novo Documento</h5>
          <p class="text-secondary small mb-3">
            Envie um PDF ou imagem. O texto sera extraido por OCR (Mistral) e
            voce podera fazer perguntas sobre o conteudo.
          </p>
          <form id="ragUploadForm">
            <div class="mb-2">
              <input type="file" name="pdf" id="ragPdfInput"
                     accept="application/pdf,image/png,image/jpeg,image/webp"
                     class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100" id="ragUploadBtn">
              Enviar e processar
            </button>
            <div id="ragUploadStatus" class="small mt-2 text-secondary"></div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="card-title m-0">Meus Documentos</h5>
            <button id="ragRefreshBtn" class="btn btn-sm btn-outline-secondary" title="Atualizar">
              &#x21bb;
            </button>
          </div>
          <div id="ragDocList" class="rag-doc-list">
            <div class="text-secondary small">Carregando...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Coluna direita: chat -->
    <div class="col-lg-8">
      <div class="card rag-chat-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <strong id="ragChatTitle">Selecione um documento</strong>
            <div class="small text-secondary" id="ragChatSubtitle">
              Escolha um documento na lista ao lado para comecar.
            </div>
          </div>
          <button id="ragDeleteBtn" class="btn btn-sm btn-outline-danger d-none">
            Excluir
          </button>
        </div>
        <div class="card-body rag-chat-body" id="ragChatBody">
          <div class="text-center text-secondary my-5" id="ragChatEmpty">
            Nenhuma conversa ativa.
          </div>
        </div>
        <div class="card-footer">
          <form id="ragChatForm" class="d-flex gap-2">
            <input type="text" class="form-control" id="ragChatInput"
                   placeholder="Faca uma pergunta sobre o documento..."
                   disabled maxlength="2000">
            <button type="submit" class="btn btn-primary" id="ragChatSend" disabled>
              Enviar
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<footer class="text-center text-muted my-4">
  2025 &copy; Desenvolvimento BBZ.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/rag-chat.js"></script>
</body>
</html>
