(() => {
  const studio = document.querySelector('.studio');
  const base = (studio ? studio.dataset.basePath : '') || '';
  let geoApi = null;
  if (studio) {
    const body = document.querySelector('#markdown-body');
    const title = document.querySelector('#article-title');
    const date = document.querySelector('#article-date');
    const token = document.querySelector('#csrf-token');
    const preview = document.querySelector('#markdown-preview');
    const state = document.querySelector('#save-state');
    const previewUrl=base + '/admin/markdown/preview';
    const publicationForm = document.querySelector('[data-publication-form]');
    const publicationChecksum = document.querySelector('[data-publication-checksum]');
    const editorPanel = document.querySelector('.editor-panel');
    const previewPanel = document.querySelector('.preview-panel');
    let saveTimer;
    let previewTimer;
    let cursorSyncTimer;
    let scrollEndTimer;
    let previewVersion = 0;
    let currentChecksum = studio.dataset.articleChecksum;
    let dirty = false;
    let saveInFlight = null;
    let isSyncingScroll = false;
    let isUserScrolling = false;

    const saveIcons = {saved: 'check_circle', saving: 'sync', unsaved: 'edit_note', error: 'error'};
    const saveIcon = state.querySelector('[data-save-icon]');
    const saveLabel = state.querySelector('[data-save-label]');
    const setState = (value, label) => {
      state.dataset.state = value;
      if (saveIcon) saveIcon.textContent = saveIcons[value] || 'circle';
      if (saveLabel) saveLabel.textContent = label;
      else state.textContent = label;
    };

    const syncPreviewScroll = () => {
      if (window.innerWidth <= 1100 || !previewPanel) return;
      isUserScrolling = true;
      clearTimeout(scrollEndTimer);
      scrollEndTimer = setTimeout(() => { isUserScrolling = false; }, 200);

      const maxBodyScroll = body.scrollHeight - body.clientHeight;
      if (maxBodyScroll > 0) {
        const ratio = body.scrollTop / maxBodyScroll;
        const maxPreview = previewPanel.scrollHeight - previewPanel.clientHeight;
        if (maxPreview > 0) {
          isSyncingScroll = true;
          previewPanel.scrollTop = ratio * maxPreview;
          requestAnimationFrame(() => { isSyncingScroll = false; });
        }
      } else if (editorPanel) {
        const maxEditorScroll = editorPanel.scrollHeight - editorPanel.clientHeight;
        if (maxEditorScroll > 0) {
          const ratio = editorPanel.scrollTop / maxEditorScroll;
          const maxPreview = previewPanel.scrollHeight - previewPanel.clientHeight;
          if (maxPreview > 0) {
            isSyncingScroll = true;
            previewPanel.scrollTop = ratio * maxPreview;
            requestAnimationFrame(() => { isSyncingScroll = false; });
          }
        }
      }
    };

    const syncCursorToPreview = () => {
      if (window.innerWidth <= 1100 || isSyncingScroll || isUserScrolling || !previewPanel) return;
      const cursorPos = body.selectionStart;
      if (typeof cursorPos !== 'number') return;
      const textBefore = body.value.slice(0, cursorPos);
      const linesBefore = textBefore.split('\n');

      let targetHeadingText = null;
      for (let i = linesBefore.length - 1; i >= 0; i--) {
        const line = linesBefore[i].trim();
        const match = line.match(/^#{1,6}\s+(.+)$/);
        if (match) {
          targetHeadingText = match[1].trim();
          break;
        }
      }

      if (targetHeadingText) {
        const headings = Array.from(preview.querySelectorAll('h1, h2, h3, h4, h5, h6'));
        const target = headings.find(h => {
          const text = h.textContent.trim();
          return text === targetHeadingText || text.includes(targetHeadingText) || targetHeadingText.includes(text);
        });
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'center' });
          return;
        }
      }

      const paragraphsBefore = linesBefore.filter(l => l.trim() !== '').length;
      const totalParagraphs = body.value.split('\n').filter(l => l.trim() !== '').length;
      const blocks = Array.from(preview.children).filter(el => el.tagName !== 'SCRIPT' && el.tagName !== 'STYLE');
      if (blocks.length > 0 && totalParagraphs > 0) {
        const targetIndex = Math.min(blocks.length - 1, Math.max(0, Math.floor((paragraphsBefore / totalParagraphs) * blocks.length)));
        if (blocks[targetIndex]) {
          blocks[targetIndex].scrollIntoView({ behavior: 'smooth', block: 'center' });
          return;
        }
      }

      const totalLines = body.value.split('\n').length;
      if (totalLines > 1) {
        const lineRatio = linesBefore.length / totalLines;
        const maxPreview = previewPanel.scrollHeight - previewPanel.clientHeight;
        previewPanel.scrollTo({ top: lineRatio * maxPreview, behavior: 'smooth' });
      }
    };

    const queueCursorSync = () => {
      clearTimeout(cursorSyncTimer);
      cursorSyncTimer = setTimeout(syncCursorToPreview, 150);
    };

    const renderPreview = async () => {
      const version = ++previewVersion;
      preview.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch(previewUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({body: body.value, csrf_token: token.value}),
        });
        const payload = await response.json();
        if (!response.ok) throw Error(payload.error || 'Preview failed');
        if (version === previewVersion) {
          preview.innerHTML = payload.html;
          syncPreviewScroll();
        }
      } catch (error) {
        if (version === previewVersion) preview.textContent = error.message || 'Preview failed';
      } finally {
        if (version === previewVersion) preview.removeAttribute('aria-busy');
      }
    };

    const queuePreview = () => {
      clearTimeout(previewTimer);
      previewTimer = setTimeout(renderPreview, 120);
      queueCursorSync();
    };

    body.addEventListener('scroll', syncPreviewScroll, { passive: true });
    if (editorPanel) editorPanel.addEventListener('scroll', syncPreviewScroll, { passive: true });
    body.addEventListener('keyup', queueCursorSync);
    body.addEventListener('click', queueCursorSync);

    const metadataFields = () => [...document.querySelectorAll('[data-metadata-input]')].reduce((fields, field) => ({...fields, [field.name]: field.value}), {});

    const save = async () => {
      if (saveInFlight) return saveInFlight;
      if (!dirty) return true;
      const snapshot = {title: title.value, date: date.value, body: body.value, ...metadataFields()};
      dirty = false;
      setState('saving', 'Saving…');
      saveInFlight = (async () => {
        const response = await fetch(studio.dataset.autosaveUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({...snapshot, expected_checksum: currentChecksum, csrf_token: token.value}),
        });
        const payload = await response.json();
        if (!response.ok) {
          throw Error(payload.error || 'Save failed');
        }
        currentChecksum = payload.checksum;
        studio.dataset.articleChecksum = currentChecksum;
        if (publicationChecksum) publicationChecksum.value = currentChecksum;
        if (!dirty) setState('saved', 'Source saved');
        return true;
      })();
      try {
        return await saveInFlight;
      } catch (error) {
        dirty = true;
        setState('error', error.message || 'Save failed');
        throw error;
      } finally {
        saveInFlight = null;
        if (dirty && state.dataset.state !== 'error') void save().catch(() => {});
      }
    };

    const flushSave = async () => {
      clearTimeout(saveTimer);
      while (dirty || saveInFlight) {
        if (saveInFlight) await saveInFlight;
        else await save();
      }
    };

    const listen = field => field.addEventListener('input', () => {
      queuePreview();
      dirty = true;
      setState('unsaved', 'Unsaved changes');
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => void save().catch(() => {}), 800);
    });
    [body, title, date].forEach(listen);
    document.querySelectorAll('[data-metadata-input]').forEach(listen);

    if (publicationForm) publicationForm.addEventListener('submit', async event => {
      if (publicationForm.dataset.submitting === 'true') return;
      event.preventDefault();
      try {
        await flushSave();
        publicationForm.dataset.submitting = 'true';
        HTMLFormElement.prototype.submit.call(publicationForm);
      } catch (error) {
        setState('error', error.message || 'Save failed; publication was cancelled.');
      }
    });

    window.addEventListener('beforeunload', event => {
      if (!dirty && !saveInFlight) return;
      event.preventDefault();
      event.returnValue = '';
    });
    renderPreview();
    geoApi = {save, flushSave, checksum: () => currentChecksum};
  }

  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-copy]');
    if (!button || button.dataset.copying) return;
    button.dataset.copying = 'true';
    const originalHtml = button.innerHTML;
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
      button.textContent = 'Copied';
    } catch {
      button.textContent = 'Copy failed';
    }
    setTimeout(() => {
      button.innerHTML = originalHtml;
      delete button.dataset.copying;
    }, 2000);
  });

  const panel = document.querySelector('[data-geo-panel]');
  if (!panel || !geoApi) return;
  const slug = panel.dataset.articleSlug;
  const csrf = panel.querySelector('[data-geo-csrf]')?.value || '';
  const status = panel.querySelector('[data-geo-review-status]');
  const reviewButton = panel.querySelector('[data-geo-review]');

  const request = async (path, data = {}) => {
    const response = await fetch(path, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({...data, csrf_token: csrf}),
    });
    const payload = await response.json();
    if (!response.ok) throw Error(payload.error || 'GEO request failed');
    return payload;
  };

  const FIELD_MAP = {
    summary: 'summary',
    entities: 'entities',
    faq_candidates: 'faq',
    alt_text: 'alt_text',
    sources: 'sources',
    internal_links: 'internal_links',
    structured_data: 'structured_data',
  };

  const fillValue = proposal => {
    if (proposal.type === 'hierarchy') return null;
    const value = proposal.value;
    if (typeof proposal.value === 'string') return value;
    if (Array.isArray(value) && value.every(item => typeof item === 'string')) return value.join('\n');
    return JSON.stringify(value, null, 2);
  };

  const applyProposalsToInputs = async proposals => {
    if (!Array.isArray(proposals)) return;
    let anyFilled = false;
    proposals.forEach(proposal => {
      const fieldName = FIELD_MAP[proposal.type];
      if (!fieldName) return;
      const input = document.querySelector(`[data-metadata-input][name="${fieldName}"], [data-geo-field="${fieldName}"] textarea, [data-meta-field="${fieldName}"] textarea`);
      if (input && input.value.trim() === '') {
        const val = fillValue(proposal);
        if (val !== null) {
          input.value = val;
          input.dispatchEvent(new Event('input', {bubbles: true}));
          anyFilled = true;
        }
      }
    });
    const catchAll = panel.querySelector('[data-geo-catchall]');
    if (catchAll) catchAll.hidden = true;
    if (anyFilled) {
      await geoApi.flushSave();
    }
  };

  const sleep = ms => new Promise(res => setTimeout(res, ms));

  const poll = async () => {
    if (reviewButton) reviewButton.disabled = true;
    for (let attempt = 0; attempt < 60; attempt++) {
      const response = await fetch(`${base}/admin/articles/${slug}/geo/review`);
      const payload = await response.json();
      if (!response.ok) throw Error(payload.error || 'GEO status failed');
      if (payload.status === 'completed') {
        await applyProposalsToInputs(payload.proposals);
        if (status) status.textContent = '✨ 智能元数据已自动补齐';
        if (reviewButton) {
          reviewButton.disabled = false;
          reviewButton.innerHTML = '<span class="icon" aria-hidden="true">refresh</span>智能补全';
        }
        return;
      }
      if (payload.status === 'failed') {
        if (reviewButton) {
          reviewButton.disabled = false;
          reviewButton.textContent = 'Retry GEO review';
        }
        throw Error(payload.failure || 'GEO review failed');
      }
      if (status) {
        status.textContent = payload.status === 'running' ? 'GEO review running…' : 'GEO review queued — waiting for Cron worker…';
      }
      if (reviewButton) reviewButton.textContent = 'Refresh GEO status';
      await sleep(Math.min(10000, 2000 + attempt * 500));
    }
    if (reviewButton) {
      reviewButton.disabled = false;
      reviewButton.textContent = 'Refresh GEO status';
    }
  };

  if (reviewButton) {
    reviewButton.onclick = async () => {
      try {
        if (reviewButton.textContent === 'Refresh GEO status') {
          await poll();
          return;
        }
        reviewButton.disabled = true;
        if (status) status.textContent = '正在发起分析…';
        const payload = await request(`${base}/admin/articles/${slug}/geo/review`);
        if (payload.queued) {
          await poll();
        } else if (payload.proposals) {
          await applyProposalsToInputs(payload.proposals);
          if (status) status.textContent = '✨ 智能元数据已自动补齐';
          reviewButton.disabled = false;
        }
      } catch (error) {
        if (status) status.textContent = error.message;
        reviewButton.disabled = false;
      }
    };
  }

  const resumeStatus = async () => {
    try {
      const response = await fetch(`${base}/admin/articles/${slug}/geo/review`);
      const payload = await response.json();
      if (!response.ok) return;
      if (payload.status === 'completed' && Array.isArray(payload.proposals)) {
        await applyProposalsToInputs(payload.proposals);
        if (status) status.textContent = '✨ 智能元数据已就绪';
      } else if (payload.status === 'queued' || payload.status === 'running') {
        await poll();
      }
    } catch {
      // Ignore background check failure
    }
  };

  resumeStatus();
})();
