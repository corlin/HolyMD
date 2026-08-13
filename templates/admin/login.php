<?php

declare(strict_types=1);

/** @var ?string $error */
ob_start();
?>
<main class="login-shell">
  <section class="login-card">
    <p class="eyebrow">HolyMD</p>
    <h1>Administrator sign in</h1>
    <?php if ($error !== null): ?>
      <p class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/login">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <label>Email <input type="email" name="email" autocomplete="username" required></label>
      <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
      <button type="submit"><span class="icon" aria-hidden="true">login</span>Sign in</button>
    </form>
  </section>
</main>
<?php
$content = (string) ob_get_clean();
$title = 'Sign in';
require __DIR__ . '/layout.php';
