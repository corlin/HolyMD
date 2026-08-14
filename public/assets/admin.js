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
    let saveTimer;
    let previewTimer;
    let previewVersion = 0;
    let currentChecksum = studio.dataset.articleChecksum;
    let dirty = false;
    let saveInFlight = null;

    const saveIcons = {saved: 'check_circle', saving: 'sync', unsaved: 'edit_note', error: 'error'};
    const saveIcon = state.querySelector('[data-save-icon]');
    const saveLabel = state.querySelector('[data-save-label]');
    const setState = (value, label) => {
      state.dataset.state = value;
      if (saveIcon) saveIcon.textContent = saveIcons[value] || 'circle';
      if (saveLabel) saveLabel.textContent = label;
      else state.textContent = label;
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
        if (version === previewVersion) preview.innerHTML = payload.html;
      } catch (error) {
        if (version === previewVersion) preview.textContent = error.message || 'Preview failed';
      } finally {
        if (version === previewVersion) preview.removeAttribute('aria-busy');
      }
    };

    const queuePreview = () => {
      clearTimeout(previewTimer);
      previewTimer = setTimeout(renderPreview, 120);
    };

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
    if (!button) return;
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
      button.textContent = 'Copied';
    } catch {
      button.textContent = 'Copy failed';
    }
  });

  const panel = document.querySelector('[data-geo-panel]');
  if (!panel || !geoApi) return;
  const slug = panel.dataset.articleSlug;
  const csrf = panel.querySelector('[data-geo-csrf]').value;
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

  const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const isWebUrl = value => { try { const parsed = new URL(value); return parsed.protocol === 'http:' || parsed.protocol === 'https:'; } catch { return false; } };
  const FIELD_BY_TYPE = {summary: 'summary', entities: 'entities', faq_candidates: 'faq', sources: 'sources', internal_links: 'internal_links', alt_text: 'alt_text', hierarchy: 'hierarchy', structured_data: 'structured_data'};

  const isUntargeted = proposal => {
    if (proposal.type === 'hierarchy') return true;
    if (proposal.type === 'sources') return typeof proposal.value === 'string' && !isWebUrl(proposal.value);
    if (proposal.type === 'structured_data') {
      if (typeof proposal.value === 'string') { try { return !isObject(JSON.parse(proposal.value)); } catch { return true; } }
      return !isObject(proposal.value);
    }
    return false;
  };

  const proposalCard = proposal => {
    const item = document.createElement('li');
    item.className = 'geo-proposal-card' + (proposal.status === 'accepted' ? ' is-accepted' : (proposal.status === 'rejected' ? ' is-rejected' : ''));
    item.dataset.proposalId = proposal.id;
    item.dataset.proposalValue = typeof proposal.value === 'string' ? proposal.value : JSON.stringify(proposal.value, null, 2);
    item._geoProposal = proposal;

    const header = document.createElement('div');
    header.className = 'geo-card-header';
    const tag = document.createElement('span');
    tag.className = 'geo-type-tag';
    tag.textContent = proposal.type;
    header.append(tag);

    if (proposal.status === 'accepted' || proposal.status === 'rejected') {
      const statusPill = document.createElement('span');
      statusPill.className = 'geo-status-pill ' + proposal.status;
      statusPill.textContent = proposal.status === 'accepted' ? 'Accepted ✓' : 'Rejected';
      header.append(statusPill);
    }
    item.append(header);

    const output = document.createElement('output');
    output.className = 'geo-card-body';
    output.dataset.geoDiff = '';
    output.textContent = typeof proposal.value === 'string' ? proposal.value : JSON.stringify(proposal.value, null, 2);
    item.append(output);

    if (proposal.status !== 'accepted' && proposal.status !== 'rejected') {
      const actions = document.createElement('div');
      actions.className = 'geo-card-actions';
      const fillable = proposal.type === 'metadata' || !isUntargeted(proposal);
      (fillable ? ['accept', 'reject', 'edit'] : ['reject']).forEach(action => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.action = action;
        button.className = 'btn-geo-' + action;
        button.textContent = action === 'accept' ? (proposal.type === 'metadata' ? 'Fill fields & accept' : 'Fill & accept') : (action === 'reject' ? 'Reject' : 'Edit');
        actions.append(button);
      });
      item.append(actions);
    }
    return item;
  };

  const renderProposals = proposals => {
    document.querySelectorAll('[data-geo-field]').forEach(container => {
      const listEl = container.querySelector('[data-geo-suggestions]');
      if (listEl) listEl.replaceChildren();
    });
    const catchAll = panel.querySelector('[data-geo-catchall]');
    const catchAllList = catchAll ? catchAll.querySelector('[data-geo-catchall-list]') : null;
    if (catchAllList) catchAllList.replaceChildren();
    proposals.forEach(proposal => {
      const field = FIELD_BY_TYPE[proposal.type];
      const target = field && !isUntargeted(proposal) ? document.querySelector(`[data-geo-field="${field}"] [data-geo-suggestions]`) : null;
      if (target) target.append(proposalCard(proposal));
      else if (catchAllList) catchAllList.append(proposalCard(proposal));
    });
    if (catchAll) catchAll.hidden = !catchAllList || catchAllList.childElementCount === 0;
  };

  const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
  const poll = async () => {
    reviewButton.disabled = true;
    for (let attempt = 0; attempt < 60; attempt++) {
      const response = await fetch(`${base}/admin/articles/${slug}/geo/review`);
      const payload = await response.json();
      if (!response.ok) throw Error(payload.error || 'GEO status failed');
      if (payload.status === 'completed') {
        renderProposals(payload.proposals);
        status.textContent = 'Review complete';
        reviewButton.disabled = false;
        reviewButton.textContent = 'Request GEO review';
        return;
      }
      if (payload.status === 'failed') {
        reviewButton.disabled = false;
        reviewButton.textContent = 'Retry GEO review';
        throw Error(payload.failure || 'GEO review failed');
      }
      status.textContent = payload.status === 'running' ? 'GEO review running…' : 'GEO review queued — waiting for Cron worker…';
      reviewButton.textContent = 'Refresh GEO status';
      await sleep(Math.min(15000, 2000 + attempt * 500));
    }
    reviewButton.disabled = false;
    reviewButton.textContent = 'Refresh GEO status';
    status.textContent = 'Review is still queued. You can leave this page and refresh its status later.';
  };

  reviewButton.onclick = async () => {
    try {
      if (reviewButton.textContent === 'Refresh GEO status') {
        await poll();
        return;
      }
      const payload = await request(`${base}/admin/articles/${slug}/geo/review`);
      if (payload.queued) {
        status.textContent = 'Review queued — waiting for Cron worker…';
        await poll();
      } else {
        renderProposals(payload.proposals);
        status.textContent = 'Review complete';
      }
    } catch (error) {
      status.textContent = error.message;
      reviewButton.disabled = false;
    }
  };

  const fillValue = value => {
    if (typeof value === 'string') return value;
    if (Array.isArray(value) && value.every(item => typeof item === 'string')) return value.join('\n');
    return JSON.stringify(value, null, 2);
  };

  const fillProposal = proposal => {
    const filled = [];
    const apply = (key, value) => {
      const field = document.querySelector(`[data-metadata-input][name="${key}"]`);
      if (!field) return false;
      field.value = fillValue(value);
      field.dispatchEvent(new Event('input', {bubbles: true}));
      filled.push(key);
      return true;
    };
    if (proposal.type === 'metadata' && isObject(proposal.value)) {
      for (const [key, value] of Object.entries(proposal.value)) apply(key, value);
    } else {
      const key = FIELD_BY_TYPE[proposal.type];
      if (key) apply(key, proposal.value);
    }
    return filled;
  };

  document.addEventListener('click', async event => {
    const button = event.target.closest('.geo-proposal-card button[data-action]');
    if (!button) return;
    const item = button.closest('[data-proposal-id]');
    if (!item) return;
    const id = item.dataset.proposalId;
    const action = button.dataset.action;

    if (action === 'edit') {
      const editor = document.createElement('div');
      editor.className = 'geo-edit-box';
      const textarea = document.createElement('textarea');
      textarea.className = 'geo-edit-input';
      textarea.value = item.dataset.proposalValue;
      const saveButton = document.createElement('button');
      saveButton.type = 'button';
      saveButton.className = 'btn-geo-edit';
      saveButton.textContent = 'Save';
      const cancelButton = document.createElement('button');
      cancelButton.type = 'button';
      cancelButton.className = 'btn-geo-reject';
      cancelButton.textContent = 'Cancel';
      editor.append(textarea, saveButton, cancelButton);
      item.append(editor);
      cancelButton.onclick = () => editor.remove();
      saveButton.onclick = async () => {
        try {
          const result = await request(`${base}/admin/geo/proposals/${id}/edit`, {value: textarea.value});
          item.dataset.proposalValue = typeof result.value === 'string' ? result.value : JSON.stringify(result.value, null, 2);
          item._geoProposal = {...item._geoProposal, value: result.value};
          const cardBody = item.querySelector('.geo-card-body');
          if (cardBody) cardBody.textContent = typeof result.value === 'string' ? result.value : JSON.stringify(result.value, null, 2);
          editor.remove();
          status.textContent = 'Proposal updated successfully.';
        } catch (error) {
          status.textContent = error.message;
        }
      };
      return;
    }

    if (action === 'accept') {
      const proposal = item._geoProposal;
      const filled = proposal ? fillProposal(proposal) : [];
      if (proposal && proposal.type === 'metadata' && filled.length === 0) {
        status.textContent = 'No editable metadata fields match this proposal.';
        return;
      }
      try {
        if (filled.length > 0) await geoApi.flushSave();
        await request(`${base}/admin/geo/proposals/${id}/accept`, {expected_checksum: geoApi.checksum()});
      } catch (error) {
        status.textContent = 'Proposal was not accepted: ' + (error.message || 'Save failed');
        return;
      }
      item.classList.add('is-accepted');
      const header = item.querySelector('.geo-card-header');
      if (header && !header.querySelector('.geo-status-pill')) {
        const pill = document.createElement('span');
        pill.className = 'geo-status-pill accepted';
        pill.textContent = 'Accepted ✓';
        header.append(pill);
      }
      const actions = item.querySelector('.geo-card-actions');
      if (actions) actions.remove();
      status.textContent = 'Proposal accepted and applied to article metadata.';
      return;
    }

    if (action === 'reject') {
      try {
        await request(`${base}/admin/geo/proposals/${id}/reject`);
      } catch (error) {
        status.textContent = error.message;
        return;
      }
      item.classList.add('is-rejected');
      const header = item.querySelector('.geo-card-header');
      if (header && !header.querySelector('.geo-status-pill')) {
        const pill = document.createElement('span');
        pill.className = 'geo-status-pill rejected';
        pill.textContent = 'Rejected';
        header.append(pill);
      }
      const actions = item.querySelector('.geo-card-actions');
      if (actions) actions.remove();
      status.textContent = 'Proposal rejected.';
    }
  });

  const resumeStatus = async () => {
    try {
      const response = await fetch(`${base}/admin/articles/${slug}/geo/review`);
      const payload = await response.json();
      if (!response.ok) return;
      if (payload.status === 'completed') {
        renderProposals(payload.proposals);
        status.textContent = 'Review complete';
        return;
      }
      if (payload.status === 'failed') {
        status.textContent = payload.failure || 'GEO review failed';
        reviewButton.textContent = 'Retry GEO review';
        return;
      }
      if (payload.status === 'queued' || payload.status === 'running') {
        status.textContent = payload.status === 'running' ? 'GEO review running…' : 'GEO review queued — waiting for Cron worker…';
        await poll();
      }
    } catch {
      status.textContent = 'GEO status can be refreshed later.';
    }
  };
  resumeStatus();
})();
