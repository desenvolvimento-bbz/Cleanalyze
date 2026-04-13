<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_admin();
app_log('page.view', ['page' => basename(__FILE__)]);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Documentos BBZ — Cleanalyze</title>
  <?php include __DIR__ . '/includes/head.php'; ?>
  <link rel="stylesheet" href="assets/css/rag-chat.css">
  <style>
    /* Mesmo overlay visual do Assistente IA */
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
    .rag-loading-step.active .icon{ background:#0664e4; animation:ragPulse 1.2s infinite; }
    .rag-loading-step.done .icon{ background:#2e7d32; }
    .rag-loading-step .text{ font-size:.9rem; }
    .rag-loading-step .text .detail{ font-size:.75rem; color:rgba(255,255,255,.5); margin-top:2px; }
    .rag-loading-dots::after{ content:''; animation:ragDots 1.5s steps(4,end) infinite; }
    @keyframes ragDots{ 0%{content:''} 25%{content:'.'} 50%{content:'..'} 75%{content:'...'} }
    @keyframes ragPulse{ 0%,100%{transform:scale(1)} 50%{transform:scale(1.15)} }
    .rag-loading-footer{ position:absolute; bottom:30px; text-align:center; font-size:.75rem; opacity:.4; }

    .admin-doc-card{ border:1px solid #e5e7ef; border-radius:10px; margin-bottom:12px; }
    .admin-doc-card .card-header{
      background:#f7f8fc; border-bottom:1px solid #e5e7ef; padding:12px 16px;
      display:flex; justify-content:space-between; align-items:center; gap:12px;
    }
    .admin-doc-card .card-body{ padding:12px 16px; }
    .admin-access-list{ display:flex; flex-wrap:wrap; gap:6px; }
    .admin-access-pill{
      display:inline-flex; align-items:center; gap:6px;
      padding:4px 8px 4px 12px; border-radius:999px;
      background:#eef1f8; color:#04193b; font-size:.8rem;
    }
    .admin-access-pill .remove{
      cursor:pointer; border:0; background:transparent;
      color:#842029; font-weight:700; padding:0 4px; line-height:1;
    }
    .admin-access-pill .remove:hover{ color:#000; }
    .admin-access-empty{ color:#6c757d; font-size:.8rem; font-style:italic; }
    .admin-add-email{
      display:flex; gap:6px; margin-top:10px; max-width:380px;
    }
  </style>
</head>
<body>
<?php $activePage = 'admin-docs-bbz'; include __DIR__ . '/includes/navbar.php'; ?>

<!-- Loading overlay reusado do Assistente IA -->
<div class="rag-loading-overlay" id="ragLoadingOverlay">
  <div class="rag-loading-logo">Cleanalyze <span>IA</span></div>
  <div class="rag-loading-steps">
    <div class="rag-loading-step" id="rag-step-upload">
      <div class="icon">&#8593;</div>
      <div class="text">Enviando arquivo<span class="rag-loading-dots"></span><div class="detail" id="rag-detail-upload"></div></div>
    </div>
    <div class="rag-loading-step" id="rag-step-ocr">
      <div class="icon">&#128270;</div>
      <div class="text">Reconhecendo texto com OCR<span class="rag-loading-dots"></span><div class="detail" id="rag-detail-ocr"></div></div>
    </div>
    <div class="rag-loading-step" id="rag-step-chunk">
      <div class="icon">&#9783;</div>
      <div class="text">Analisando conteudo do documento<span class="rag-loading-dots"></span><div class="detail" id="rag-detail-chunk"></div></div>
    </div>
    <div class="rag-loading-step" id="rag-step-embed">
      <div class="icon">&#9881;</div>
      <div class="text">Indexando trechos para busca semantica<span class="rag-loading-dots"></span><div class="detail" id="rag-detail-embed"></div></div>
    </div>
    <div class="rag-loading-step" id="rag-step-done">
      <div class="icon">&#9998;</div>
      <div class="text">Publicando Documento BBZ<span class="rag-loading-dots"></span><div class="detail" id="rag-detail-done"></div></div>
    </div>
  </div>
  <div class="rag-loading-footer">
    Respostas geradas por Inteligencia Artificial com base no documento enviado. As informacoes sao indicativas e devem ser validadas no documento original pelo usuario.
  </div>
</div>

<div class="container py-4">
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title mb-3">Novo Documento BBZ</h5>
          <p class="text-secondary small mb-3">
            Manuais e guias oficiais. Apos o upload, defina quais usuarios podem consultar este documento pelo Assistente IA.
          </p>
          <form id="adminUploadForm">
            <div class="mb-2">
              <label class="form-label small">Arquivo (PDF ou imagem)</label>
              <input type="file" name="pdf" id="adminPdfInput"
                     accept="application/pdf,image/png,image/jpeg,image/webp"
                     class="form-control" required>
            </div>
            <div class="mb-2">
              <label class="form-label small">Emails com acesso inicial (opcional)</label>
              <input type="text" id="adminInitialAccess" class="form-control"
                     placeholder="email1@bbz.com.br, email2@bbz.com.br">
              <div class="form-text small">Separe com virgula. Voce pode adicionar mais depois.</div>
            </div>
            <button type="submit" class="btn btn-primary w-100" id="adminUploadBtn">
              Enviar e processar
            </button>
            <div id="adminUploadStatus" class="small mt-2 text-secondary"></div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title m-0">Documentos publicados</h5>
            <button id="adminRefreshBtn" class="btn btn-sm btn-outline-secondary" title="Atualizar">
              &#x21bb;
            </button>
          </div>
          <div id="adminDocsList">
            <div class="text-secondary small">Carregando...</div>
          </div>
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
<script src="assets/js/rag-admin-docs.js"></script>
</body>
</html>
