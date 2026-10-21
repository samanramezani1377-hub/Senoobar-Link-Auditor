(() => {
  'use strict';
  const startButton = document.getElementById('sla-rescan');
  if (!startButton || !window.SLAConfig) return;

  const cancelButton = document.getElementById('sla-cancel');
  const status = document.getElementById('sla-status');
  const progressWrap = document.getElementById('sla-progress-wrap');
  const progress = document.getElementById('sla-progress');
  const progressLabel = document.getElementById('sla-progress-label');
  const errorBox = document.getElementById('sla-error');
  let activeScan = null;
  let busy = false;
  let cancelRequested = false;

  const brokenButton = document.getElementById('sla-check-broken');

  async function copyTable(tableId, button) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const text = Array.from(table.querySelectorAll('tr')).map(row =>
      Array.from(row.querySelectorAll('th,td')).map(cell => cell.innerText.trim().replace(/\s+/g, ' ')).join('\t')
    ).join('\n');
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
      else {
        const field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        const copied = document.execCommand('copy');
        field.remove();
        if (!copied) throw new Error('copy failed');
      }
      const previous = button.textContent;
      button.textContent = SLAConfig.strings.copyDone;
      button.disabled = true;
      // Restore the label on the next user interaction; no background timer is used.
      button.dataset.previousLabel = previous;
    } catch (_) {
      showError('کپی خودکار انجام نشد؛ متن جدول را انتخاب و کپی کنید.');
    }
  }

  document.querySelectorAll('.sla-copy-report').forEach(button => {
    button.addEventListener('click', () => copyTable(button.dataset.copy, button));
    button.addEventListener('blur', () => {
      if (button.dataset.previousLabel) {
        button.textContent = button.dataset.previousLabel;
        button.disabled = false;
        delete button.dataset.previousLabel;
      }
    });
  });

  if (brokenButton) {
    brokenButton.addEventListener('click', async () => {
      if (busy || !window.confirm('بررسی دستی مقصد لینک‌ها شروع شود؟ این کار ممکن است با توجه به تعداد لینک‌ها زمان ببرد.')) return;
      brokenButton.disabled = true;
      status.textContent = SLAConfig.strings.checking;
      errorBox.hidden = true;
      try {
        let done = false;
        let checked = 0;
        while (!done) {
          const result = await request('sla_check_broken_batch');
          checked += Number(result.checked || 0);
          done = Boolean(result.done);
          status.textContent = SLAConfig.strings.checking + ' (' + checked + ')';
          if (!result.checked && !done) throw new Error('پیشرفت بررسی متوقف شد.');
        }
        status.textContent = SLAConfig.strings.checkDone + ' (' + checked + ')';
        window.location.reload();
      } catch (error) {
        showError(error.message || SLAConfig.strings.failed);
        brokenButton.disabled = false;
      }
    });
  }


  async function request(action, payload = {}) {
    const body = new URLSearchParams({ action, nonce: SLAConfig.nonce, ...payload });
    const response = await fetch(SLAConfig.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body
    });
    const json = await response.json();
    if (!response.ok || !json.success) {
      throw new Error(json?.data?.message || SLAConfig.strings.failed);
    }
    return json.data;
  }

  function setProgress(processed, total, percent) {
    progress.value = percent;
    progressLabel.textContent = percent + '% (' + processed + ' از ' + total + ')';
  }

  function showError(message) {
    errorBox.hidden = false;
    errorBox.querySelector('p').textContent = message;
  }

  function finish(message) {
    busy = false;
    activeScan = null;
    cancelRequested = false;
    cancelButton.hidden = true;
    cancelButton.disabled = false;
    startButton.disabled = false;
    status.textContent = message;
  }

  async function runBatches() {
    try {
      while (activeScan && busy) {
        if (cancelRequested) {
          await request('sla_cancel_scan', {
            scanId: String(activeScan.scanId),
            token: activeScan.token
          });
          finish(SLAConfig.strings.cancelled);
          return;
        }

        // Each bounded request completes before the next request is sent.
        const result = await request('sla_scan_batch', {
          scanId: String(activeScan.scanId),
          token: activeScan.token
        });
        setProgress(result.processed, result.total, result.percent);

        if (result.done) {
          finish(SLAConfig.strings.done);
          window.location.reload();
          return;
        }
      }
    } catch (error) {
      finish(cancelRequested ? SLAConfig.strings.cancelled : SLAConfig.strings.failed);
      if (!cancelRequested) showError(error.message || SLAConfig.strings.failed);
    }
  }

  startButton.addEventListener('click', async () => {
    if (busy || !window.confirm(SLAConfig.strings.confirm)) return;
    errorBox.hidden = true;
    startButton.disabled = true;
    status.textContent = SLAConfig.strings.starting;
    progressWrap.hidden = false;
    progress.value = 0;
    progressLabel.textContent = '0%';
    try {
      const result = await request('sla_start_scan');
      activeScan = { scanId: result.scanId, token: result.token };
      busy = true;
      cancelRequested = false;
      cancelButton.hidden = false;
      status.textContent = SLAConfig.strings.working;
      setProgress(result.processed, result.total, 0);
      await runBatches();
    } catch (error) {
      finish(SLAConfig.strings.failed);
      showError(error.message || SLAConfig.strings.failed);
    }
  });

  cancelButton.addEventListener('click', () => {
    if (!activeScan || !busy) return;
    cancelRequested = true;
    cancelButton.disabled = true;
    status.textContent = 'در حال لغو بررسی پس از پایان درخواست جاری…';
  });
})();