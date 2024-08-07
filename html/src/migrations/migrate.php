<?php

if (!defined('FUPPI')) {
    define('FUPPI', 1);
}

if (!defined('FUPPI_CLI')) {
    define('FUPPI_CLI', 1);
}

require(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php');

if (!file_exists($fuppiConfig['sqlite3_file_path'])) {
    if (!file_exists(dirname($fuppiConfig['sqlite3_file_path']))) {
        mkdir(dirname($fuppiConfig['sqlite3_file_path']), 0777, true);
        $htaccess = <<<HTACCESS
<Files ~ "^.*">
    Deny from all
</Files>
HTACCESS;
        file_put_contents(dirname($fuppiConfig['sqlite3_file_path']) . DIRECTORY_SEPARATOR . '.htaccess', $htaccess);
    }
    touch($fuppiConfig['sqlite3_file_path']);
    chmod($fuppiConfig['sqlite3_file_path'], 0777);
}

require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fuppi.php');

$dbSchemaDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'databaseSchema';

$existingMigrations = [];
$lastMigrationDate = null;

$pdo = $app->getDb()->getPdo();

try {

    $statement = $pdo->query("UPDATE `fuppi_migrations` SET `filename` = '2024-03-20_01' WHERE `filename` = 'install'");

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

    include(__DIR__ . DIRECTORY_SEPARATOR . '2024-03-20_01' . DIRECTORY_SEPARATOR . 'migration.php');

    $pdo->query("INSERT INTO `fuppi_migrations` (`filename`) VALUES ('2024-03-20_01')");

    echo 'All tables created successfully' . PHP_EOL;

    $lastMigrationDate = '2024-03-20 00:00:00';
}

$migrations = list_migrations();

foreach ($migrations as $k => $migration) {
    if (array_key_exists($migration, $existingMigrations)) {
        // ignore migrations that have been explicitly imported
        unset($migrations[$k]);
    }
    if (strtotime(substr($migration, 0, 10)) <= strtotime(substr($lastMigrationDate, 0, 10))) {
        // ignore migrations that have been created before the last migration date
        unset($migrations[$k]);
    }
}

$migrations = array_values($migrations);

echo 'There are ' . count($migrations) . ' migrations to process..' . PHP_EOL;

foreach ($migrations as $migration) {
    echo '--' . PHP_EOL;
    echo 'Processing migration ' . $migration . PHP_EOL;
    try {
        include(__DIR__ . DIRECTORY_SEPARATOR . $migration . DIRECTORY_SEPARATOR . 'migration.php');
        $statement = $pdo->prepare("INSERT INTO `fuppi_migrations` (`filename`) VALUES (:filename)");
        $statement->execute(['filename' => $migration]);
    } catch(Exception $error) {
        echo '  --> Migration Failed! ... cannot proceed, please review the error messages:' . PHP_EOL;
        echo $error->getMessage() . PHP_EOL;
        break;
    }
}

echo 'Finished processing all migrations' . PHP_EOL;
