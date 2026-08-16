(function () {
  'use strict';
  var input = document.getElementById('site-search');
  var results = document.getElementById('search-results');
  var trigger = document.getElementById('search-trigger');
  var dropdown = document.getElementById('search-dropdown');
  var closeBtn = document.getElementById('search-close');
  if (!input || !results) return;

  var wordmark = document.querySelector('a.wordmark');
  var basePath = (wordmark ? wordmark.getAttribute('href') : '').replace(/\/$/, '');
  var indexUrl = basePath + '/search-index.json';

  var field = input.closest('.search-field');
  if (field) field.removeAttribute('hidden');
  input.removeAttribute('hidden');

  var index = null;
  fetch(indexUrl)
    .then(function (response) {
      if (!response.ok) throw new Error('search index unavailable');
      return response.json();
    })
    .then(function (data) {
      index = data.articles || [];
      initLoadMore();
    })
    .catch(function () {
      if (trigger) trigger.style.display = 'none';
      if (dropdown) dropdown.setAttribute('hidden', '');
    });

  function openSearch() {
    if (!dropdown) return;
    dropdown.removeAttribute('hidden');
    if (trigger) trigger.setAttribute('aria-expanded', 'true');
    input.focus();
    input.select();
  }

  function closeSearch() {
    if (!dropdown) return;
    dropdown.setAttribute('hidden', '');
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
      trigger.focus();
    }
  }

  function toggleSearch() {
    if (!dropdown) return;
    if (dropdown.hasAttribute('hidden')) {
      openSearch();
    } else {
      closeSearch();
    }
  }

  if (trigger) {
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleSearch();
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      closeSearch();
    });
  }

  document.addEventListener('click', function (e) {
    if (dropdown && !dropdown.hasAttribute('hidden')) {
      if (!e.target.closest('.header-search-wrap')) {
        closeSearch();
      }
    }
  });

  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      toggleSearch();
      return;
    }
    if (e.key === 'Escape' && dropdown && !dropdown.hasAttribute('hidden')) {
      e.preventDefault();
      closeSearch();
      return;
    }
    if (e.key === '/' && dropdown && dropdown.hasAttribute('hidden')) {
      var activeEl = document.activeElement;
      var isInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.isContentEditable);
      if (!isInput) {
        e.preventDefault();
        openSearch();
      }
    }
  });

  function initLoadMore() {
    var loadMoreBtn = document.getElementById('load-more-button');
    var articlesContainer = document.getElementById('latest-articles');
    if (!loadMoreBtn || !articlesContainer || !index) return;
    var renderedCount = articlesContainer.querySelectorAll('.article-row').length + 1; // +1 for featured
    if (renderedCount >= index.length) {
      loadMoreBtn.style.display = 'none';
      return;
    }
    loadMoreBtn.addEventListener('click', function () {
      var nextBatch = index.slice(renderedCount, renderedCount + 10);
      nextBatch.forEach(function (article) {
        var row = document.createElement('article');
        row.className = 'article-row';
        var body = document.createElement('div');
        var kicker = document.createElement('p');
        kicker.className = 'article-kicker';
        var time = document.createElement('time');
        time.dateTime = article.date;
        time.textContent = article.date;
        kicker.appendChild(time);
        var heading = document.createElement('h3');
        var link = document.createElement('a');
        link.href = basePath + '/articles/' + encodeURIComponent(article.slug) + '/';
        link.textContent = article.title;
        heading.appendChild(link);
        body.appendChild(kicker);
        body.appendChild(heading);
        if (article.summary) {
          var summary = document.createElement('p');
          summary.textContent = article.summary;
          body.appendChild(summary);
        }
        var arrow = document.createElement('a');
        arrow.className = 'quiet-arrow';
        arrow.href = link.href;
        arrow.setAttribute('aria-label', 'Read ' + article.title);
        var arrowIcon = document.createElement('span');
        arrowIcon.className = 'icon';
        arrowIcon.setAttribute('aria-hidden', 'true');
        arrowIcon.textContent = 'arrow_forward';
        arrow.appendChild(arrowIcon);
        row.appendChild(body);
        row.appendChild(arrow);
        articlesContainer.appendChild(row);
      });
      renderedCount += nextBatch.length;
      if (renderedCount >= index.length) {
        loadMoreBtn.style.display = 'none';
      }
    });
  }

  var normalize = function (value) { return String(value || '').toLowerCase(); };

  input.addEventListener('input', function () {
    var query = normalize(input.value.trim());
    if (!index) return;
    if (query === '') {
      results.setAttribute('hidden', '');
      results.textContent = '';
      return;
    }
    var hits = index.filter(function (article) {
      return normalize(article.title).indexOf(query) !== -1
        || normalize(article.summary).indexOf(query) !== -1
        || normalize((article.topics || []).join(' ')).indexOf(query) !== -1
        || normalize(article.text).indexOf(query) !== -1;
    });
    results.textContent = '';
    if (hits.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'muted search-no-results';
      empty.textContent = 'No matching articles.';
      results.appendChild(empty);
    } else {
      hits.slice(0, 8).forEach(function (article) {
        var row = document.createElement('article');
        row.className = 'header-search-item';
        var heading = document.createElement('h4');
        var link = document.createElement('a');
        link.href = basePath + '/articles/' + encodeURIComponent(article.slug) + '/';
        link.textContent = article.title;
        heading.appendChild(link);
        row.appendChild(heading);
        if (article.summary) {
          var summary = document.createElement('p');
          summary.className = 'header-search-summary';
          summary.textContent = article.summary;
          row.appendChild(summary);
        }
        var meta = document.createElement('div');
        meta.className = 'header-search-meta';
        var time = document.createElement('time');
        time.dateTime = article.date;
        time.textContent = article.date;
        meta.appendChild(time);
        row.appendChild(meta);
        results.appendChild(row);
      });
    }
    results.removeAttribute('hidden');
  });
})();
