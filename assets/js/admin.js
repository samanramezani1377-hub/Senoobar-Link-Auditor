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

  async function request(action, payload = {}) {
    const body = new URLSearchParams({
      action,
      nonce: SLAConfig.nonce,
      ...payload
    });
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

  async function runNextBatch() {
    if (!activeScan || !busy) return;
    try {
      const result = await request('sla_scan_batch', {
        scanId: String(activeScan.scanId),
        token: activeScan.token
      });
      setProgress(result.processed, result.total, result.percent);
      if (result.done) {
        busy = false;
        activeScan = null;
        cancelButton.hidden = true;
        startButton.disabled = false;
        status.textContent = SLAConfig.strings.done;
        window.location.reload();
        return;
      }
      // The next bounded request is issued only after the prior response.
      await runNextBatch();
    } catch (error) {
      busy = false;
      activeScan = null;
      cancelButton.hidden = true;
      startButton.disabled = false;
      status.textContent = SLAConfig.strings.failed;
      showError(error.message || SLAConfig.strings.failed);
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
      cancelButton.hidden = false;
      status.textContent = SLAConfig.strings.working;
      setProgress(result.processed, result.total, 0);
      await runNextBatch();
    } catch (error) {
      busy = false;
      startButton.disabled = false;
      status.textContent = SLAConfig.strings.failed;
      showError(error.message || SLAConfig.strings.failed);
    }
  });

  cancelButton.addEventListener('click', async () => {
    if (!activeScan || !busy) return;
    cancelButton.disabled = true;
    try {
      await request('sla_cancel_scan', {
        scanId: String(activeScan.scanId),
        token: activeScan.token
      });
      busy = false;
      activeScan = null;
      status.textContent = SLAConfig.strings.cancelled;
    } catch (error) {
      showError(error.message || SLAConfig.strings.failed);
    } finally {
      cancelButton.disabled = false;
      cancelButton.hidden = true;
      startButton.disabled = false;
    }
  });
})();