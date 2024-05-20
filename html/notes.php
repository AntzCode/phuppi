<?php

use Fuppi\ApiResponse;
use Fuppi\Note;
use Fuppi\User;
use Fuppi\UserPermission;
use Fuppi\Voucher;
use Fuppi\VoucherPermission;

require('fuppi.php');


if ($user->user_id <= 0) {
    redirect('/login.php?redirectAfterLogin=' . urlencode('/'));
}

$errors = [];

$profileUser = $user;

if (!empty($_GET['userId'])) {
    if ($user->hasPermission(UserPermission::USERS_READ)) {
        $profileUser = User::getOne($_GET['userId']) ?? $user;
    }
}

$editingNote = null;

if (!empty($_GET['noteId'])) {
    $editingNote = Note::getOne($_GET['noteId']);
    if (!_can_write_note($editingNote)) {
        fuppi_add_error_message('Not permitted to edit that note');
        redirect('/login.php?redirectAfterLogin=' . urlencode('/'));
    }
}


if (!empty($_POST)) {

    switch ($_POST['_method'] ?? 'post') {

        case 'post':
            switch ($_POST['_action'] ?? '') {


                case 'createSharableLink':
                    $apiResponse = new ApiResponse();
                    $validFor = (int) $_POST['validFor'];
                    if (!array_key_exists($validFor, $config->token_valid_for_options)) {
                        $apiResponse->throwException('Valid for "' . $validFor . '" is not a valid option');
                    }
                    $sharingNoteId = (int) $_POST['noteId'];
                    if ($sharingNote = Note::getOne($sharingNoteId)) {
                        if (!_can_read_note($sharingNote)) {
                            $apiResponse->throwException('Not permitted');
                        } else {
                            $token = $sharingNote->createToken($validFor);
                            $apiResponse->data = [
                                'token' => $token,
                                'url' => base_url() . '/note.php?id=' . $sharingNote->note_id . '&token=' . $token,
                                'expiresAt' => $sharingNote->getTokenExpiresAt($token)
                            ];
                            $apiResponse->sendResponse();
                        }
                    } else {
                        $apiResponse->throwException('Invalid file id "' . $uploadedFileId . '"');
                    }
                    break;

                case 'create':
                    $notes = $_POST['notes'];
                    if (!empty($notes)) {
                        if (!_can_write_note($editingNote)) {
                            $apiResponse->throwException('Not permitted');
                        } else {
                            if (is_null($editingNote)) {
                                // create a new note
                                $note = new Note();
                                $note->content = $notes;
                                if (empty($_POST['filename'])) {
                                    $note->filename = 'Note Created at ' . date('d M Y, H:i:s');
                                } else {
                                    $note->filename = $note->generateUniqueFilename($_POST['filename']);
                                }
                                $note->created_at = date('Y-m-d H:i:s');
                                $note->user_id = $profileUser->user_id;
                                $note->voucher_id = $app->getVoucher()->voucher_id ?? 0;
                                $note->save();
                            } else {
                                // edit an existing note
                                $editingNote->content = $notes;
                                $editingNote->updated_at = date('Y-m-d H:i:s');
                                $editingNote->voucher_id = $app->getVoucher()->voucher_id ?? 0;
                                $editingNote->save();
                            }
                        }
                        redirect('/notes.php');
                    }
                    break;
            }
            break;

        case 'delete':
            $noteId = $_POST['noteId'] ?? 0;
            $uploadedNote = Note::getOne($noteId);
            $noteUser = $uploadedNote->getUser();
            if (_can_delete_note($uploadedNote)) {
                Note::deleteOne($noteId);
            }
            redirect($_SERVER['REQUEST_URI']);
            break;
    }
}

$existingNotes = $user->getNotes();

?>

<h2>Welcome back, <?= $profileUser->username ?>!</h2>

<?php if ($user->hasPermission(UserPermission::NOTES_PUT)) { ?>

    <div class="ui segment <?= ($user->user_id !== $profileUser->user_id ? 'disabled' : '') ?> ">

        <div class="ui top attached label">
            <i class="pencil icon"></i>
            <label for="files">Add a new Note</label>
        </div>

        <form id="createNoteForm" disabled class="ui large form" action="<?= $_SERVER['REQUEST_URI'] ?>" method="post">
            <input type="hidden" name="_action" value="create" />

            <div class="field <?= (!empty($errors['filename'] ?? []) ? 'error' : '') ?>">
                <label for="filename">Filename: </label>
                <input id="filename" type="text" name="filename" value="<?= $_POST['filename'] ?? $editingNote?->filename ?? '' ?>" placeholder="<?= 'Note Created at ' . date('d M Y') ?>" />
            </div>

            <div class="field">
                <textarea name="notes"><?= $_POST['notes'] ?? $editingNote?->content ?? '' ?></textarea>
            </div>

            <div class="ui container right aligned">
                <button <?= ($user->user_id !== $profileUser->user_id ? 'disabled="disabled"' : '') ?> class="ui green right labeled icon submit button" type="submit"><i class="pencil icon right"></i> <?= $editingNote ? 'Save' : 'Create' ?> Note</button>
            </div>

        </form>

    </div>

<?php } ?>

<?php if ($user->hasPermission(UserPermission::NOTES_LIST)) { ?>

    <h2 class="title"><i class="pencil icon"></i> Your Existing Notes</h2></h2>

    <div class="ui segment">

        <?php if (empty($existingNotes)) { ?>

            <div class="ui content">
                <p>- Empty -</p>
            </div>

        <?php } else { ?>

            <div class="ui divided items">

                <?php foreach ($existingNotes as $existingNoteIndex => $existingNote) { ?>

                    <div class="ui item " style="position: relative">

                        <?php if ($user->hasPermission(UserPermission::NOTES_DELETE)) { ?>
                            <button class="red ui top right attached round label raised clickable-confirm" style="z-index: 1;" data-confirm="Are you sure you want to delete this note?" data-action="(e) => document.getElementById('deleteNoteForm<?= $existingNoteIndex ?>').submit()">
                                <i class="trash icon"></i> Delete
                            </button>
                        <?php } ?>

                        <?php if (_can_read_note($existingNote)) {  ?>

                            <div class="ui modal preview<?= $existingNoteIndex ?>">

                                <i class="close icon"></i>

                                <div class="header">
                                    Image Preview
                                </div>

                                <div class="image content">
                                    <img class="ui centered massive image" src="file.php?id=<?= $existingNote->note_id ?>&icon">
                                </div>
                                <div class="actions">
                                    <div class="ui positive right labeled icon button clickable" onclick="$('.share<?= $existingNoteIndex ?>').modal('show')">
                                        Share
                                        <i class="share icon"></i>
                                    </div>
                                </div>

                            </div>

                            <div class="ui modal share<?= $existingNoteIndex ?>">

                                <i class="close icon"></i>

                                <div class="header">
                                    Share Note
                                </div>

                                <div class="content ui items">

                                    <div class="item">

                                        <div class="image">
                                            <?php if (
                                                in_array($existingNote->mimetype, ['image/jpeg', 'image/png', 'image/giff'])
                                                && _can_read_note($existingNote)
                                            ) { ?>
                                                <img class="tiny rounded image" src="file.php?id=<?= $existingNote->note_id ?>&icon" />
                                            <?php } else if (empty("{$existingNote->extension}")) { ?>
                                                <img src="/assets/images/filetype-icons/unknown.png" />
                                            <?php } else { ?>
                                                <img src="/assets/images/filetype-icons/<?= $existingNote->extension ?>.png" />
                                            <?php } ?>
                                        </div>

                                        <div class="content">

                                            <div class="header">
                                                <?= $existingNote->filename ?>
                                            </div>

                                            <div class="meta">
                                                <?= _get_meta_content($existingNote) ?>
                                            </div>

                                            <div class="description">

                                                <select name="valid_for" id="validForDropdown<?= $existingNoteIndex ?>" style="display: none">
                                                    <?php foreach ($config->token_valid_for_options as $value => $title) {
                                                        echo '<option value="' . $value . '"' . (intval($_POST['valid_for'] ?? 0) === $value ? 'selected="selected"' : '') . '>' . $title . '</option>';
                                                    } ?>
                                                </select>

                                                <div class="ui labeled ticked slider attached" id="sharableLinkSlider<?= $existingNoteIndex ?>"></div>

                                                <script>
                                                    $('#sharableLinkSlider<?= $existingNoteIndex ?>')
                                                        .slider({
                                                            min: 0,
                                                            max: <?= count($config->token_valid_for_options) - 1 ?>,
                                                            start: <?= (array_search($_POST['valid_for'] ?? array_keys($config->token_valid_for_options)[0], array_keys($config->token_valid_for_options))) ?>,
                                                            autoAdjustLabels: true,
                                                            fireOnInit: true,
                                                            onChange: (v) => {
                                                                document.getElementById('validForDropdown<?= $existingNoteIndex ?>').selectedIndex = v;
                                                                let formData = new FormData();
                                                                formData.append('_action', 'createSharableLink');
                                                                formData.append('noteId', <?= $existingNote->note_id ?>);
                                                                formData.append('validFor', document.getElementById('validForDropdown<?= $existingNoteIndex ?>').options[document.getElementById('validForDropdown<?= $existingNoteIndex ?>').selectedIndex].value);
                                                                axios.post('<?= $_SERVER['REQUEST_URI'] ?>', formData).then((response) => {
                                                                    $('#sharableLink<?= $existingNoteIndex ?>').text(response.data.url);
                                                                    $('#sharableLinkCopyButton<?= $existingNoteIndex ?>').data('content', response.data.url);
                                                                    $('#expiresAt<?= $existingNoteIndex ?>').text(response.data.expiresAt);
                                                                }).catch((error) => {
                                                                    if (error.response && error.response.data && error.response.data.message) {
                                                                        alert(error.response.data.message);
                                                                    } else {
                                                                        console.log(error);
                                                                    }
                                                                });
                                                            },
                                                            interpretLabel: function(value) {
                                                                let _labels = JSON.parse('<?= json_encode(array_values($config->token_valid_for_options)) ?>');
                                                                return _labels[value];
                                                            }
                                                        });
                                                </script>

                                            </div>

                                            <div class="extra">
                                                <div>
                                                    <p class="ui label"><span id="sharableLink<?= $existingNoteIndex ?>"></span> <i id="sharableLinkCopyButton<?= $existingNoteIndex ?>" class="clickable copy icon copy-to-clipboard" data-content="" title="Copy to clipboard"></i></p>
                                                </div>
                                                <div>
                                                    <p class="">Expires at: <span class="ui label" id="expiresAt<?= $existingNoteIndex ?>"></span>
                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php } ?>

                        <div class="content">

                            <span class="header"><?= _get_firstline_content($existingNote) ?></span>

                            <div class="meta">
                                <?= _get_meta_content($existingNote) ?>
                            </div>

                            <?php if (_can_read_note($existingNote)) { ?>
                                <div class="extra">
                                    <div class="ui positive right labeled icon button clickable" onclick="$('.share<?= $existingNoteIndex ?>').modal('show')">
                                        Share
                                        <i class="share icon"></i>
                                    </div>

                                    <?php if (_can_write_note($existingNote)) { ?>
                                        <div class="ui primary right labeled icon button clickable" onclick="window.location = '/notes.php?noteId=<?= $existingNote->note_id ?>'">
                                            Edit
                                            <i class="edit icon"></i>
                                        </div>
                                    <?php } ?>


                                </div>
                            <?php } ?>

                        </div>

                        <div class="right ui grid middle aligned">

                            <div class="one wide column">

                                <form id="deleteNoteForm<?= $existingNoteIndex ?>" method="post" action="<?= $_SERVER['REQUEST_URI'] ?>">
                                    <input name="_method" type="hidden" value="delete" />
                                    <input type="hidden" name="noteId" value="<?= $existingNote->note_id ?>" />
                                </form>

                            </div>

                        </div>

                    </div>

                <?php } ?>

            </div>

        <?php } ?>

    </div>

<?php } ?>

<?php

function _get_firstline_content(Note $note)
{
    $firstLine = '';
    $words = explode(' ', $note->content);
    while (strlen($firstLine) < 50 && count($words) > 0) {
        $firstLine .= ' ' . array_shift($words);
    }
    if (count($words) > 0) {
        $firstLine .= '&hellip;';
    }
    return trim($firstLine);
}

function _get_meta_content(Note $note)
{
    ob_start();
?>
    <p><?= htmlentities($note->content) ?></p>
    <p><small>Created at <?= $note->created_at ?></small></p>
    <?php if ($note->updated_at) { ?>
        <p><small>Updated at <?= $note->updated_at ?></small></p>
    <?php } ?>

<?php
    $meta = ob_get_contents();
    ob_end_clean();
    return $meta;
}

function _can_read_note(Note $note)
{
    $app = \Fuppi\App::getInstance();
    $user = $app->getUser();

    if ($voucher = $app->getVoucher()) {
        if ($note->voucher_id === $voucher->voucher_id) {
            return true;
        }
        if ($user->hasPermission(VoucherPermission::NOTES_LIST_ALL)) {
            return true;
        }
    } else {
        return $user->hasPermission(UserPermission::NOTES_READ);
    }

    return false;
}

function _can_write_note(Note $note = null)
{
    $app = \Fuppi\App::getInstance();
    $user = $app->getUser();

    if (is_null($note)) {
        // wants to create a new note
        if ($voucher = $app->getVoucher()) {
            return $voucher->hasPermission(VoucherPermission::NOTES_PUT);
        } else {
            return $user->hasPermission(UserPermission::NOTES_PUT);
        }
    } else {
        // wants to edit a note
        if ($voucher = $app->getVoucher()) {
            // the user is a voucher user, only allowed if using the same voucher id or has voucher administrator permission
            return ($voucher->voucher_id === $note->voucher_id || $voucher->hasPermission(VoucherPermission::IS_ADMINISTRATOR));
        } else {
            // its a logged-in user, only allowed if same user or has administrator permissions
            if ($note->user_id === $user->user_id || $user->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
                return $user->hasPermission(UserPermission::NOTES_PUT);
            }
        }
    }

    // not permitted by default
    return false;
}

function _can_delete_note(Note $note)
{
    $app = \Fuppi\App::getInstance();
    $user = $app->getUser();

    // wants to edit a note
    if ($voucher = $app->getVoucher()) {
        // the user is a voucher user, only allowed if using the same voucher id or has voucher administrator permission
        return (($voucher->voucher_id === $note->voucher_id && $voucher->hasPermission(VoucherPermission::NOTES_DELETE)) || $voucher->hasPermission(VoucherPermission::IS_ADMINISTRATOR));
    } else {
        // its a logged-in user, only allowed if same user or has administrator permissions
        if ($note->user_id === $user->user_id || $user->hasPermission(UserPermission::IS_ADMINISTRATOR)) {
            return $user->hasPermission(UserPermission::NOTES_DELETE);
        }
    }
    
    // not permitted by default
    return false;
}
