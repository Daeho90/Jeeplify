<?php
$hash = '';
if (!empty($_POST['pw'])) {
    $hash = password_hash($_POST['pw'], PASSWORD_BCRYPT);
}
?>
<form method="post" autocomplete="off">
  <input type="text" name="pw" placeholder="New password">
  <button>Make hash</button>
</form>
<?php if ($hash): ?>
<p style="word-break:break-all"><?= htmlspecialchars($hash) ?></p>
<?php endif; ?>