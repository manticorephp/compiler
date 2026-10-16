<h1><?= $title ?></h1>
<?php foreach ($items as $i => $it): ?>
  <li><?= $i ?>: <?= htmlspecialchars($it) ?></li>
<?php endforeach; ?>
<p><?= $this->tag() ?> <?= $missing ?? "none" ?></p>
