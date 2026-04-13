// assets/js/rag-admin-docs.js
// Tela admin: upload + gerenciamento de ACL dos Documentos BBZ.
(function () {
  'use strict';

  const $uploadForm   = document.getElementById('adminUploadForm');
  const $pdfInput     = document.getElementById('adminPdfInput');
  const $initialEmails = document.getElementById('adminInitialAccess');
  const $uploadBtn    = document.getElementById('adminUploadBtn');
  const $uploadStatus = document.getElementById('adminUploadStatus');
  const $docsList     = document.getElementById('adminDocsList');
  const $refreshBtn   = document.getElementById('adminRefreshBtn');
  const $loadingOverlay = document.getElementById('ragLoadingOverlay');

  function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function formatDate(ts) {
    if (!ts) return '';
    return new Date(ts * 1000).toLocaleString('pt-BR');
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

  function setStatus(msg, kind) {
    const cls = kind === 'error' ? 'text-danger'
              : kind === 'ok'    ? 'text-success'
              : kind === 'warn'  ? 'text-warning'
              : 'text-secondary';
    $uploadStatus.className = 'small mt-2 ' + cls;
    $uploadStatus.textContent = msg;
  }

  // --- Loading overlay (mesmo padrao de rag-chat.js) ---
  const LOADING_STEPS = [
    { id: 'rag-step-upload', detailId: 'rag-detail-upload', delay: 0 },
    { id: 'rag-step-ocr',    detailId: 'rag-detail-ocr',    delay: 1500 },
    { id: 'rag-step-chunk',  detailId: 'rag-detail-chunk',  delay: 14000 },
    { id: 'rag-step-embed',  detailId: 'rag-detail-embed',  delay: 18000 },
    { id: 'rag-step-done',   detailId: 'rag-detail-done',   delay: 26000 },
  ];
  let loadingTimers = [];

  function resetLoading() {
    LOADING_STEPS.forEach((s) => {
      const el = document.getElementById(s.id);
      if (!el) return;
      el.classList.remove('active', 'done');
      const icon = el.querySelector('.icon');
      if (icon && icon.dataset.original) icon.innerHTML = icon.dataset.original;
      const dots = el.querySelector('.rag-loading-dots');
      if (dots) dots.style.display = '';
      const detail = document.getElementById(s.detailId);
      if (detail) detail.textContent = '';
    });
  }

  function markStepDone(idx) {
    const step = LOADING_STEPS[idx];
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

  function activateStep(idx) {
    for (let i = 0; i < idx; i++) {
      const prev = document.getElementById(LOADING_STEPS[i].id);
      if (prev && !prev.classList.contains('done')) markStepDone(i);
    }
    const el = document.getElementById(LOADING_STEPS[idx].id);
    if (el) el.classList.add('active');
  }

  function showLoading(file) {
    if (!$loadingOverlay) return;
    resetLoading();
    $loadingOverlay.classList.add('active');
    const uploadDetail = document.getElementById('rag-detail-upload');
    if (uploadDetail && file) {
      uploadDetail.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
    }
    loadingTimers.forEach(clearTimeout);
    loadingTimers = LOADING_STEPS.map((s, idx) =>
      setTimeout(() => activateStep(idx), s.delay)
    );
  }

  function hideLoading(opts) {
    loadingTimers.forEach(clearTimeout);
    loadingTimers = [];
    if (!$loadingOverlay) return;
    if (opts && opts.success) {
      LOADING_STEPS.forEach((_, idx) => markStepDone(idx));
      const doneDetail = document.getElementById('rag-detail-done');
      if (doneDetail) doneDetail.textContent = opts.doneText || 'Pronto!';
      setTimeout(() => $loadingOverlay.classList.remove('active'), 700);
    } else {
      $loadingOverlay.classList.remove('active');
    }
  }

  // --- Render dos docs + ACLs ---
  async function loadDocs() {
    $docsList.innerHTML = '<div class="text-secondary small">Carregando...</div>';
    const data = await api('web/rag-admin-global-list-all.php');
    if (!data.ok) {
      $docsList.innerHTML = '<div class="text-danger small">Erro: ' + escapeHtml(data.error || '') + '</div>';
      return;
    }
    const docs = data.documents || [];
    if (!docs.length) {
      $docsList.innerHTML = '<div class="text-secondary small">Nenhum Documento BBZ publicado ainda.</div>';
      return;
    }
    $docsList.innerHTML = docs.map(renderDoc).join('');
    docs.forEach(wireDocEvents);
  }

  function renderDoc(d) {
    const statusBadge = d.status === 'ready' ? 'bg-success'
                      : d.status === 'error' ? 'bg-danger' : 'bg-warning';
    const accessHtml = (d.access && d.access.length)
      ? d.access.map((e) =>
          '<span class="admin-access-pill" data-email="' + escapeHtml(e) + '">' +
            escapeHtml(e) +
            '<button type="button" class="remove js-revoke" title="Remover acesso">&times;</button>' +
          '</span>'
        ).join('')
      : '<span class="admin-access-empty">Nenhum usuario com acesso. Adicione emails abaixo.</span>';

    return (
      '<div class="admin-doc-card" data-id="' + escapeHtml(d.doc_id) + '">' +
        '<div class="card-header">' +
          '<div>' +
            '<div style="font-weight:600;">' + escapeHtml(d.filename) + '</div>' +
            '<div class="text-secondary" style="font-size:.75rem;">' +
              '<span class="badge ' + statusBadge + '" style="font-size:.65rem;">' + escapeHtml(d.status) + '</span> ' +
              (d.pages ? (d.pages + ' pag. · ') : '') +
              escapeHtml(formatDate(d.created_at)) +
            '</div>' +
          '</div>' +
          '<button type="button" class="btn btn-sm btn-outline-danger js-delete-doc">Excluir</button>' +
        '</div>' +
        '<div class="card-body">' +
          '<div class="small text-secondary mb-1">Usuarios com acesso:</div>' +
          '<div class="admin-access-list">' + accessHtml + '</div>' +
          '<div class="admin-add-email">' +
            '<input type="email" class="form-control form-control-sm js-new-email" placeholder="usuario@bbz.com.br">' +
            '<button type="button" class="btn btn-sm btn-primary js-add-email">Adicionar</button>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  }

  function wireDocEvents(doc) {
    const card = $docsList.querySelector('.admin-doc-card[data-id="' + doc.doc_id + '"]');
    if (!card) return;

    card.querySelectorAll('.js-revoke').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const pill = btn.closest('.admin-access-pill');
        const email = pill.getAttribute('data-email');
        if (!confirm('Remover acesso de ' + email + '?')) return;
        const data = await api('web/rag-admin-global-access.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'revoke', doc_id: doc.doc_id, email: email }),
        });
        if (!data.ok) {
          alert('Erro ao remover: ' + (data.error || 'desconhecido'));
          return;
        }
        await loadDocs();
      });
    });

    const addBtn = card.querySelector('.js-add-email');
    const addInput = card.querySelector('.js-new-email');
    async function doAdd() {
      const email = (addInput.value || '').trim().toLowerCase();
      if (!email) return;
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('Email invalido');
        return;
      }
      addBtn.disabled = true;
      const data = await api('web/rag-admin-global-access.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'grant', doc_id: doc.doc_id, email: email }),
      });
      addBtn.disabled = false;
      if (!data.ok) {
        alert('Erro ao adicionar: ' + (data.error || 'desconhecido'));
        return;
      }
      addInput.value = '';
      await loadDocs();
    }
    addBtn.addEventListener('click', doAdd);
    addInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } });

    const delBtn = card.querySelector('.js-delete-doc');
    delBtn.addEventListener('click', async () => {
      if (!confirm('Excluir "' + doc.filename + '"? Todos os usuarios perderao acesso.')) return;
      const data = await api('web/rag-admin-global-delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ doc_id: doc.doc_id }),
      });
      if (!data.ok) {
        alert('Erro ao excluir: ' + (data.error || 'desconhecido'));
        return;
      }
      await loadDocs();
    });
  }

  // --- Upload ---
  $uploadForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!$pdfInput.files || !$pdfInput.files[0]) return;
    const file = $pdfInput.files[0];
    const fd = new FormData();
    fd.append('pdf', file);
    const initial = ($initialEmails.value || '').trim();
    if (initial) fd.append('access_emails', initial);
    $uploadBtn.disabled = true;
    setStatus('Processando documento...', 'info');
    showLoading(file);
    try {
      const data = await api('web/rag-admin-global-upload.php', { method: 'POST', body: fd });
      if (!data.ok) {
        hideLoading({ success: false });
        setStatus('Erro: ' + (data.error || 'desconhecido'), 'error');
        return;
      }
      hideLoading({
        success: true,
        doneText: data.pages + ' pag. · ' + data.chunks + ' chunks indexados',
      });
      const grantedN = (data.granted || []).length;
      setStatus(
        'Documento publicado: ' + data.pages + ' pag., ' + data.chunks + ' chunks. ' +
        (grantedN ? grantedN + ' usuario(s) com acesso concedido.' : 'Adicione usuarios abaixo.'),
        'ok'
      );
      $pdfInput.value = '';
      $initialEmails.value = '';
      await loadDocs();
    } catch (err) {
      hideLoading({ success: false });
      setStatus('Erro: ' + (err.message || err), 'error');
    } finally {
      $uploadBtn.disabled = false;
    }
  });

  $refreshBtn.addEventListener('click', loadDocs);

  // --- Init ---
  loadDocs();
})();
