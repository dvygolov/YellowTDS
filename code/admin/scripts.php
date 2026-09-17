<?php
require_once __DIR__ . '/../paths.php';
$jsFsPath = __DIR__ . '/js';
$jsPath = get_admin_url_path() . 'js';
$sortableTime = filemtime($jsFsPath . '/sortable.min.js');
?>
<script src="<?=$jsPath?>/jquery.js"></script>
<script src="<?=$jsPath?>/jquery.modal.min.js"></script>
<script src="<?=$jsPath?>/query-builder.standalone.min.js"></script>
<script src="<?=$jsPath?>/sortable.min.js?v=<?=$sortableTime?>"></script>
<script src="<?=$jsPath?>/flatpickr.js"></script>
<script src="<?=$jsPath?>/luxon.min.js"></script>
<script src="<?=$jsPath?>/xlsx.full.min.js"></script>
<script src="<?=$jsPath?>/jszip.min.js"></script>
<?php
$tTime = filemtime($jsFsPath . '/tabulator.js');
$cTime = filemtime($jsFsPath . '/campeditor.js');
$hTime = filemtime($jsFsPath . '/header.js'); 
$sTime = filemtime($jsFsPath . '/settings.js');
?>
<script src="<?=$jsPath?>/tabulator.js?v=<?=$tTime?>"></script>
<script src="<?=$jsPath?>/campeditor.js?v=<?=$cTime?>"></script>
<script src="<?=$jsPath?>/matrix-rain.js?v=<?= filemtime($jsFsPath . '/matrix-rain.js') ?>"></script>
<script src="<?=$jsPath?>/header.js?v=<?=$hTime?>"></script>
<script src="<?=$jsPath?>/settings.js?v=<?=$sTime?>"></script>
