<?php

echo '-- begin script for installation of phpLiteAdmin' . PHP_EOL;

if (!is_string($password)) {
    echo '  --> cannot proceed because $password is not set' . PHP_EOL;
    return;
}

$phpLiteAdminPath = null;
$phpLiteAdminPathParts = explode(DIRECTORY_SEPARATOR, __DIR__);

while (is_null($phpLiteAdminPath) && count($phpLiteAdminPathParts) > 0) {
    $_phpLiteAdminPath = implode(DIRECTORY_SEPARATOR, $phpLiteAdminPathParts);
    // var_dump($config);
    if (file_exists($_phpLiteAdminPath . DIRECTORY_SEPARATOR . $config->phpliteadmin_folder_name)) {
        $phpLiteAdminPath = $_phpLiteAdminPath . DIRECTORY_SEPARATOR . $config->phpliteadmin_folder_name;
    } else {
        array_pop($phpLiteAdminPathParts);
    }
}

if (!is_null($phpLiteAdminPath)) {
    // update settings for phpLiteAdmin

    echo 'phpLiteAdmin is installed at path: ' . $phpLiteAdminPath . PHP_EOL;

    $phpLiteAdminConfigFilePath = $phpLiteAdminPath . DIRECTORY_SEPARATOR . 'phpliteadmin.config.php';
    $lines = file($phpLiteAdminConfigFilePath, FILE_IGNORE_NEW_LINES);

    // update admin password
    $fuppiUpdateFlag = false;
    foreach ($lines as $k => $line) {
        if ($fuppiUpdateFlag !== false) {
            // look for the closing flag
            if (preg_match('/.*fuppi_update_end.*/', $line)) {
                $fuppiUpdateFlag = false;
                continue;
            } else {
                switch ($fuppiUpdateFlag) {
                    case 'phpLiteAdmin_password':
                        // set the password
                        echo '-- setting the $password' . PHP_EOL;
                        $lines[$k] = "\$password = '{$password}';";
                        break;
                }
            }
        } else {
            // look for the opening flag
            if (preg_match('/.*fuppi_update_begin.*/', $line)) {
                $fuppiUpdateFlag = preg_replace('/(.*fuppi_update_begin:\s)(.*)$/', '$2', $line);
                continue;
            }
        }
    }

    echo '-- writing the $configurationFile at path: ' . $phpLiteAdminConfigFilePath . PHP_EOL;
    file_put_contents($phpLiteAdminConfigFilePath, implode(PHP_EOL, $lines));
} else {
    echo '  --> could not find the installation path for phpLiteAdmin! ' . PHP_EOL;
    echo '    >     path of origin: ' . __DIR__ . PHP_EOL;
    echo '    >     installation foldername: ' . $config->phpliteadmin_folder_name . PHP_EOL;
}
