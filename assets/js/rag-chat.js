// assets/js/rag-chat.js
// Frontend do Assistente IA (RAG) — lista docs, upload, chat.
(function () {
  'use strict';

  const state = {
    currentDocId: null,
    currentDocName: null,
    currentIsGlobal: false,
    docs: [],
    globalDocs: [],
    sending: false,
    viewAsUser: null, // email do usuario sendo impersonado (null = modo normal)
  };

  // Restaura view-as de session storage (sobrevive refresh, some ao fechar aba)
  try {
    const saved = sessionStorage.getItem('ragViewAsUser');
    if (saved) state.viewAsUser = saved;
  } catch (_) { /* ignore */ }

  function isViewingAs() {
    return !!state.viewAsUser;
  }

  function withViewAs(url) {
    // Anexa ?as_user=... em URLs GET quando em modo view-as
    if (!isViewingAs()) return url;
    const sep = url.indexOf('?') >= 0 ? '&' : '?';
    return url + sep + 'as_user=' + encodeURIComponent(state.viewAsUser);
  }

  // --- DOM refs ---
  const $uploadForm   = document.getElementById('ragUploadForm');
  const $pdfInput     = document.getElementById('ragPdfInput');
  const $uploadBtn    = document.getElementById('ragUploadBtn');
  const $uploadStatus = document.getElementById('ragUploadStatus');
  const $loadingOverlay = document.getElementById('ragLoadingOverlay');
  const $docList       = document.getElementById('ragDocList');
  const $globalDocList = document.getElementById('ragGlobalDocList');
  const $globalCard    = document.getElementById('ragGlobalCard');
  const $refreshBtn    = document.getElementById('ragRefreshBtn');
  const $viewAsSelect  = document.getElementById('ragViewAsSelect');
  const $viewAsBanner  = document.getElementById('ragViewAsBanner');
  const $viewAsBannerEmail = document.getElementById('ragViewAsBannerEmail');
  const $viewAsHint    = document.getElementById('ragViewAsHint');
  const $chatTitle    = document.getElementById('ragChatTitle');
  const $chatSubtitle = document.getElementById('ragChatSubtitle');
  const $chatBody     = document.getElementById('ragChatBody');
  const $chatEmpty    = document.getElementById('ragChatEmpty');
  const $chatForm     = document.getElementById('ragChatForm');
  const $chatInput    = document.getElementById('ragChatInput');
  const $chatSend     = document.getElementById('ragChatSend');
  const $deleteBtn    = document.getElementById('ragDeleteBtn');

  // --- Helpers ---
  function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function formatDate(ts) {
    if (!ts) return '';
    const d = new Date(ts * 1000);
    return d.toLocaleString('pt-BR');
  }

  function formatBytes(n) {
    if (!n || n < 1024) return (n || 0) + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  // Remove a extensao do arquivo para exibicao (mantem o filename real
  // na state para download/ocr-text). Ex: "manual.pdf" -> "manual"
  function stripExt(name) {
    if (!name) return '';
    return name.replace(/\.(pdf|png|jpe?g|webp)$/i, '');
  }

  async function copyToClipboard(text) {
    // Metodo moderno
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }
    // Fallback: textarea temporario + document.execCommand
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
      const ok = document.execCommand('copy');
      if (!ok) throw new Error('execCommand copy falhou');
    } finally {
      document.body.removeChild(ta);
    }
  }

  async function api(path, opts = {}) {
    const res = await fetch(path, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      ...opts,
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'resposta invalida' }));
    if (!res.ok && !data.error) data.error = 'HTTP ' + res.status;
    return data;
  }

  // --- Lista de documentos ---
  async function loadDocs() {
    $docList.innerHTML = '<div class="text-secondary small">Carregando...</div>';
    if ($globalDocList) $globalDocList.innerHTML = '<div class="text-secondary small">Carregando...</div>';
    const data = await api(withViewAs('web/rag-list-docs.php'));
    if (!data.ok) {
      $docList.innerHTML = '<div class="text-danger small">Erro: ' + escapeHtml(data.error || '') + '</div>';
      return;
    }
    state.docs = data.documents || [];
    state.globalDocs = data.global_documents || [];
    renderDocs();
    renderGlobalDocs();
  }

  function renderGlobalDocs() {
    if (!$globalCard || !$globalDocList) return;
    if (!state.globalDocs.length) {
      $globalCard.classList.add('d-none');
      return;
    }
    $globalCard.classList.remove('d-none');

    const iconDownload =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">' +
        '<path d="M7.5 1a.5.5 0 0 1 .5.5v7.793l2.646-2.647a.5.5 0 1 1 .708.708l-3.5 3.5a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L7 9.293V1.5a.5.5 0 0 1 .5-.5z"/>' +
        '<path d="M2 12.5a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1h-10a.5.5 0 0 1-.5-.5z"/>' +
      '</svg>';
    const iconCopy =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">' +
        '<path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>' +
        '<path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>' +
      '</svg>';

    const isAdmin = !!window.ragIsAdmin;

    $globalDocList.innerHTML = state.globalDocs.map((d) => {
      const active = d.doc_id === state.currentDocId ? ' active' : '';
      const canAct = d.status === 'ready';
      return (
        '<div class="rag-doc-item rag-doc-item-global' + active + '" data-id="' + escapeHtml(d.doc_id) + '">' +
          '<div class="rag-doc-actions">' +
            (canAct
              ? '<button type="button" class="rag-doc-btn js-doc-download" title="Baixar PDF" aria-label="Baixar PDF">' + iconDownload + '</button>'
              : '') +
            // "Copiar texto do OCR" e restrito a admin nos Documentos BBZ
            (canAct && isAdmin
              ? '<button type="button" class="rag-doc-btn js-doc-copy" title="Copiar texto do OCR" aria-label="Copiar texto do OCR">' + iconCopy + '</button>'
              : '') +
          '</div>' +
          '<div class="rag-doc-name">' + escapeHtml(stripExt(d.filename)) + '</div>' +
          '<div class="rag-doc-meta">' +
            '<span class="rag-doc-badge bbz">BBZ</span> ' +
            (d.pages ? (d.pages + ' pag.') : '') +
          '</div>' +
        '</div>'
      );
    }).join('');

    $globalDocList.querySelectorAll('.rag-doc-item-global').forEach((el) => {
      const id = el.getAttribute('data-id');

      el.addEventListener('click', (e) => {
        if (e.target.closest('.rag-doc-btn')) return;
        const doc = state.globalDocs.find((x) => x.doc_id === id);
        if (!doc) return;
        if (doc.status !== 'ready') {
          showUploadStatus('Documento BBZ ainda nao pronto (' + doc.status + ')', 'warn');
          return;
        }
        selectDoc(doc, { isGlobal: true });
      });

      const dlBtn = el.querySelector('.js-doc-download');
      if (dlBtn) {
        dlBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          e.preventDefault();
          window.location.href = withViewAs('web/rag-download.php?doc_id=' + encodeURIComponent(id));
        });
      }

      const copyBtn = el.querySelector('.js-doc-copy');
      if (copyBtn) {
        copyBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          e.preventDefault();
          const originalHtml = copyBtn.innerHTML;
          copyBtn.disabled = true;
          try {
            const data = await api(withViewAs('web/rag-ocr-text.php?doc_id=' + encodeURIComponent(id)));
            if (!data.ok || typeof data.text !== 'string') {
              throw new Error(data.error || 'falha ao obter texto');
            }
            await copyToClipboard(data.text);
            copyBtn.innerHTML =
              '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/></svg>';
            copyBtn.classList.add('is-success');
            showUploadStatus('Texto do OCR copiado (' + formatBytes(data.bytes || 0) + ').', 'ok');
            setTimeout(() => {
              copyBtn.innerHTML = originalHtml;
              copyBtn.classList.remove('is-success');
              copyBtn.disabled = false;
            }, 1500);
          } catch (err) {
            copyBtn.disabled = false;
            alert('Nao foi possivel copiar: ' + (err.message || err));
          }
        });
      }
    });
  }

  function renderDocs() {
    if (!state.docs.length) {
      $docList.innerHTML = '<div class="text-secondary small">Nenhum documento ainda. Faca um upload para comecar.</div>';
      return;
    }
    const iconDownload =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">' +
        '<path d="M7.5 1a.5.5 0 0 1 .5.5v7.793l2.646-2.647a.5.5 0 1 1 .708.708l-3.5 3.5a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L7 9.293V1.5a.5.5 0 0 1 .5-.5z"/>' +
        '<path d="M2 12.5a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1h-10a.5.5 0 0 1-.5-.5z"/>' +
      '</svg>';
    const iconCopy =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">' +
        '<path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>' +
        '<path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>' +
      '</svg>';
    const iconCheck =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">' +
        '<path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/>' +
      '</svg>';
    const iconTrash =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">' +
        '<path d="M5.5 5.5a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>' +
        '<path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3h11V2h-11v1z"/>' +
      '</svg>';

    const html = state.docs.map((d) => {
      const active = d.doc_id === state.currentDocId ? ' active' : '';
      const badge = d.status === 'ready' ? 'ready' : (d.status === 'error' ? 'error' : 'processing');
      const canAct = d.status === 'ready';
      const viewing = isViewingAs();
      return (
        '<div class="rag-doc-item' + active + '" data-id="' + escapeHtml(d.doc_id) + '">' +
          '<div class="rag-doc-actions">' +
            (canAct
              ? '<button type="button" class="rag-doc-btn js-doc-download" title="Baixar PDF original" aria-label="Baixar PDF original">' + iconDownload + '</button>'
              : '') +
            (canAct
              ? '<button type="button" class="rag-doc-btn js-doc-copy" title="Copiar texto do OCR" aria-label="Copiar texto do OCR">' + iconCopy + '</button>'
              : '') +
            (viewing
              ? ''
              : '<button type="button" class="rag-doc-btn js-doc-delete" title="Excluir documento" aria-label="Excluir documento">' + iconTrash + '</button>') +
          '</div>' +
          '<div class="rag-doc-name">' + escapeHtml(stripExt(d.filename)) + '</div>' +
          '<div class="rag-doc-meta">' +
            '<span class="rag-doc-badge ' + badge + '">' + escapeHtml(d.status) + '</span> ' +
            (d.pages ? (d.pages + ' pag. · ') : '') +
            escapeHtml(formatDate(d.created_at)) +
          '</div>' +
          (d.status === 'error' && d.error_message
            ? '<div class="small text-danger mt-1">' + escapeHtml(d.error_message) + '</div>'
            : '') +
        '</div>'
      );
    }).join('');
    $docList.innerHTML = html;

    $docList.querySelectorAll('.rag-doc-item').forEach((el) => {
      const id = el.getAttribute('data-id');

      // Clique no card (fora dos botoes) abre o chat
      el.addEventListener('click', (e) => {
        if (e.target.closest('.rag-doc-btn')) return; // ignorado — tratado pelos listeners abaixo
        const doc = state.docs.find((x) => x.doc_id === id);
        if (!doc) return;
        if (doc.status !== 'ready') {
          showUploadStatus('Documento ainda nao pronto (' + doc.status + ')', 'warn');
          return;
        }
        selectDoc(doc);
      });

      // Botao download
      const dlBtn = el.querySelector('.js-doc-download');
      if (dlBtn) {
        dlBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          e.preventDefault();
          // Navega direto para o endpoint; o servidor responde com Content-Disposition: attachment
          window.location.href = withViewAs('web/rag-download.php?doc_id=' + encodeURIComponent(id));
        });
      }

      // Botao copiar texto OCR
      const copyBtn = el.querySelector('.js-doc-copy');
      if (copyBtn) {
        copyBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          e.preventDefault();
          const originalHtml = copyBtn.innerHTML;
          copyBtn.disabled = true;
          try {
            const data = await api(withViewAs('web/rag-ocr-text.php?doc_id=' + encodeURIComponent(id)));
            if (!data.ok || typeof data.text !== 'string') {
              throw new Error(data.error || 'falha ao obter texto');
            }
            await copyToClipboard(data.text);
            // Feedback visual: troca o icone por um check por 1.5s
            copyBtn.innerHTML = iconCheck;
            copyBtn.classList.add('is-success');
            copyBtn.title = 'Texto copiado!';
            showUploadStatus('Texto do OCR copiado para a area de transferencia (' + formatBytes(data.bytes || 0) + ').', 'ok');
            setTimeout(() => {
              copyBtn.innerHTML = originalHtml;
              copyBtn.classList.remove('is-success');
              copyBtn.title = 'Copiar texto do OCR';
              copyBtn.disabled = false;
            }, 1500);
          } catch (err) {
            copyBtn.disabled = false;
            alert('Nao foi possivel copiar o texto: ' + (err.message || err));
          }
        });
      }

      // Botao delete (direto da lista, sem precisar abrir o chat)
      const delBtn = el.querySelector('.js-doc-delete');
      if (delBtn) {
        delBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          e.preventDefault();
          if (isViewingAs()) {
            alert('Exclusao desabilitada em modo "visualizar como".');
            return;
          }
          const doc = state.docs.find((x) => x.doc_id === id);
          const name = doc ? stripExt(doc.filename) : 'este documento';
          if (!confirm('Excluir "' + name + '" e todo o seu historico? Esta acao nao pode ser desfeita.')) {
            return;
          }
          const data = await api('web/rag-delete-doc.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ doc_id: id }),
          });
          if (!data.ok) {
            alert('Erro ao excluir: ' + (data.error || 'desconhecido'));
            return;
          }
          // Se o doc deletado estava aberto no chat, limpa a area de chat
          if (state.currentDocId === id) {
            state.currentDocId = null;
            state.currentDocName = null;
            $chatTitle.textContent = 'Selecione um documento';
            $chatSubtitle.textContent = 'Escolha um documento na lista ao lado para comecar.';
            $chatBody.innerHTML = '<div class="text-center text-secondary my-5">Nenhuma conversa ativa.</div>';
            $chatInput.disabled = true;
            $chatSend.disabled = true;
            $deleteBtn.classList.add('d-none');
          }
          await loadDocs();
        });
      }
    });
  }

  // --- Selecao de documento ---
  async function selectDoc(doc, opts) {
    const isGlobal = !!(opts && opts.isGlobal);
    state.currentDocId = doc.doc_id;
    state.currentDocName = doc.filename;
    state.currentIsGlobal = isGlobal;
    $chatTitle.textContent = stripExt(doc.filename) + (isGlobal ? ' · BBZ' : '');
    const created = doc.created_at ? formatDate(doc.created_at) : '';
    $chatSubtitle.textContent = (doc.pages || 0) + ' pagina(s)' + (created ? ' · ' + created : '');
    // Em modo view-as: chat e delete sao sempre desabilitados
    const viewing = isViewingAs();
    $chatInput.disabled = viewing;
    $chatSend.disabled = viewing;
    // Usuario comum nao pode deletar doc global pelo botao do chat,
    // nem ninguem pode deletar em modo view-as
    if (isGlobal || viewing) {
      $deleteBtn.classList.add('d-none');
    } else {
      $deleteBtn.classList.remove('d-none');
    }
    renderDocs();
    renderGlobalDocs();
    await loadHistory(doc.doc_id);
  }

  async function loadHistory(docId) {
    $chatBody.innerHTML = '<div class="text-secondary small text-center my-3">Carregando historico...</div>';
    const data = await api(withViewAs('web/rag-list-docs.php?doc_id=' + encodeURIComponent(docId) + '&with_history=1'));
    if (!data.ok) {
      $chatBody.innerHTML = '<div class="text-danger small text-center my-3">Erro: ' + escapeHtml(data.error || '') + '</div>';
      return;
    }
    const history = data.history || [];
    $chatBody.innerHTML = '';
    if (!history.length) {
      $chatBody.innerHTML = '<div class="text-secondary small text-center my-3">Nenhuma conversa ainda. Faca a primeira pergunta!</div>';
    } else {
      history.forEach((m) => appendMessage(m.role, m.content, m.sources));
    }
    scrollChatToEnd();
  }

  function appendMessage(role, content, sources) {
    // Se o placeholder "vazio" estiver, remove
    const empty = $chatBody.querySelector('.text-secondary.text-center');
    if (empty && !empty.classList.contains('rag-typing-wrapper')) empty.remove();

    const wrap = document.createElement('div');
    wrap.className = 'rag-message ' + (role === 'user' ? 'user' : 'assistant');
    const bubble = document.createElement('div');
    bubble.className = 'rag-bubble';
    bubble.textContent = content;

    wrap.appendChild(bubble);

    if (role === 'assistant' && Array.isArray(sources) && sources.length) {
      const src = document.createElement('div');
      src.className = 'rag-sources';
      const items = sources.map((s) =>
        '<li>Pagina ' + (s.page || '?') + ' — <em>' + escapeHtml(s.preview || '') + '</em></li>'
      ).join('');
      src.innerHTML = '<details><summary>Fontes (' + sources.length + ')</summary><ul>' + items + '</ul></details>';
      bubble.appendChild(src);
    }
    $chatBody.appendChild(wrap);
    scrollChatToEnd();
  }

  function appendTyping() {
    const wrap = document.createElement('div');
    wrap.className = 'rag-message assistant rag-typing-wrapper';
    wrap.innerHTML = '<div class="rag-bubble"><span class="rag-typing">Pensando</span></div>';
    $chatBody.appendChild(wrap);
    scrollChatToEnd();
    return wrap;
  }

  function scrollChatToEnd() {
    $chatBody.scrollTop = $chatBody.scrollHeight;
  }

  function showUploadStatus(msg, kind) {
    const cls = kind === 'error' ? 'text-danger'
              : kind === 'ok'    ? 'text-success'
              : kind === 'warn'  ? 'text-warning'
              : 'text-secondary';
    $uploadStatus.className = 'small mt-2 ' + cls;
    $uploadStatus.textContent = msg;
  }

  // --- Loading overlay helpers ---
  const LOADING_STEPS = [
    { id: 'rag-step-upload', detailId: 'rag-detail-upload', delay: 0 },
    { id: 'rag-step-ocr',    detailId: 'rag-detail-ocr',    delay: 1500 },
    { id: 'rag-step-chunk',  detailId: 'rag-detail-chunk',  delay: 14000 },
    { id: 'rag-step-embed',  detailId: 'rag-detail-embed',  delay: 18000 },
    { id: 'rag-step-done',   detailId: 'rag-detail-done',   delay: 26000 },
  ];

  let loadingTimers = [];

  function resetLoadingSteps() {
    LOADING_STEPS.forEach((s) => {
      const el = document.getElementById(s.id);
      if (!el) return;
      el.classList.remove('active', 'done');
      // Restaura o icone original (checkmark fica somente em 'done')
      const icon = el.querySelector('.icon');
      if (icon && icon.dataset.original) icon.innerHTML = icon.dataset.original;
      const dots = el.querySelector('.rag-loading-dots');
      if (dots) dots.style.display = '';
      const detail = document.getElementById(s.detailId);
      if (detail) detail.textContent = '';
    });
  }

  function markStepDone(stepIdx) {
    const step = LOADING_STEPS[stepIdx];
    if (!step) return;
    const el = document.getElementById(step.id);
    if (!el) return;
    el.classList.remove('active');
    el.classList.add('done');
    const icon = el.querySelector('.icon');
    if (icon) {
      if (!icon.dataset.original) icon.dataset.original = icon.innerHTML;
      icon.innerHTML = '&#10003;';
    }
    const dots = el.querySelector('.rag-loading-dots');
    if (dots) dots.style.display = 'none';
  }

  function activateStep(stepIdx) {
    const step = LOADING_STEPS[stepIdx];
    if (!step) return;
    for (let i = 0; i < stepIdx; i++) {
      const prev = document.getElementById(LOADING_STEPS[i].id);
      if (prev && !prev.classList.contains('done')) markStepDone(i);
    }
    const el = document.getElementById(step.id);
    if (el) el.classList.add('active');
  }

  function showLoadingOverlay(file) {
    if (!$loadingOverlay) return;
    resetLoadingSteps();
    $loadingOverlay.classList.add('active');

    const sizeKb = file ? (file.size / 1024).toFixed(0) : '';
    const name = file ? file.name : '';
    const uploadDetail = document.getElementById('rag-detail-upload');
    if (uploadDetail && file) uploadDetail.textContent = name + ' (' + sizeKb + ' KB)';

    loadingTimers.forEach(clearTimeout);
    loadingTimers = [];
    LOADING_STEPS.forEach((s, idx) => {
      const t = setTimeout(() => activateStep(idx), s.delay);
      loadingTimers.push(t);
    });
  }

  function hideLoadingOverlay(opts) {
    loadingTimers.forEach(clearTimeout);
    loadingTimers = [];
    if (!$loadingOverlay) return;
    if (opts && opts.success) {
      // Marca todos como done antes de fechar
      LOADING_STEPS.forEach((_, idx) => markStepDone(idx));
      const doneDetail = document.getElementById('rag-detail-done');
      if (doneDetail) doneDetail.textContent = opts.doneText || 'Pronto!';
      setTimeout(() => $loadingOverlay.classList.remove('active'), 700);
    } else {
      $loadingOverlay.classList.remove('active');
    }
  }

  // --- Upload ---
  $uploadForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (isViewingAs()) {
      showUploadStatus('Upload desabilitado em modo "visualizar como".', 'warn');
      return;
    }
    if (!$pdfInput.files || !$pdfInput.files[0]) return;
    const file = $pdfInput.files[0];
    const fd = new FormData();
    fd.append('pdf', file);
    $uploadBtn.disabled = true;
    showUploadStatus('Processando documento...', 'info');
    showLoadingOverlay(file);

    try {
      const data = await api('web/rag-upload.php', { method: 'POST', body: fd });
      if (!data.ok) {
        hideLoadingOverlay({ success: false });
        showUploadStatus('Erro: ' + (data.error || 'desconhecido'), 'error');
        return;
      }
      hideLoadingOverlay({
        success: true,
        doneText: data.pages + ' pag. · ' + data.chunks + ' chunks indexados',
      });
      showUploadStatus('Documento pronto: ' + data.pages + ' pag., ' + data.chunks + ' chunks.', 'ok');
      $pdfInput.value = '';
      await loadDocs();
      const doc = state.docs.find((x) => x.doc_id === data.doc_id);
      if (doc) await selectDoc(doc);
    } catch (err) {
      hideLoadingOverlay({ success: false });
      showUploadStatus('Erro: ' + (err.message || err), 'error');
    } finally {
      $uploadBtn.disabled = false;
    }
  });

  // --- Chat send ---
  $chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (state.sending) return;
    if (isViewingAs()) {
      alert('Chat desabilitado em modo "visualizar como". Apenas leitura.');
      return;
    }
    const q = ($chatInput.value || '').trim();
    if (!q || !state.currentDocId) return;
    state.sending = true;
    $chatSend.disabled = true;
    appendMessage('user', q);
    $chatInput.value = '';
    const typing = appendTyping();

    const data = await api('web/rag-chat-api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ doc_id: state.currentDocId, question: q }),
    });
    typing.remove();
    state.sending = false;
    $chatSend.disabled = false;

    if (!data.ok) {
      appendMessage('assistant', '[Erro] ' + (data.error || 'falha ao responder'), null);
      return;
    }
    appendMessage('assistant', data.answer || '(sem resposta)', data.sources || []);
  });

  // --- Delete ---
  $deleteBtn.addEventListener('click', async () => {
    if (!state.currentDocId) return;
    if (isViewingAs()) {
      alert('Exclusao desabilitada em modo "visualizar como".');
      return;
    }
    if (!confirm('Excluir este documento e todo o seu historico?')) return;
    const data = await api('web/rag-delete-doc.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ doc_id: state.currentDocId }),
    });
    if (!data.ok) {
      alert('Erro ao excluir: ' + (data.error || 'desconhecido'));
      return;
    }
    state.currentDocId = null;
    state.currentDocName = null;
    $chatTitle.textContent = 'Selecione um documento';
    $chatSubtitle.textContent = 'Escolha um documento na lista ao lado para comecar.';
    $chatBody.innerHTML = '<div class="text-center text-secondary my-5">Nenhuma conversa ativa.</div>';
    $chatInput.disabled = true;
    $chatSend.disabled = true;
    $deleteBtn.classList.add('d-none');
    await loadDocs();
  });

  $refreshBtn.addEventListener('click', loadDocs);

  // --- View-as (admin) ---
  async function initViewAs() {
    if (!$viewAsSelect) return; // usuario comum nao tem a barra

    // Popula o dropdown com a lista de usuarios
    try {
      const data = await api('web/rag-admin-list-users.php');
      if (!data.ok) throw new Error(data.error || 'falha');
      // Remove opcoes existentes exceto a primeira
      while ($viewAsSelect.options.length > 1) $viewAsSelect.remove(1);
      (data.users || []).forEach((u) => {
        if (u.is_self) return; // o proprio admin ja e "Seu modo"
        const opt = document.createElement('option');
        opt.value = u.email;
        opt.textContent = u.email + (u.role === 'admin' ? ' (admin)' : '');
        $viewAsSelect.appendChild(opt);
      });
      // Reaplica selecao salva em sessionStorage
      if (state.viewAsUser) {
        $viewAsSelect.value = state.viewAsUser;
      }
    } catch (err) {
      if ($viewAsHint) $viewAsHint.textContent = 'falha ao carregar usuarios';
    }

    $viewAsSelect.addEventListener('change', async () => {
      const target = $viewAsSelect.value || null;
      state.viewAsUser = target;
      try {
        if (target) sessionStorage.setItem('ragViewAsUser', target);
        else sessionStorage.removeItem('ragViewAsUser');
      } catch (_) { /* ignore */ }

      // Limpa contexto de chat corrente
      state.currentDocId = null;
      state.currentDocName = null;
      state.currentIsGlobal = false;
      $chatTitle.textContent = 'Selecione um documento';
      $chatSubtitle.textContent = 'Escolha um documento na lista ao lado para comecar.';
      $chatBody.innerHTML = '<div class="text-center text-secondary my-5">Nenhuma conversa ativa.</div>';
      $chatInput.disabled = true;
      $chatSend.disabled = true;
      $deleteBtn.classList.add('d-none');

      applyViewAsVisuals();
      await loadDocs();
    });

    applyViewAsVisuals();
  }

  function applyViewAsVisuals() {
    const on = isViewingAs();
    document.body.classList.toggle('rag-view-as-active', on);
    if ($viewAsBanner) {
      if (on) {
        $viewAsBanner.classList.remove('d-none');
        if ($viewAsBannerEmail) $viewAsBannerEmail.textContent = state.viewAsUser;
      } else {
        $viewAsBanner.classList.add('d-none');
      }
    }
    // Desabilita inputs sensiveis quando em view-as
    if ($pdfInput)  $pdfInput.disabled  = on;
    if ($uploadBtn) $uploadBtn.disabled = on;
    if (on) {
      $chatInput.disabled = true;
      $chatSend.disabled = true;
    }
    // Delete button ja e gated por doc selecionado — some junto no selectDoc
  }

  // --- Init ---
  initViewAs();
  loadDocs();
})();
