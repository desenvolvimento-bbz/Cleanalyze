<style>
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
  .loading-step .l-icon{
    width:28px; height:28px; border-radius:50%; display:flex;
    align-items:center; justify-content:center; font-size:.8rem;
    background:rgba(255,255,255,.1); flex-shrink:0;
  }
  .loading-step.active .l-icon{
    background:#0664e4; animation:l-pulse 1.2s infinite;
  }
  .loading-step.done .l-icon{ background:#2e7d32; }
  .loading-step .l-text{ font-size:.9rem; }
  .loading-step .l-text .l-detail{
    font-size:.75rem; color:rgba(255,255,255,.5); margin-top:2px;
  }
  .l-dots::after{ content:''; animation:l-dots 1.5s steps(4,end) infinite; }
  @keyframes l-dots{ 0%{content:''} 25%{content:'.'} 50%{content:'..'} 75%{content:'...'} }
  @keyframes l-pulse{ 0%,100%{transform:scale(1)} 50%{transform:scale(1.15)} }
  .loading-footer{
    position:absolute; bottom:30px; text-align:center;
    font-size:.75rem; opacity:.4;
  }
</style>

<script>
function initLoadingOverlay(formId, overlayId, stepsConfig) {
  var form = document.getElementById(formId);
  if (!form) return;

  form.addEventListener('submit', function(e) {
    e.preventDefault();

    var btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;

    var overlay = document.getElementById(overlayId);
    overlay.classList.add('active');

    var stepTimers = [];

    function activateStep(stepIndex) {
      var step = stepsConfig[stepIndex];
      var el = document.getElementById(step.id);

      for (var i = 0; i < stepIndex; i++) {
        var prev = stepsConfig[i];
        var prevEl = document.getElementById(prev.id);
        if (!prevEl.classList.contains('done')) {
          prevEl.classList.remove('active');
          prevEl.classList.add('done');
          prevEl.querySelector('.l-icon').innerHTML = '&#10003;';
          var dots = prevEl.querySelector('.l-dots');
          if (dots) dots.style.display = 'none';
          if (prev.doneText) {
            var d = prevEl.querySelector('.l-detail');
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

    stepsConfig.forEach(function(step, idx) {
      var t = setTimeout(function() { activateStep(idx); }, step.delay);
      stepTimers.push(t);
    });

    var formData = new FormData(form);

    fetch(form.action, { method: 'POST', body: formData })
    .then(function(response) { return response.text(); })
    .then(function(html) {
      stepsConfig.forEach(function(s, idx) { activateStep(idx); });
      var last = stepsConfig[stepsConfig.length - 1];
      var lastEl = document.getElementById(last.id);
      lastEl.classList.remove('active');
      lastEl.classList.add('done');
      lastEl.querySelector('.l-icon').innerHTML = '&#10003;';
      var dots = lastEl.querySelector('.l-dots');
      if (dots) dots.style.display = 'none';
      if (last.doneText && last.detailId) {
        document.getElementById(last.detailId).textContent = last.doneText;
      }

      setTimeout(function() {
        document.open();
        document.write(html);
        document.close();
      }, 600);
    })
    .catch(function(err) {
      stepTimers.forEach(clearTimeout);
      overlay.classList.remove('active');
      if (btn) { btn.disabled = false; }
      alert('Erro ao processar: ' + err.message);
    });
  });
}
</script>
