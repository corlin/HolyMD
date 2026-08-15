(function () {
  'use strict';
  var input = document.getElementById('site-search');
  var results = document.getElementById('search-results');
  if (!input || !results) return;
  var field = input.closest('.search-field');
  if (field) field.removeAttribute('hidden');
  input.removeAttribute('hidden');

  var index = null;
  fetch('search-index.json')
    .then(function (response) {
      if (!response.ok) throw new Error('search index unavailable');
      return response.json();
    })
    .then(function (data) {
      index = data.articles || [];
      initLoadMore();
    })
    .catch(function () {
      input.setAttribute('hidden', '');
      if (field) field.setAttribute('hidden', '');
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
      var basePath = (document.querySelector('a.wordmark') ? document.querySelector('a.wordmark').getAttribute('href') : '').replace(/\/$/, '');
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
        || normalize(article.topics.join(' ')).indexOf(query) !== -1
        || normalize(article.text).indexOf(query) !== -1;
    });
    results.textContent = '';
    if (hits.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'muted';
      empty.textContent = 'No matching articles.';
      results.appendChild(empty);
    } else {
      var basePath = (document.querySelector('a.wordmark') ? document.querySelector('a.wordmark').getAttribute('href') : '').replace(/\/$/, '');
      hits.slice(0, 12).forEach(function (article) {
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
        row.appendChild(body);
        results.appendChild(row);
      });
    }
    results.removeAttribute('hidden');
  });
})();
