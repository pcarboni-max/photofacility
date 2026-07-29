<?php

declare(strict_types=1);

/**
 * UI di visualizzazione (Fase 2). Home (card per giorno) + pagina giorno.
 * Sta dietro la basic auth del webserver (nessun login applicativo).
 *
 *   index.php            → home, prima pagina
 *   index.php?page=N     → home, pagina N
 *   index.php?day=Y-m-d  → pagina del giorno
 */

use PhotoFacility\Web\Gallery;
use PhotoFacility\Web\Guard;

$g = Gallery::boot(dirname(__DIR__));
Guard::enforce($g->config);

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$env = $h($g->config->appEnvName);

$renderHeader = static function () use ($env): void {
    echo '<header class="topbar"><span class="env">' . $env . '</span>'
        . '<a class="home" href="index.php">torna alla home</a></header>';
};

header('Content-Type: text/html; charset=utf-8');
$day = isset($_GET['day']) ? (string) $_GET['day'] : null;

?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $env ?> — <?= $day ? $h($day) : 'Home' ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php $renderHeader(); ?>
<main>
<?php if ($day !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)):

    $items = $g->dayItems($day);
    ?>
    <h1><?= $h($day) ?> <span class="muted">· <?= count($items) ?> foto</span></h1>
    <?php if ($items === []): ?>
      <p class="muted">Nessuna foto per questo giorno.</p>
    <?php else: ?>
    <div class="grid">
      <?php foreach ($items as $i => $it): ?>
        <button class="cell" data-i="<?= $i ?>" title="<?= $h($it['name']) ?>">
          <?php if ($it['thumb']): ?>
            <img loading="lazy" src="<?= $h($it['thumb']) ?>" alt="<?= $h($it['name']) ?>">
          <?php else: ?>
            <span class="ph"><small><?= $h((string) $it['placeholder']) ?></small></span>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
    <script>window.__ITEMS__ = <?= json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <?php endif; ?>

<?php else:

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $data = $g->home($page);
    ?>
    <?php if ($data['days'] === []): ?>
      <p class="muted">Nessuna foto caricata finora.</p>
    <?php else: ?>
      <?php foreach ($data['days'] as $card): $d = $h($card['date']); ?>
        <a class="card" href="index.php?day=<?= $d ?>">
          <div class="card-head"><h2><?= $d ?></h2><span class="muted"><?= (int) $card['count'] ?> foto</span></div>
          <div class="strip">
            <?php foreach ($card['thumbs'] as $t): $id = (int) $t['id']; $ts = (string) ($t['thumb_status'] ?? 'PENDING'); ?>
              <?php if ($ts === 'READY'): ?>
                <img loading="lazy" src="media.php?id=<?= $id ?>&size=thumb" alt="">
              <?php else: ?>
                <span class="ph small"><?= $ts === 'SKIPPED' ? 'RAW' : '…' ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <span class="cta">Apri il giorno →</span>
        </a>
      <?php endforeach; ?>

      <nav class="pager">
        <?php if ($data['page'] > 1): ?><a href="index.php?page=<?= $data['page'] - 1 ?>">← Precedente</a><?php endif; ?>
        <span>Pagina <?= $data['page'] ?> di <?= $data['pages'] ?></span>
        <?php if ($data['page'] < $data['pages']): ?><a href="index.php?page=<?= $data['page'] + 1 ?>">Successiva →</a><?php endif; ?>
      </nav>
    <?php endif; ?>

<?php endif; ?>
</main>

<!-- Lightbox -->
<div id="lb" class="lb" hidden>
  <button class="lb-close" aria-label="Chiudi">✕</button>
  <button class="lb-prev" aria-label="Precedente">‹</button>
  <button class="lb-next" aria-label="Successiva">›</button>
  <figure class="lb-fig">
    <div class="lb-imgwrap"></div>
    <figcaption class="lb-meta"></figcaption>
  </figure>
</div>
<script src="assets/app.js"></script>
</body>
</html>
