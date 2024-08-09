<?php
/**
 * v1.0.17 migration 1 : new feature: custom note name.
 */

// fetch the search results
$searchStatement = $pdo->prepare("SELECT `note_id`, `content` FROM `fuppi_notes` WHERE `filename` LIKE 'Note created at %'");
$upgradedCount = 0;
if ($searchResult = $searchStatement->execute()) {
    foreach ($searchStatement->fetchAll(PDO::FETCH_ASSOC) as $existingNote) {
        echo 'upgrading note #' . $existingNote['note_id'] . ' with a default note name ...' . PHP_EOL;

        $newNoteName = _get_firstline_content($existingNote['content']);
        $query = $pdo->prepare("UPDATE `fuppi_notes` SET `filename` = :filename WHERE `note_id` = :note_id");
        if ($query->execute([
            'note_id' => $existingNote['note_id'],
            'filename' => $newNoteName
        ])) {
            $upgradedCount++;
        }
    }
}

echo 'finished upgrading ' . $upgradedCount . ' notes' . PHP_EOL;

function _get_firstline_content(string $noteContent)
{
    $firstLine = '';
    $lines = explode("\n", $noteContent);
    $words = explode(' ', strip_tags($lines[0]));
    while (strlen($firstLine) < 40 && count($words) > 0) {
        $firstLine .= ' ' . array_shift($words);
    }
    if (count($words) > 0) {
        $firstLine .= '&hellip;';
    }
    return trim($firstLine);
}
