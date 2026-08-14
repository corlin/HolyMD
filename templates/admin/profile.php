<?php
declare(strict_types=1);
require __DIR__ . '/_base.php';
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$activeNav = 'profile';
?>
<main class="admin-shell">
<?php require __DIR__ . '/_nav.php'; ?>
<section class="article-index">
  <p class="eyebrow">Account</p>
  <h1>Administrator profile</h1>
  <p class="muted">Update your display name, email address, or password.</p>
  <?php if (!empty($error)): ?>
    <p class="login-error" role="alert"><?= $escape($error) ?></p>
  <?php endif; ?>
  <?php if (!empty($success)): ?>
    <p class="status status-published" style="padding:8px 12px;font-size:13.5px;margin:12px 0;" role="status"><span class="icon" aria-hidden="true">check_circle</span><?= $escape($success) ?></p>
  <?php endif; ?>
  <form class="new-article-form" method="post" action="<?= $path('/admin/profile') ?>">
    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
    <label>Display name
      <input type="text" name="display_name" value="<?= $escape($displayName) ?>" required autocomplete="name">
    </label>
    <label>Email address
      <input type="email" name="email" value="<?= $escape($email) ?>" required autocomplete="email">
    </label>
    <fieldset style="margin:24px 0 0;padding:16px;border:1px solid var(--line);background:#fffdf9;border-radius:4px;">
      <legend style="font-weight:700;padding:0 6px;color:var(--blue);">Change password (optional)</legend>
      <p class="muted" style="margin:0 0 12px;font-size:12.5px;">Leave blank if you don't want to change your password.</p>
      <label>Current password
        <input type="password" name="current_password" autocomplete="current-password">
      </label>
      <label>New password
        <input type="password" name="new_password" autocomplete="new-password" minlength="12">
      </label>
      <label>Confirm new password
        <input type="password" name="confirm_password" autocomplete="new-password" minlength="12">
      </label>
    </fieldset>
    <button type="submit" style="margin-top:20px;"><span class="icon" aria-hidden="true">save</span>Save changes</button>
  </form>
</section>
</main>
<?php
$content = (string) ob_get_clean();
$title = 'Profile';
require __DIR__ . '/layout.php';
?>
