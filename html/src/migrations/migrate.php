<?php

define('FUPPI', 1);
define('FUPPI_CLI', 1);

require(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php');

if (!file_exists($fuppiConfig['sqlite3FilePath'])) {
    if (!file_exists(dirname($fuppiConfig['sqlite3FilePath']))) {
        mkdir(dirname($fuppiConfig['sqlite3FilePath']), 0777, true);
        $htaccess = <<<HTACCESS
<Files ~ "^.*">
    Deny from all
</Files>
HTACCESS;
        file_put_contents(dirname($fuppiConfig['sqlite3FilePath']) . DIRECTORY_SEPARATOR . '.htaccess', $htaccess);
    }
    touch($fuppiConfig['sqlite3FilePath']);
    chmod($fuppiConfig['sqlite3FilePath'], 0777);
}

require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fuppi.php');

$installDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'install';

$existingMigrations = [];
$lastMigrationDate = null;

$pdo = $app->getDb()->getPdo();

try {
    $statement = $pdo->query("SELECT `filename`, `date` FROM `fuppi_migrations` ORDER BY `date` DESC");

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $migrationRecord) {
        $lastMigrationDate = $lastMigrationDate ?? $migrationRecord['date'];
        $existingMigrations[$migrationRecord['filename']] = $migrationRecord['date'];
    }
} catch (PDOException $e) {
    $lastMigrationDate = null;
}

if (is_null($lastMigrationDate)) {
    echo 'Database contains no tables, I will run the install script...' . PHP_EOL;

    include($installDirectory . DIRECTORY_SEPARATOR . 'install.php');

    $pdo->query("INSERT INTO `fuppi_migrations` (`filename`) VALUES ('install')");

    echo 'All tables created successfully' . PHP_EOL;
}

$migrationDates = [];

foreach (scandir(__DIR__, SCANDIR_SORT_ASCENDING) as $filename) {
    if (!preg_match('/^([0-9]{4,4}\-[0-9]{2,2}\-[0-9]{2,2})(_[0-9])?$/', $filename)) {
        continue;
    }
    if ($migrationDate = strtotime(substr($filename, 0, 10))) {

        if (!array_key_exists($filename, $existingMigrations)) {
            $migrationDates[] = $filename;
        } else {
            echo '.. ' . $filename . ' was imported at ' . $existingMigrations[$filename] . PHP_EOL;
        }
    }
}

ksort($migrationDates);
$migrations = array_values($migrationDates);

echo 'There are ' . count($migrations) . ' migrations to process..' . PHP_EOL;

foreach ($migrations as $migration) {
    echo '--' . PHP_EOL;
    echo 'Processing migration ' . $migration . PHP_EOL;
    include(__DIR__ . DIRECTORY_SEPARATOR . $migration . DIRECTORY_SEPARATOR . 'migration.php');
    $statement = $pdo->prepare("INSERT INTO `fuppi_migrations` (`filename`) VALUES (:filename)");
    $statement->execute(['filename' => $migration]);
}

echo 'Finished processing all migrations' . PHP_EOL;
