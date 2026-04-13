// assets/js/rag-chat.js
// Frontend do Assistente IA (RAG) — lista docs, upload, chat.
(function () {
  'use strict';

  const state = {
    currentDocId: null,
    currentDocName: null,
    docs: [],
    sending: false,
  };

  // --- DOM refs ---
  const $uploadForm   = document.getElementById('ragUploadForm');
  const $pdfInput     = document.getElementById('ragPdfInput');
  const $uploadBtn    = document.getElementById('ragUploadBtn');
  const $uploadStatus = document.getElementById('ragUploadStatus');
  const $docList      = document.getElementById('ragDocList');
  const $refreshBtn   = document.getElementById('ragRefreshBtn');
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
    const data = await api('web/rag-list-docs.php');
    if (!data.ok) {
      $docList.innerHTML = '<div class="text-danger small">Erro: ' + escapeHtml(data.error || '') + '</div>';
      return;
    }
    state.docs = data.documents || [];
    renderDocs();
  }

  function renderDocs() {
    if (!state.docs.length) {
      $docList.innerHTML = '<div class="text-secondary small">Nenhum documento ainda. Faca um upload para comecar.</div>';
      return;
    }
    const html = state.docs.map((d) => {
      const active = d.doc_id === state.currentDocId ? ' active' : '';
      const badge = d.status === 'ready' ? 'ready' : (d.status === 'error' ? 'error' : 'processing');
      return (
        '<div class="rag-doc-item' + active + '" data-id="' + escapeHtml(d.doc_id) + '">' +
          '<div class="rag-doc-name">' + escapeHtml(d.filename) + '</div>' +
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
      el.addEventListener('click', () => {
        const id = el.getAttribute('data-id');
        const doc = state.docs.find((x) => x.doc_id === id);
        if (!doc) return;
        if (doc.status !== 'ready') {
          showUploadStatus('Documento ainda nao pronto (' + doc.status + ')', 'warn');
          return;
        }
        selectDoc(doc);
      });
    });
  }

  // --- Selecao de documento ---
  async function selectDoc(doc) {
    state.currentDocId = doc.doc_id;
    state.currentDocName = doc.filename;
    $chatTitle.textContent = doc.filename;
    $chatSubtitle.textContent = (doc.pages || 0) + ' pagina(s) · ' + formatDate(doc.created_at);
    $chatInput.disabled = false;
    $chatSend.disabled = false;
    $deleteBtn.classList.remove('d-none');
    renderDocs();
    await loadHistory(doc.doc_id);
  }

  async function loadHistory(docId) {
    $chatBody.innerHTML = '<div class="text-secondary small text-center my-3">Carregando historico...</div>';
    const data = await api('web/rag-list-docs.php?doc_id=' + encodeURIComponent(docId) + '&with_history=1');
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

  // --- Upload ---
  $uploadForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!$pdfInput.files || !$pdfInput.files[0]) return;
    const fd = new FormData();
    fd.append('pdf', $pdfInput.files[0]);
    $uploadBtn.disabled = true;
    showUploadStatus('Enviando e processando (OCR + embeddings)...', 'info');
    const data = await api('web/rag-upload.php', { method: 'POST', body: fd });
    $uploadBtn.disabled = false;
    if (!data.ok) {
      showUploadStatus('Erro: ' + (data.error || 'desconhecido'), 'error');
      return;
    }
    showUploadStatus('Documento pronto: ' + data.pages + ' pag., ' + data.chunks + ' chunks.', 'ok');
    $pdfInput.value = '';
    await loadDocs();
    const doc = state.docs.find((x) => x.doc_id === data.doc_id);
    if (doc) await selectDoc(doc);
  });

  // --- Chat send ---
  $chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (state.sending) return;
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

  // --- Init ---
  loadDocs();
})();
