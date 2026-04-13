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
  <style>
    /* Loading overlay (mesmo molde visual de prestacao_anual.php) */
    .rag-loading-overlay{
      display:none; position:fixed; inset:0; z-index:9999;
      background:rgba(4,25,59,.92); color:#fff;
      flex-direction:column; align-items:center; justify-content:center;
    }
    .rag-loading-overlay.active{ display:flex; }
    .rag-loading-logo{ font-size:1.6rem; font-weight:700; margin-bottom:2rem; opacity:.9; }
    .rag-loading-logo span{ color:#0664e4; }
    .rag-loading-steps{ width:100%; max-width:480px; padding:0 20px; }
    .rag-loading-step{
      display:flex; align-items:center; gap:12px;
      padding:10px 0; border-bottom:1px solid rgba(255,255,255,.08);
      opacity:.25; transition:opacity .4s;
    }
    .rag-loading-step.active{ opacity:1; }
    .rag-loading-step.done{ opacity:.6; }
    .rag-loading-step .icon{
      width:28px; height:28px; border-radius:50%; display:flex;
      align-items:center; justify-content:center; font-size:.8rem;
      background:rgba(255,255,255,.1); flex-shrink:0;
    }
    .rag-loading-step.active .icon{
      background:#0664e4; animation:ragPulse 1.2s infinite;
    }
    .rag-loading-step.done .icon{ background:#2e7d32; }
    .rag-loading-step .text{ font-size:.9rem; }
    .rag-loading-step .text .detail{
      font-size:.75rem; color:rgba(255,255,255,.5); margin-top:2px;
    }
    .rag-loading-dots::after{
      content:''; animation:ragDots 1.5s steps(4,end) infinite;
    }
    @keyframes ragDots{
      0%{content:''} 25%{content:'.'} 50%{content:'..'} 75%{content:'...'}
    }
    @keyframes ragPulse{
      0%,100%{transform:scale(1)} 50%{transform:scale(1.15)}
    }
    .rag-loading-footer{
      position:absolute; bottom:30px; text-align:center;
      font-size:.75rem; opacity:.4;
    }
  </style>
</head>
<body>
<?php $activePage = 'rag'; include __DIR__ . '/includes/navbar.php'; ?>

<!-- Loading overlay do upload (mesmo molde do prestacao_anual.php) -->
<div class="rag-loading-overlay" id="ragLoadingOverlay">
  <div class="rag-loading-logo">Cleanalyze <span>IA</span></div>
  <div class="rag-loading-steps">
    <div class="rag-loading-step" id="rag-step-upload">
      <div class="icon">&#8593;</div>
      <div class="text">
        Enviando arquivo<span class="rag-loading-dots"></span>
        <div class="detail" id="rag-detail-upload"></div>
      </div>
    </div>
    <div class="rag-loading-step" id="rag-step-ocr">
      <div class="icon">&#128270;</div>
      <div class="text">
        Reconhecendo texto com OCR<span class="rag-loading-dots"></span>
        <div class="detail" id="rag-detail-ocr"></div>
      </div>
    </div>
    <div class="rag-loading-step" id="rag-step-chunk">
      <div class="icon">&#9783;</div>
      <div class="text">
        Analisando conteudo do documento<span class="rag-loading-dots"></span>
        <div class="detail" id="rag-detail-chunk"></div>
      </div>
    </div>
    <div class="rag-loading-step" id="rag-step-embed">
      <div class="icon">&#9881;</div>
      <div class="text">
        Indexando trechos para busca semantica<span class="rag-loading-dots"></span>
        <div class="detail" id="rag-detail-embed"></div>
      </div>
    </div>
    <div class="rag-loading-step" id="rag-step-done">
      <div class="icon">&#9998;</div>
      <div class="text">
        Preparando Assistente IA<span class="rag-loading-dots"></span>
        <div class="detail" id="rag-detail-done"></div>
      </div>
    </div>
  </div>
  <div class="rag-loading-footer">
    Respostas geradas por Inteligencia Artificial com base no documento enviado. As informacoes sao indicativas e devem ser validadas no documento original pelo usuario.
  </div>
</div>

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

      <div class="card mb-3">
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

      <!-- Documentos BBZ (globais) — so aparece se houver algum acessivel -->
      <div class="card d-none" id="ragGlobalCard">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="card-title m-0">
              Documentos BBZ
              <span class="badge rounded-pill text-bg-info ms-1" style="font-size:.6rem;">BBZ</span>
            </h5>
          </div>
          <p class="text-secondary small mb-2">
            Manuais e guias oficiais. Pergunte duvidas sobre processos da BBZ.
          </p>
          <div id="ragGlobalDocList" class="rag-doc-list">
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

<footer class="text-center text-muted my-4" style="font-size:.8rem;">
  <div>Respostas geradas por Inteligencia Artificial com base no documento enviado. As informacoes sao indicativas e devem ser validadas no documento original pelo usuario.</div>
  <div class="mt-1">2025 &copy; Desenvolvimento BBZ.</div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/rag-chat.js"></script>
</body>
</html>
