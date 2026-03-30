<?php
require_once __DIR__ . '/auth/bootstrap.php';
auth_require_login();
app_log('page.view', ['page'=>basename(__FILE__)]);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
  <title>Analise de Prestacao de Contas por Cleanalyze IA</title>
  <?php include __DIR__ . '/includes/head.php'; ?>
  <style>
    .upload-zone{
      border:2px dashed var(--cinza); border-radius:8px; padding:2rem;
      text-align:center; transition:border-color .2s;
    }
    .upload-zone:hover{ border-color:var(--azul); }
    .upload-zone input[type="file"]{ display:none; }
    .upload-zone label{ cursor:pointer; display:block; }
    .file-name{ font-size:.85rem; color:#666; margin-top:.5rem; }

    /* Loading overlay */
    .loading-overlay{
      display:none; position:fixed; inset:0; z-index:9999;
      background:rgba(4,25,59,.92); color:#fff;
      flex-direction:column; align-items:center; justify-content:center;
    }
    .loading-overlay.active{ display:flex; }
    .loading-logo{ font-size:1.6rem; font-weight:700; margin-bottom:2rem; opacity:.9; }
    .loading-logo span{ color:#0664e4; }
    .loading-steps{ width:100%; max-width:480px; padding:0 20px; }
    .loading-step{
      display:flex; align-items:center; gap:12px;
      padding:10px 0; border-bottom:1px solid rgba(255,255,255,.08);
      opacity:.25; transition:opacity .4s;
    }
    .loading-step.active{ opacity:1; }
    .loading-step.done{ opacity:.6; }
    .loading-step .icon{
      width:28px; height:28px; border-radius:50%; display:flex;
      align-items:center; justify-content:center; font-size:.8rem;
      background:rgba(255,255,255,.1); flex-shrink:0;
    }
    .loading-step.active .icon{
      background:#0664e4; animation:pulse 1.2s infinite;
    }
    .loading-step.done .icon{
      background:#2e7d32;
    }
    .loading-step .text{ font-size:.9rem; }
    .loading-step .text .detail{
      font-size:.75rem; color:rgba(255,255,255,.5); margin-top:2px;
    }
    .loading-dots::after{
      content:''; animation:dots 1.5s steps(4,end) infinite;
    }
    @keyframes dots{
      0%{content:''} 25%{content:'.'} 50%{content:'..'} 75%{content:'...'}
    }
    @keyframes pulse{
      0%,100%{transform:scale(1)} 50%{transform:scale(1.15)}
    }
    .loading-footer{
      position:absolute; bottom:30px; text-align:center;
      font-size:.75rem; opacity:.4;
    }
  </style>
</head>
<body>
<?php $activePage = 'prestacao'; include __DIR__ . '/includes/navbar.php'; ?>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
  <div class="loading-logo">Cleanalyze <span>IA</span></div>
  <div class="loading-steps">
    <div class="loading-step" id="step-upload">
      <div class="icon">&#8593;</div>
      <div class="text">Enviando PDF<span class="loading-dots"></span></div>
    </div>
    <div class="loading-step" id="step-extract">
      <div class="icon">&#9783;</div>
      <div class="text">
        Extraindo texto do PDF<span class="loading-dots"></span>
        <div class="detail" id="detail-extract"></div>
      </div>
    </div>
    <div class="loading-step" id="step-parse">
      <div class="icon">&#9881;</div>
      <div class="text">
        Identificando lancamentos e contas<span class="loading-dots"></span>
        <div class="detail" id="detail-parse"></div>
      </div>
    </div>
    <div class="loading-step" id="step-slice">
      <div class="icon">&#128197;</div>
      <div class="text">
        Fatiando lancamentos por mes<span class="loading-dots"></span>
        <div class="detail" id="detail-slice"></div>
      </div>
    </div>
    <div class="loading-step" id="step-compare">
      <div class="icon">&#8644;</div>
      <div class="text">
        Comparando periodos e detectando anomalias<span class="loading-dots"></span>
        <div class="detail" id="detail-compare"></div>
      </div>
    </div>
    <div class="loading-step" id="step-report">
      <div class="icon">&#9998;</div>
      <div class="text">
        Gerando relatorio final<span class="loading-dots"></span>
        <div class="detail" id="detail-report"></div>
      </div>
    </div>
  </div>
  <div class="loading-footer">
    Analise gerada por Inteligencia Artificial. Os resultados sao indicativos e devem ser validados pelo usuario.
  </div>
</div>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-md-8">
      <div class="card">
        <div class="card-body">
          <h4 class="m-0">Analise de Prestacao de Contas por Cleanalyze IA</h4>
          <p class="card-text text-secondary">
            Envie um PDF de Prestacao de Contas com periodo anual (12 meses).
            Nossa IA fatia automaticamente por mes e gera comparativos detalhados, analise de padroes e deteccao de anomalias.
          </p>

          <form action="web/executar_prestacao_anual.php" method="post" enctype="multipart/form-data" id="formAnual">
            <div class="upload-zone mb-3" id="zone-anual">
              <label for="pdf_anual">
                <span style="font-size:2.5rem;">📊</span><br>
                <strong>PDF Anual</strong><br>
                <small class="text-muted">Prestacao de Contas com 12 meses de lancamentos</small>
              </label>
              <input type="file" name="pdf_anual" id="pdf_anual" accept="application/pdf" required>
              <div class="file-name" id="name-anual"></div>
            </div>

            <input type="text" name="saidaBase" class="form-control mb-3"
                   placeholder="Nome do arquivo de saida (opcional)">

            <button class="btn btn-primary btn-lg w-100 mb-3" type="submit" id="btnSubmit">
              Analisar com Cleanalyze IA
            </button>

            <p class="text-muted text-center mb-0" style="font-size:.78rem;">
              Analise gerada por Inteligencia Artificial. Os resultados sao indicativos e devem ser validados pelo usuario.
            </p>
          </form>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-body">
          <h6>O que essa analise faz</h6>
          <ul class="small text-secondary mb-0">
            <li><strong>Mesmo mes ano anterior vs atual</strong>: Compara Mar/2025 com Mar/2026, por exemplo</li>
            <li><strong>Mes anterior vs atual</strong>: Compara Fev/2026 com Mar/2026</li>
            <li><strong>Padrao mensal</strong>: Identifica contas e subcategorias recorrentes, ausentes e anomalas</li>
            <li><strong>Fundo de Reserva</strong>: Alerta se houver debitos no mes atual</li>
            <li><strong>Visao panoramica</strong>: Totais por conta em cada mes</li>
          </ul>
        </div>
      </div>

      <!-- Tutorial: como extrair o relatorio -->
      <div class="card mt-3">
        <div class="card-header" style="background:#0664e4; color:#fff;">
          <strong>Como extrair o relatorio correto</strong>
        </div>
        <div class="card-body">
          <ol class="small text-secondary mb-3">
            <li>Acessar o menu <strong>Prestacao de Contas &gt; Prestacao de Contas</strong> no Ahreas.</li>
            <li>Informar o condominio desejado <strong>(apenas 1)</strong>.</li>
            <li>Informar periodo de <strong>1 ano</strong> desejado (Exemplo: Data Inicial do Mes Atual do Ano Passado ate Data Final do Mes Atual do Ano Atual).</li>
            <li>Selecionar a Finalidade <strong>Conferencia</strong>.</li>
            <li>Selecionar Checkbox de Relatorios <strong>01</strong> e <strong>02</strong>.</li>
            <li>Clicar em <strong>"Mais Opcoes"</strong>, e selecionar a modalidade "Arquivos de saidas (PDF)" como <strong>"Arquivo unico por condominio"</strong>.</li>
            <li><strong>Importar no Cleanalyze</strong> para analise da IA.</li>
          </ol>
          <div class="text-center">
            <img src="assets/img/exemplo_prestcontas.png" alt="Exemplo de configuracao do relatorio de Prestacao de Contas"
                 class="img-fluid rounded border" style="max-width:100%; box-shadow:0 2px 8px rgba(0,0,0,.12);">
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<footer class="text-center text-muted my-4" style="font-size:.8rem;">
  <div>Analise gerada por Inteligencia Artificial. Os resultados sao indicativos e devem ser validados pelo usuario.</div>
  <div class="mt-1">2025 &copy; Desenvolvimento BBZ.</div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('pdf_anual').addEventListener('change', function() {
  var file = this.files[0];
  var nameEl = document.getElementById('name-anual');
  var zone = document.getElementById('zone-anual');
  if (file) {
    var sizeMb = (file.size / 1024 / 1024).toFixed(1);
    nameEl.textContent = file.name + ' (' + sizeMb + ' MB)';
    nameEl.style.color = '#2e7d32';
    nameEl.style.fontWeight = '600';
    zone.style.borderColor = '#548235';
    zone.style.borderStyle = 'solid';
  } else {
    nameEl.textContent = '';
    zone.style.borderColor = '';
    zone.style.borderStyle = 'dashed';
  }
});

// Loading animation — usa fetch para manter o loading visível
document.getElementById('formAnual').addEventListener('submit', function(e) {
  e.preventDefault(); // Impedir submit normal

  var form = this;
  var btn = document.getElementById('btnSubmit');
  btn.disabled = true;

  var overlay = document.getElementById('loadingOverlay');
  overlay.classList.add('active');

  var fileInput = document.getElementById('pdf_anual');
  var fileName = fileInput.files[0] ? fileInput.files[0].name : 'PDF';
  var fileSize = fileInput.files[0]
    ? (fileInput.files[0].size / 1024 / 1024).toFixed(1) + ' MB' : '';

  // Steps com timing mais agressivo
  var steps = [
    { id: 'step-upload', delay: 0, detail: null, doneText: 'PDF enviado (' + fileSize + ')' },
    { id: 'step-extract', delay: 1200, detailId: 'detail-extract', detail: 'Lendo ' + fileName, doneText: 'Texto extraido com sucesso' },
    { id: 'step-parse', delay: 3000, detailId: 'detail-parse',
      details: [
        { at: 0, text: 'Identificando secoes do condominio...' },
        { at: 1200, text: 'Mapeando contas e subcategorias...' },
        { at: 2400, text: 'Classificando lancamentos...' },
      ],
      doneText: 'Lancamentos identificados'
    },
    { id: 'step-slice', delay: 7000, detailId: 'detail-slice', detail: 'Separando lancamentos por mes...', doneText: '12 meses fatiados' },
    { id: 'step-compare', delay: 9000, detailId: 'detail-compare',
      details: [
        { at: 0, text: 'Comparando mesmo mes do ano anterior...' },
        { at: 1500, text: 'Comparando com mes anterior...' },
        { at: 3000, text: 'Analisando padrao mensal e recorrencia...' },
        { at: 4500, text: 'Verificando Fundo de Reserva...' },
      ],
      doneText: 'Anomalias e padroes detectados'
    },
    { id: 'step-report', delay: 15000, detailId: 'detail-report', detail: 'Montando relatorio detalhado...', doneText: 'Relatorio pronto!' },
  ];

  var stepTimers = [];

  function activateStep(stepIndex) {
    var step = steps[stepIndex];
    var el = document.getElementById(step.id);

    // Marcar anteriores como done
    for (var i = 0; i < stepIndex; i++) {
      var prev = steps[i];
      var prevEl = document.getElementById(prev.id);
      if (!prevEl.classList.contains('done')) {
        prevEl.classList.remove('active');
        prevEl.classList.add('done');
        prevEl.querySelector('.icon').innerHTML = '&#10003;';
        var dots = prevEl.querySelector('.loading-dots');
        if (dots) dots.style.display = 'none';
        if (prev.doneText) {
          var d = prevEl.querySelector('.detail');
          if (d) d.textContent = prev.doneText;
        }
      }
    }

    el.classList.add('active');

    if (step.detail && step.detailId) {
      document.getElementById(step.detailId).textContent = step.detail;
    }
    if (step.details && step.detailId) {
      step.details.forEach(function(d) {
        var t = setTimeout(function() {
          if (el.classList.contains('active')) {
            document.getElementById(step.detailId).textContent = d.text;
          }
        }, d.at);
        stepTimers.push(t);
      });
    }
  }

  // Agendar steps
  steps.forEach(function(step, idx) {
    var t = setTimeout(function() { activateStep(idx); }, step.delay);
    stepTimers.push(t);
  });

  // Enviar via fetch — a pagina NAO navega, o loading continua visivel
  var formData = new FormData(form);

  fetch(form.action, {
    method: 'POST',
    body: formData
  })
  .then(function(response) { return response.text(); })
  .then(function(html) {
    // Marcar todos como done antes de navegar
    steps.forEach(function(s, idx) { activateStep(idx); });
    var lastStep = steps[steps.length - 1];
    var lastEl = document.getElementById(lastStep.id);
    lastEl.classList.remove('active');
    lastEl.classList.add('done');
    lastEl.querySelector('.icon').innerHTML = '&#10003;';
    var dots = lastEl.querySelector('.loading-dots');
    if (dots) dots.style.display = 'none';
    if (lastStep.doneText && lastStep.detailId) {
      document.getElementById(lastStep.detailId).textContent = lastStep.doneText;
    }

    // Pequena pausa para mostrar "Relatorio pronto!" antes de exibir resultado
    setTimeout(function() {
      document.open();
      document.write(html);
      document.close();
    }, 800);
  })
  .catch(function(err) {
    stepTimers.forEach(clearTimeout);
    overlay.classList.remove('active');
    btn.disabled = false;
    btn.textContent = 'Analisar com IA';
    alert('Erro ao processar: ' + err.message);
  });
});
</script>
</body>
</html>
