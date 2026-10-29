<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** @var array $pageScripts  @var bool $useCharts */
$pageScripts = $pageScripts ?? [];
$useCharts   = $useCharts ?? false;
?>
<?php if (!$authLayout): ?>
</main>

<?php if (current_user()): ?>
<nav class="bottom-nav d-lg-none">
  <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
    <a class="<?= $activeNav === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>">
      <i class="bi <?= $icon ?>"></i><span><?= e($label) ?></span>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($useCharts): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<?php endif; ?>
<script src="<?= e(url('assets/js/app.js')) ?>?v=<?= APP_VERSION ?>"></script>
<?php foreach ($pageScripts as $s): ?>
<script src="<?= e(url('assets/js/' . $s)) ?>?v=<?= APP_VERSION ?>"></script>
<?php endforeach; ?>
</body>
</html>
