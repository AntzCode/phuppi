<?php

if (!empty($messages)) {

    $messagesFlat = [];

    foreach ($messages as $_messages) {
        if (is_array($_messages)) {
            $messagesFlat = array_merge($messagesFlat, $_messages);
        } else if (is_string($_messages)) {
            $messagesFlat[] = $_messages;
        }
    }

    $componentId = md5(json_encode($messages));

    switch ($type) {
        case 'success':
            $color = $color ?? 'green';
            $attitude = 'positive';
            $icon = $icon ?? 'check';
            $header = $header ?? 'Success!';
            break;
        case 'error':
            $color = $color ?? 'red';
            $attitude = 'negative';
            $icon = $icon ?? 'exclamation';
            $header = $header ?? 'Error!';
            break;
        default:
            $color = $color ?? 'gray';
            $attitude = 'neutral';
            $icon = $icon ?? 'info';
            $header = $header ?? 'Info!';
            break;
    }
?>
    <div class="ui container">

        <div class="ui attached padded segment" id="component_messages_<?= $componentId ?>">

            <div class="ui icon <?= $attitude ?> message" style="position: relative">

                <label class="ui corner label <?= $color ?> clickable" onclick="(() => {document.getElementById('component_messages_<?= $componentId ?>').remove()})()"><i class="close icon"></i></label>
                <i class="icon <?= $icon ?> circle"></i>

                <div class="content">
                    <div class="header">
                        <?= $header ?>
                    </div>
                    <ul class="list">
                        <?php foreach ($messagesFlat as $message) { ?>
                            <li><?= $message ?></li>
                        <?php } ?>
                    </ul>
                </div>

            </div>

        </div>

    </div>

<?php }
