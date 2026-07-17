<?php
// Variables consumed by this partial (set before including):
//   $title     (string) — page title
//   $extraHead (string) — raw HTML injected before </head> (optional)
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Lernmonitor') ?></title>
    <link rel="stylesheet" href="<?= Config::asset('/css/colors.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/style.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/buttons.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/panels.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/icons.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/menus.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/charts.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/notifications.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/tables.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/admin-dashboard.css') ?>">
    <link rel="stylesheet" href="<?= Config::asset('/css/settings.css') ?>">
    <?php
    $_gc     = function_exists('settings') ? settings()->get('graduation_config', null) : null;
    $_gcLvls = (is_array($_gc) && isset($_gc['levels']) && is_array($_gc['levels']) && count($_gc['levels']) > 0)
               ? $_gc['levels']
               : ["Neustarter", "Starter", "Durchstarter", "Lernprofi"];
    $_gcName = (is_array($_gc) && isset($_gc['category_name']) && is_string($_gc['category_name']) && $_gc['category_name'] !== '')
               ? $_gc['category_name']
               : "Abschlussstufe";
    ?>
    <script>window.graduationConfig = <?= json_encode(['categoryName' => $_gcName, 'levels' => $_gcLvls]) ?>;</script>
    <script>window.defaultRoom = <?= json_encode(function_exists('settings') ? (settings()->get('default_room', '') ?: '') : '') ?>;</script>
    <script src="<?= Config::asset('/js/student-database.js') ?>"></script>
    <script>const assetV = '<?= Config::ASSET_VERSION ?>';</script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function syncFilterBtns() {
                document.querySelectorAll('.filter-add-btn').forEach(function(btn) {
                    btn.style.width = btn.offsetHeight + 'px';
                });
            }
            syncFilterBtns();
            window.addEventListener('resize', syncFilterBtns);
        });
    </script>
    <?= $extraHead ?? '' ?>
</head>
