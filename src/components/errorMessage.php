<?php
if (!empty($errors)) {
    $errorsFlat = [];
    foreach ($errors as $_errors) {
        $errorsFlat = array_merge($errorsFlat, $_errors);
    }
?>
    <div class="ui icon negative message">
        <i class="icon exclamation circle"></i>
        <div class="content">
            <div class="header">
                Error<?= (count($errorsFlat) > 1 ? 's' : '') ?> enountered:
            </div>
            <ul class="list">
                <?php foreach ($errorsFlat as $errorMessage) { ?>
                    <li><?= $errorMessage ?></li>
                <?php } ?>
            </ul>
        </div>
    </div>
<?php }
