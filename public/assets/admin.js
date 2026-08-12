(() => {
  const studio = document.querySelector('.studio');
  if (studio) {
    const body = document.querySelector('#markdown-body');
    const title = document.querySelector('#article-title');
    const date = document.querySelector('#article-date');
    const token = document.querySelector('#csrf-token');
    const preview = document.querySelector('#markdown-preview');
    const state = document.querySelector('#save-state');
    const previewUrl='/admin/markdown/preview';
    let saveTimer;
    let previewTimer;
    let previewVersion = 0;

    const setState = (value, label) => {
      state.dataset.state = value;
      state.textContent = label;
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

    const save = async () => {
      setState('saving', 'Saving…');
      try {
        const response = await fetch(studio.dataset.autosaveUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({title: title.value, date: date.value, body: body.value, csrf_token: token.value}),
        });
        if (!response.ok) {
          const payload = await response.json();
          throw Error(payload.error || 'Save failed');
        }
        setState('saved', 'Saved draft');
      } catch (error) {
        setState('error', error.message || 'Save failed');
      }
    };

    [body, title, date].forEach(field => field.addEventListener('input', () => {
      queuePreview();
      setState('unsaved', 'Unsaved changes');
      clearTimeout(saveTimer);
      saveTimer = setTimeout(save, 800);
    }));
    renderPreview();
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
  if (!panel) return;
  const slug = panel.dataset.articleSlug;
  const csrf = panel.querySelector('[data-geo-csrf]').value;
  const status = panel.querySelector('[data-geo-review-status]');
  const list = panel.querySelector('[data-geo-proposals]');
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

  const renderProposals = proposals => list.replaceChildren(...proposals.map(proposal => {
    const item = document.createElement('li');
    item.className = 'geo-proposal-card';
    item.dataset.proposalId = proposal.id;
    item.dataset.proposalValue = JSON.stringify(proposal.value);

    const header = document.createElement('div');
    header.className = 'geo-card-header';
    const tag = document.createElement('span');
    tag.className = 'geo-type-tag';
    tag.textContent = proposal.type;
    header.append(tag);
    item.append(header);

    const output = document.createElement('output');
    output.className = 'geo-card-body';
    output.dataset.geoDiff = '';
    const valText = typeof proposal.value === 'string' ? proposal.value : JSON.stringify(proposal.value, null, 2);
    output.textContent = valText;
    item.append(output);

    const actions = document.createElement('div');
    actions.className = 'geo-card-actions';
    ['accept', 'reject', 'edit'].forEach(action => {
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.action = action;
      button.className = 'btn-geo-' + action;
      button.textContent = action === 'accept' ? 'Accept proposal' : (action === 'reject' ? 'Reject' : 'Edit');
      actions.append(button);
    });
    item.append(actions);
    return item;
  }));

  const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
  const poll = async () => {
    reviewButton.disabled = true;
    for (let attempt = 0; attempt < 60; attempt++) {
      const response = await fetch(`/admin/articles/${slug}/geo/review`);
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
      const payload = await request(`/admin/articles/${slug}/geo/review`);
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

  list.onclick = async event => {
    const button = event.target.closest('button');
    if (!button) return;
    const item = button.parentNode;
    const id = item.dataset.proposalId;
    try {
      const data = button.dataset.action === 'edit' ? {value: prompt('Metadata JSON', item.dataset.proposalValue) || ''} : {};
      const result = await request(`/admin/geo/proposals/${id}/${button.dataset.action}`, data);
      if (button.dataset.action === 'edit') item.dataset.proposalValue = JSON.stringify(result.value);
      button.disabled = true;
    } catch (error) {
      status.textContent = error.message;
    }
  };

  const resumeStatus = async () => {
    try {
      const response = await fetch(`/admin/articles/${slug}/geo/review`);
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
