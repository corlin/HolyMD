<?php
declare(strict_types=1);
/**
 * Shared footer, theme toggle script and document close.
 *
 * @var ?string $activeNav 'writing' | 'about' | null — index omits the Writing footer link.
 */
?>
  <footer class="site-footer"><div class="shell"><p><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> — writing by <?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>.</p><nav aria-label="Footer"><?php if ($activeNav !== 'writing'): ?><a href="/">Writing</a><?php endif; ?><a href="/about/">About</a><a href="/rss.xml">RSS</a><a href="/feed.json">JSON Feed</a><?php if ($generateLlmsTxt): ?><a href="/llms.txt">llms.txt</a><a href="/llms-full.txt">llms-full.txt</a><?php endif; ?></nav></div></footer>
  <script>!function(){function s(t){document.querySelectorAll("[data-theme-set]").forEach(function(e){var a=e.getAttribute("data-theme-set")===t;e.classList.toggle("active",a),e.setAttribute("aria-pressed",a?"true":"false")})}var t=localStorage.getItem("holymd_theme");s("light"===t||"dark"===t?t:"auto"),document.addEventListener("click",function(e){var t=e.target.closest("[data-theme-set]");if(t){var a=t.getAttribute("data-theme-set");"auto"===a?(localStorage.removeItem("holymd_theme"),document.documentElement.removeAttribute("data-theme")):(localStorage.setItem("holymd_theme",a),document.documentElement.setAttribute("data-theme",a)),s(a)}})}();</script>
<?php if ($activeNav === 'writing'): ?>
  <script src="<?= htmlspecialchars($assetSearch, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
</body>
</html>
