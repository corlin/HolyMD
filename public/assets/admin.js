(() => {
  // Translations for the active admin language, injected by layout.php; English is the fallback.
  const i18n = (() => {
    try {
      const source = document.getElementById('holymd-i18n');
      return source ? JSON.parse(source.textContent) : {};
    } catch {
      return {};
    }
  })();
  const t = (text, params = {}) => Object.entries(params).reduce((result, [name, value]) => result.split(`{${name}}`).join(String(value)), i18n[text] || text);
  const studio = document.querySelector('.studio');
  const base = (studio ? studio.dataset.basePath : document.body?.dataset.basePath) || '';
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
        if (!response.ok) throw Error(payload.error || t('Preview failed'));
        if (version === previewVersion) {
          preview.innerHTML = payload.html;
          syncPreviewScroll();
        }
      } catch (error) {
        if (version === previewVersion) preview.textContent = error.message || t('Preview failed');
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
      setState('saving', t('Saving…'));
      saveInFlight = (async () => {
        const response = await fetch(studio.dataset.autosaveUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({...snapshot, expected_checksum: currentChecksum, csrf_token: token.value}),
        });
        const payload = await response.json();
        if (!response.ok) {
          throw Error(payload.error || t('Save failed'));
        }
        currentChecksum = payload.checksum;
        studio.dataset.articleChecksum = currentChecksum;
        if (publicationChecksum) publicationChecksum.value = currentChecksum;
        if (!dirty) setState('saved', t('Source saved'));
        return true;
      })();
      try {
        return await saveInFlight;
      } catch (error) {
        dirty = true;
        setState('error', error.message || t('Save failed'));
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

    const advancedBlock = document.querySelector('[data-advanced-geo-block]');
    const advancedBadge = document.querySelector('[data-advanced-geo-badge]');
    const updateAdvancedGeoBadge = () => {
      if (!advancedBlock || !advancedBadge) return;
      const inputs = advancedBlock.querySelectorAll('[data-metadata-input]');
      let count = 0;
      inputs.forEach(input => {
        if (input.value && input.value.trim() !== '') {
          count++;
        }
      });
      if (count > 0) {
        advancedBadge.textContent = t('{count} configured', {count});
        advancedBadge.hidden = false;
      } else {
        advancedBadge.hidden = true;
      }
    };

    let outlineTimer;
    const queueOutlineUpdate = () => {
      if (!outlineDrawer || outlineDrawer.hasAttribute('hidden')) return;
      clearTimeout(outlineTimer);
      outlineTimer = setTimeout(renderOutline, 150);
    };

    const listen = field => field.addEventListener('input', () => {
      queuePreview();
      queueOutlineUpdate();
      dirty = true;
      setState('unsaved', t('Unsaved changes'));
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => void save().catch(() => {}), 800);
      updateAdvancedGeoBadge();
    });
    [body, title, date].filter(Boolean).forEach(listen);
    document.querySelectorAll('[data-metadata-input]').forEach(listen);

    if (publicationForm) publicationForm.addEventListener('submit', async event => {
      if (publicationForm.dataset.submitting === 'true') return;
      event.preventDefault();
      try {
        await flushSave();
        publicationForm.dataset.submitting = 'true';
        HTMLFormElement.prototype.submit.call(publicationForm);
      } catch (error) {
        setState('error', error.message || t('Save failed; publication was cancelled.'));
      }
    });

    const uploadAndInsertImage = async file => {
      if (!file || !file.type.startsWith('image/')) return;
      const placeholder = `![${t('Uploading image…')}]()`;
      const start = body.selectionStart;
      const end = body.selectionEnd;
      const currentVal = body.value;
      body.value = currentVal.slice(0, start) + placeholder + currentVal.slice(end);
      body.selectionStart = body.selectionEnd = start + placeholder.length;
      body.dispatchEvent(new Event('input', {bubbles: true}));

      const formData = new FormData();
      formData.append('image', file);
      formData.append('csrf_token', token.value);
      try {
        const response = await fetch(`${base}/admin/media`, {
          method: 'POST',
          headers: {'Accept': 'application/json'},
          body: formData,
        });
        const data = await response.json();
        if (!response.ok || !data.status) {
          throw new Error(data.error || t('Image upload failed'));
        }
        const fileInfo = data.first || (data.files && data.files[0]) || {url: data.url, filename: file.name};
        const stem = file.name.replace(/\.[^.]+$/, '').replace(/[^a-zA-Z0-9_-]+/g, '-');
        const markdown = `![${stem}](${fileInfo.url})`;
        body.value = body.value.replace(placeholder, markdown);
        body.dispatchEvent(new Event('input', {bubbles: true}));
      } catch (err) {
        alert(err.message || t('Image upload failed'));
        body.value = body.value.replace(placeholder, '');
        body.dispatchEvent(new Event('input', {bubbles: true}));
      }
    };

    body.addEventListener('paste', event => {
      const items = event.clipboardData?.items;
      if (!items) return;
      for (const item of items) {
        if (item.type.startsWith('image/')) {
          const file = item.getAsFile();
          if (file) {
            event.preventDefault();
            void uploadAndInsertImage(file);
            return;
          }
        }
      }
    });

    body.addEventListener('dragover', event => {
      if (event.dataTransfer?.types?.includes('Files')) {
        event.preventDefault();
        body.style.outline = '2px dashed var(--blue)';
      }
    });

    const resetDrag = () => { body.style.outline = ''; };
    body.addEventListener('dragleave', resetDrag);
    body.addEventListener('drop', event => {
      resetDrag();
      const files = event.dataTransfer?.files;
      if (!files || files.length === 0) return;
      let handled = false;
      for (const file of files) {
        if (file.type.startsWith('image/')) {
          event.preventDefault();
          void uploadAndInsertImage(file);
          handled = true;
        }
      }
      if (handled) event.preventDefault();
    });

    const wrapSelection = (before, after, defaultText) => {
      const start = body.selectionStart;
      const end = body.selectionEnd;
      const text = body.value;
      const selected = text.slice(start, end) || defaultText;
      const replacement = before + selected + after;
      body.value = text.slice(0, start) + replacement + text.slice(end);
      body.selectionStart = start + before.length;
      body.selectionEnd = start + before.length + selected.length;
      body.focus();
      body.dispatchEvent(new Event('input', {bubbles: true}));
    };

    const handleTab = isShift => {
      const start = body.selectionStart;
      const end = body.selectionEnd;
      const text = body.value;
      if (start === end) {
        if (!isShift) {
          body.value = text.slice(0, start) + '  ' + text.slice(end);
          body.selectionStart = body.selectionEnd = start + 2;
          body.dispatchEvent(new Event('input', {bubbles: true}));
        }
        return;
      }
      const before = text.slice(0, start);
      const sel = text.slice(start, end);
      const after = text.slice(end);
      const lines = sel.split('\n');
      const modified = lines.map(line => {
        if (isShift) {
          return line.startsWith('  ') ? line.slice(2) : (line.startsWith(' ') ? line.slice(1) : line);
        }
        return '  ' + line;
      }).join('\n');
      body.value = before + modified + after;
      body.selectionStart = start;
      body.selectionEnd = start + modified.length;
      body.dispatchEvent(new Event('input', {bubbles: true}));
    };

    body.addEventListener('keydown', event => {
      const isMod = event.metaKey || event.ctrlKey;
      if (isMod && (event.key === 'b' || event.key === 'B')) {
        event.preventDefault();
        wrapSelection('**', '**', 'bold');
      } else if (isMod && (event.key === 'i' || event.key === 'I')) {
        event.preventDefault();
        wrapSelection('*', '*', 'italic');
      } else if (isMod && (event.key === 'k' || event.key === 'K')) {
        event.preventDefault();
        wrapSelection('[', '](url)', 'text');
      } else if (event.key === 'Tab') {
        event.preventDefault();
        handleTab(event.shiftKey);
      }
    });

    const mediaModal = document.querySelector('#media-modal');
    const openMediaBtn = document.querySelector('[data-open-media-modal]');
    const closeMediaBtn = document.querySelector('[data-close-media-modal]');
    const mediaListContainer = document.querySelector('[data-media-modal-list]');

    const loadMediaList = async () => {
      if (!mediaListContainer) return;
      try {
        const response = await fetch(`${base}/admin/media`, {
          headers: {'Accept': 'application/json'},
        });
        const data = await response.json();
        if (!response.ok || !Array.isArray(data.media)) {
          throw new Error(data.error || 'Failed to load media');
        }
        if (data.media.length === 0) {
          mediaListContainer.innerHTML = `<p class="muted">${t('No media files uploaded yet.')}</p>`;
          return;
        }
        mediaListContainer.innerHTML = '<div class="media-picker-grid"></div>';
        const grid = mediaListContainer.querySelector('.media-picker-grid');
        data.media.forEach(item => {
          const div = document.createElement('div');
          div.className = 'media-picker-item';
          div.title = item.filename;
          div.innerHTML = `<img src="${item.url}" alt="${item.filename}"><span class="media-picker-name">${item.filename}</span>`;
          div.onclick = () => {
            const stem = item.filename.replace(/\.[^.]+$/, '');
            const markdown = `![${stem}](${item.url})\n`;
            const curStart = body.selectionStart;
            const curEnd = body.selectionEnd;
            body.value = body.value.slice(0, curStart) + markdown + body.value.slice(curEnd);
            body.selectionStart = body.selectionEnd = curStart + markdown.length;
            body.focus();
            body.dispatchEvent(new Event('input', {bubbles: true}));
            mediaModal?.close();
          };
          grid.appendChild(div);
        });
      } catch (err) {
        mediaListContainer.innerHTML = `<p class="muted">${err.message || 'Failed to load media'}</p>`;
      }
    };

    if (openMediaBtn && mediaModal) {
      openMediaBtn.addEventListener('click', () => {
        mediaModal.showModal();
        void loadMediaList();
      });
      closeMediaBtn?.addEventListener('click', () => mediaModal.close());
      mediaModal.addEventListener('click', event => {
        if (event.target === mediaModal) mediaModal.close();
      });
    }

    const shareModal = document.querySelector('#share-modal');
    const openShareBtn = document.querySelector('[data-open-share-modal]');
    const closeShareBtn = document.querySelector('[data-close-share-modal]');
    const btnCreateShare = document.querySelector('#btn-create-share');
    const shareExpirySelect = document.querySelector('#share-expiry-select');
    const shareCreatedBox = document.querySelector('#share-created-box');
    const shareUrlInput = document.querySelector('#share-url-input');
    const btnCopyShare = document.querySelector('#btn-copy-share');
    const activeSharesContainer = document.querySelector('[data-active-shares-container]');
    const csrfToken = document.querySelector('#csrf-token')?.value || '';

    const loadSharesList = async () => {
      if (!activeSharesContainer) return;
      try {
        const response = await fetch(`${base}/admin/articles/${slug}/shares`);
        const data = await response.json();
        if (!response.ok || !Array.isArray(data.shares)) {
          throw new Error(data.error || 'Failed to load share links');
        }
        if (data.shares.length === 0) {
          activeSharesContainer.innerHTML = `<p class="muted">${t('No preview links generated yet.')}</p>`;
          return;
        }
        const ul = document.createElement('ul');
        ul.className = 'share-active-list';
        ul.style.listStyle = 'none';
        ul.style.padding = '0';
        ul.style.margin = '0';
        data.shares.forEach(item => {
          const li = document.createElement('li');
          li.style.display = 'flex';
          li.style.justifyContent = 'space-between';
          li.style.alignItems = 'center';
          li.style.padding = '8px 0';
          li.style.borderBottom = '1px solid var(--border-subtle)';
          li.style.fontSize = '0.85rem';

          const info = document.createElement('div');
          const tokenShort = item.token.slice(0, 8) + '…';
          let statusText = item.is_active ? t('Active') : (item.revoked_at ? t('Revoked') : t('Expired'));
          let expiryText = item.expires_at ? `${t('Expires')}: ${item.expires_at}` : t('Permanent');
          info.innerHTML = `<strong>${tokenShort}</strong> <span class="badge ${item.is_active ? 'badge-success' : 'badge-muted'}" style="margin-left:6px;font-size:0.75rem;padding:2px 6px;border-radius:4px;background:var(--surface-canvas);border:1px solid var(--border-subtle);">${statusText}</span><div class="muted" style="font-size:0.8rem;margin-top:2px;">${expiryText}</div>`;

          const actions = document.createElement('div');
          if (item.is_active) {
            const previewUrl = `${window.location.origin}${base}/preview/articles/${slug}?token=${item.token}`;
            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'button-link-secondary';
            copyBtn.title = t('Copy link');
            copyBtn.innerHTML = '<span class="icon" aria-hidden="true">content_copy</span>';
            copyBtn.onclick = async () => {
              await navigator.clipboard.writeText(previewUrl);
              copyBtn.innerHTML = '<span class="icon" aria-hidden="true">check</span>';
              setTimeout(() => { copyBtn.innerHTML = '<span class="icon" aria-hidden="true">content_copy</span>'; }, 2000);
            };

            const revokeBtn = document.createElement('button');
            revokeBtn.type = 'button';
            revokeBtn.className = 'button-link-secondary';
            revokeBtn.title = t('Revoke link');
            revokeBtn.style.color = '#d9534f';
            revokeBtn.innerHTML = '<span class="icon" aria-hidden="true">block</span>';
            revokeBtn.onclick = async () => {
              if (!confirm(t('Revoke this share link? Anyone using it will immediately lose access.'))) return;
              try {
                const res = await fetch(`${base}/admin/articles/${slug}/shares/revoke`, {
                  method: 'POST',
                  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                  body: new URLSearchParams({csrf_token: csrfToken, token: item.token}),
                });
                const revokeData = await res.json();
                if (!res.ok) throw new Error(revokeData.error || 'Failed to revoke');
                void loadSharesList();
              } catch (e) {
                alert(e.message);
              }
            };
            actions.style.display = 'flex';
            actions.style.gap = '8px';
            actions.appendChild(copyBtn);
            actions.appendChild(revokeBtn);
          }
          li.appendChild(info);
          li.appendChild(actions);
          ul.appendChild(li);
        });
        activeSharesContainer.innerHTML = '';
        activeSharesContainer.appendChild(ul);
      } catch (err) {
        activeSharesContainer.innerHTML = `<p class="muted">${err.message || 'Failed to load'}</p>`;
      }
    };

    if (openShareBtn && shareModal) {
      openShareBtn.addEventListener('click', () => {
        shareModal.showModal();
        if (shareCreatedBox) shareCreatedBox.style.display = 'none';
        void loadSharesList();
      });
      closeShareBtn?.addEventListener('click', () => shareModal.close());
      shareModal.addEventListener('click', event => {
        if (event.target === shareModal) shareModal.close();
      });

      btnCreateShare?.addEventListener('click', async () => {
        btnCreateShare.disabled = true;
        try {
          const expiryVal = shareExpirySelect?.value || '';
          const res = await fetch(`${base}/admin/articles/${slug}/shares`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({csrf_token: csrfToken, expires_in: expiryVal}),
          });
          const shareData = await res.json();
          if (!res.ok) throw new Error(shareData.error || 'Failed to create share');
          if (shareCreatedBox && shareUrlInput) {
            shareUrlInput.value = shareData.url;
            shareCreatedBox.style.display = 'block';
          }
          void loadSharesList();
        } catch (err) {
          alert(err.message || 'Failed to create share link');
        } finally {
          btnCreateShare.disabled = false;
        }
      });

      btnCopyShare?.addEventListener('click', async () => {
        if (!shareUrlInput) return;
        try {
          await navigator.clipboard.writeText(shareUrlInput.value);
          const orig = btnCopyShare.innerHTML;
          btnCopyShare.innerHTML = '<span class="icon" aria-hidden="true">check</span> ' + t('Copied');
          setTimeout(() => { btnCopyShare.innerHTML = orig; }, 2000);
        } catch {
          shareUrlInput.select();
        }
      });
    }

    const outlineDrawer = document.querySelector('#outline-drawer');
    const outlineContent = document.querySelector('[data-outline-content]');
    const outlineToggleBtn = document.querySelector('[data-toggle-outline]');
    const outlineCloseBtn = document.querySelector('[data-close-outline]');

    const parseHeadings = text => {
      const lines = text.split('\n');
      const headings = [];
      let inCodeBlock = false;
      let charOffset = 0;

      for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const trimmed = line.trim();
        if (trimmed.startsWith('```') || trimmed.startsWith('~~~')) {
          inCodeBlock = !inCodeBlock;
        } else if (!inCodeBlock) {
          const match = line.match(/^(#{1,6})\s+(.+)$/);
          if (match) {
            headings.push({
              level: match[1].length,
              text: match[2].trim(),
              lineIndex: i,
              charOffset: charOffset,
              lineLength: line.length,
            });
          }
        }
        charOffset += line.length + 1;
      }
      return headings;
    };

    const renderOutline = () => {
      if (!outlineContent || !outlineDrawer || outlineDrawer.hasAttribute('hidden')) return;
      const text = body ? body.value : '';
      const headings = parseHeadings(text);

      if (headings.length === 0) {
        outlineContent.innerHTML = `<p class="muted outline-empty">${t('No headings found. Use # and ## to organize sections.')}</p>`;
        return;
      }

      const list = document.createElement('ul');
      list.className = 'outline-list';

      headings.forEach((heading, idx) => {
        const li = document.createElement('li');
        li.className = 'outline-item';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `outline-link level-${heading.level}`;

        const tag = document.createElement('span');
        tag.className = 'outline-level-tag';
        tag.textContent = `H${heading.level}`;

        const txt = document.createElement('span');
        txt.className = 'outline-text';
        txt.textContent = heading.text;

        btn.appendChild(tag);
        btn.appendChild(txt);

        btn.addEventListener('click', () => {
          if (!body) return;
          body.focus();
          body.setSelectionRange(heading.charOffset, heading.charOffset + heading.lineLength);
          const lineHeight = 24;
          body.scrollTop = Math.max(0, (heading.lineIndex - 3) * lineHeight);

          if (preview) {
            const previewHeadings = preview.querySelectorAll('h1, h2, h3, h4, h5, h6');
            if (previewHeadings[idx]) {
              previewHeadings[idx].scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          }
        });

        li.appendChild(btn);
        list.appendChild(li);
      });

      outlineContent.innerHTML = '';
      outlineContent.appendChild(list);
    };

    const toggleOutline = forceOpen => {
      if (!outlineDrawer) return;
      const isHidden = outlineDrawer.hasAttribute('hidden');
      const open = forceOpen !== undefined ? forceOpen : isHidden;
      if (open) {
        outlineDrawer.removeAttribute('hidden');
        outlineToggleBtn?.classList.add('is-active');
        renderOutline();
      } else {
        outlineDrawer.setAttribute('hidden', '');
        outlineToggleBtn?.classList.remove('is-active');
      }
    };

    if (outlineToggleBtn) {
      outlineToggleBtn.addEventListener('click', () => toggleOutline());
    }
    if (outlineCloseBtn) {
      outlineCloseBtn.addEventListener('click', () => toggleOutline(false));
    }

    window.addEventListener('keydown', event => {
      const isMod = event.metaKey || event.ctrlKey;
      if (isMod && (event.key === 'o' || event.key === 'O')) {
        event.preventDefault();
        toggleOutline();
      } else if (event.key === 'Escape' && outlineDrawer && !outlineDrawer.hasAttribute('hidden')) {
        toggleOutline(false);
      }
    });

    window.addEventListener('beforeunload', event => {
      if (!dirty && !saveInFlight) return;
      event.preventDefault();
      event.returnValue = '';
    });
    renderPreview();
    updateAdvancedGeoBadge();
    geoApi = {save, flushSave, checksum: () => currentChecksum, updateBadge: updateAdvancedGeoBadge};
  }

  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-copy]');
    if (!button || button.dataset.copying) return;
    button.dataset.copying = 'true';
    const originalHtml = button.innerHTML;
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
      button.textContent = t('Copied');
    } catch {
      button.textContent = t('Copy failed');
    }
    setTimeout(() => {
      button.innerHTML = originalHtml;
      delete button.dataset.copying;
    }, 2000);
  });

  document.addEventListener('click', async event => {
    const btn = event.target.closest('[data-analyze-probe]');
    if (!btn || btn.disabled) return;
    const probeId = btn.dataset.analyzeProbe;
    const csrf = btn.dataset.csrf || document.querySelector('[data-geo-csrf]')?.value || document.querySelector('#csrf-token')?.value || '';
    const container = btn.closest('[data-gap-action-container]');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="icon" aria-hidden="true">sync</span> ' + t('Analyzing gap…');
    try {
      const response = await fetch(`${base}/admin/geo/probes/${probeId}/gap-analysis`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({csrf_token: csrf}),
      });
      const data = await response.json();
      if (!response.ok || !data.gap_analysis) {
        throw new Error(data.error || t('Gap analysis failed'));
      }
      if (container) {
        const details = document.createElement('details');
        details.className = 'geo-gap-details';
        details.open = true;
        const summary = document.createElement('summary');
        summary.innerHTML = '<span class="icon" aria-hidden="true">analytics</span> ' + t('View gap analysis');
        const content = document.createElement('div');
        content.className = 'geo-gap-content prose';
        const p = document.createElement('p');
        p.textContent = data.gap_analysis;
        content.innerHTML = p.innerHTML.replace(/\n/g, '<br>');
        details.appendChild(summary);
        details.appendChild(content);
        container.replaceWith(details);
      }
    } catch (err) {
      alert(err.message || t('Gap analysis failed'));
      btn.disabled = false;
      btn.innerHTML = originalHtml;
    }
  });

  document.addEventListener('click', event => {
    const applyBtn = event.target.closest('[data-link-markdown]');
    const editorBody = document.querySelector('#markdown-body');
    if (!applyBtn || !editorBody) return;
    const raw = applyBtn.dataset.linkMarkdown || '';
    const match = raw.match(/\[([^\]]+)\]\(([^)]+)\)/);
    const linkMd = match ? match[0] : raw;
    const phrase = match ? match[1] : '';

    const start = editorBody.selectionStart;
    const end = editorBody.selectionEnd;
    const val = editorBody.value;

    let applied = false;
    if (phrase && val.includes(phrase)) {
      const idx = val.indexOf(phrase);
      if (idx !== -1) {
        editorBody.value = val.slice(0, idx) + linkMd + val.slice(idx + phrase.length);
        editorBody.selectionStart = idx;
        editorBody.selectionEnd = idx + linkMd.length;
        applied = true;
      }
    }

    if (!applied) {
      editorBody.value = val.slice(0, start) + linkMd + val.slice(end);
      editorBody.selectionStart = editorBody.selectionEnd = start + linkMd.length;
    }

    editorBody.focus();
    editorBody.dispatchEvent(new Event('input', {bubbles: true}));
    applyBtn.disabled = true;
    applyBtn.innerHTML = '<span class="icon" aria-hidden="true">check</span> ' + t('Applied');
  });

  document.addEventListener('click', async event => {
    const btn = event.target.closest('[data-compare-version]');
    if (!btn) return;
    const version = btn.dataset.compareVersion;
    const slug = btn.dataset.slug;
    const container = document.querySelector(`[data-version-diff-container="${version}"]`);
    if (!container) return;
    if (!container.hidden) {
      container.hidden = true;
      return;
    }
    if (container.dataset.loaded === 'true') {
      container.hidden = false;
      return;
    }
    btn.disabled = true;
    try {
      const response = await fetch(`${base}/admin/articles/${slug}/versions/${version}/diff`);
      const data = await response.json();
      if (!response.ok || !data.diff_html) {
        throw new Error(data.error || t('Failed to load version diff'));
      }
      container.innerHTML = data.diff_html;
      container.dataset.loaded = 'true';
      container.hidden = false;
    } catch (err) {
      alert(err.message || t('Failed to load version diff'));
    } finally {
      btn.disabled = false;
    }
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
    const internalLinksProposal = proposals.find(p => p.type === 'internal_links');
    const linksBlock = panel.querySelector('[data-geo-internal-links]');
    const linksList = panel.querySelector('[data-geo-internal-links-list]');
    if (linksBlock && linksList && internalLinksProposal && Array.isArray(internalLinksProposal.value) && internalLinksProposal.value.length > 0) {
      linksList.innerHTML = '';
      internalLinksProposal.value.forEach(linkText => {
        const card = document.createElement('div');
        card.className = 'geo-internal-link-card';
        const span = document.createElement('span');
        span.className = 'geo-internal-link-text';
        span.textContent = linkText;
        const applyBtn = document.createElement('button');
        applyBtn.type = 'button';
        applyBtn.className = 'btn-geo-apply-link button-link-secondary';
        applyBtn.innerHTML = '<span class="icon" aria-hidden="true">add_link</span> ' + t('Apply link');
        applyBtn.dataset.linkMarkdown = linkText;
        card.appendChild(span);
        card.appendChild(applyBtn);
        linksList.appendChild(card);
      });
      linksBlock.hidden = false;
    }

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
      if (!response.ok) throw Error(payload.error || t('GEO status failed'));
      if (payload.status === 'completed') {
        await applyProposalsToInputs(payload.proposals);
        if (status) status.textContent = t('Metadata suggestions applied');
        if (reviewButton) {
          reviewButton.disabled = false;
          reviewButton.dataset.mode = '';
          reviewButton.innerHTML = '<span class="icon" aria-hidden="true">refresh</span>';
          reviewButton.append(t('Suggest metadata'));
        }
        return;
      }
      if (payload.status === 'failed') {
        if (reviewButton) {
          reviewButton.disabled = false;
          reviewButton.dataset.mode = '';
          reviewButton.textContent = t('Retry GEO review');
        }
        throw Error(payload.failure || t('GEO review failed'));
      }
      if (status) {
        status.textContent = payload.status === 'running' ? t('GEO review running…') : t('GEO review queued — waiting for Cron worker…');
      }
      if (reviewButton) {
        reviewButton.dataset.mode = 'refresh';
        reviewButton.textContent = t('Refresh GEO status');
      }
      await sleep(Math.min(10000, 2000 + attempt * 500));
    }
    if (reviewButton) {
      reviewButton.disabled = false;
      reviewButton.dataset.mode = 'refresh';
      reviewButton.textContent = t('Refresh GEO status');
    }
  };

  if (reviewButton) {
    reviewButton.onclick = async () => {
      try {
        if (reviewButton.dataset.mode === 'refresh') {
          await poll();
          return;
        }
        reviewButton.disabled = true;
        if (status) status.textContent = t('Starting analysis…');
        const payload = await request(`${base}/admin/articles/${slug}/geo/review`);
        if (payload.queued) {
          await poll();
        } else if (payload.proposals) {
          await applyProposalsToInputs(payload.proposals);
          if (status) status.textContent = t('Metadata suggestions applied');
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
        if (status) status.textContent = t('Metadata suggestions ready');
      } else if (payload.status === 'queued' || payload.status === 'running') {
        await poll();
      }
    } catch {
      // Ignore background check failure
    }
  };

  resumeStatus();
})();
