<?php

// V2 Migration: Creates the full v2 database structure.
// If a v1 database is detected, migrates data from v1 to v2.

$db = Flight::db();

Flight::logger()->info('Starting Install Migration...');

// Check if v1 database exists
$v1Exists = false;
$result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='fuppi_users'");
if ($result->fetchColumn() !== false) {
    $v1Exists = true;
    Flight::logger()->info('V1 database detected, will migrate data after creating v2 structure.');
}
$result = null;

// Create v2 tables
Flight::logger()->info('Creating v2 database tables...');

// Migrations table
$db->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Users table
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    disabled_at DATETIME NULL DEFAULT NULL,
    session_expires_at DATETIME NULL DEFAULT NULL,
    notes TEXT NOT NULL DEFAULT ''
)");

// Settings table
$db->exec("CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(32) PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
)");

$db->exec("CREATE TABLE IF NOT EXISTS storage_connectors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL,
    config TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Vouchers table
$db->exec("CREATE TABLE IF NOT EXISTS vouchers (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    voucher_code VARCHAR(255) NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    expires_at DATETIME NULL DEFAULT NULL,
    redeemed_at DATETIME NULL DEFAULT NULL,
    deleted_at DATETIME NULL DEFAULT NULL,
    valid_for INT NULL,
    notes TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (user_id) REFERENCES users (id)
)");

// Uploaded files table
$db->exec("CREATE TABLE IF NOT EXISTS uploaded_files (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    voucher_id INTEGER NULL,
    filename VARCHAR(255) NOT NULL,
    display_filename VARCHAR(255) NOT NULL,
    filesize INTEGER NOT NULL,
    mimetype VARCHAR(100) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    uploaded_at DATETIME NOT NULL,
    notes TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id),
    FOREIGN KEY (voucher_id) REFERENCES vouchers (id)
)");

// Notes table
$db->exec("CREATE TABLE IF NOT EXISTS notes (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    voucher_id INTEGER NULL,
    filename VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id),
    FOREIGN KEY (voucher_id) REFERENCES vouchers (id)
)");

// Note tokens table
$db->exec("CREATE TABLE IF NOT EXISTS note_tokens (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER NOT NULL,
    voucher_id INTEGER NULL,
    token VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (note_id) REFERENCES notes (id),
    FOREIGN KEY (voucher_id) REFERENCES vouchers (id)
)");

// Tags table
$db->exec("CREATE TABLE IF NOT EXISTS tags (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    slug VARCHAR(40) NOT NULL,
    tagname VARCHAR(255) NOT NULL,
    notes TEXT NOT NULL
)");

// Temporary files table
$db->exec("CREATE TABLE IF NOT EXISTS temporary_files (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    voucher_id INTEGER NULL,
    filename VARCHAR(255) NOT NULL,
    filesize INTEGER NOT NULL,
    mimetype VARCHAR(255),
    extension VARCHAR(8),
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id),
    FOREIGN KEY (voucher_id) REFERENCES vouchers (id)
)");

// Uploaded file tokens table
$db->exec("CREATE TABLE IF NOT EXISTS uploaded_file_tokens (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    uploaded_file_id INTEGER NOT NULL,
    voucher_id INTEGER NULL,
    token VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (uploaded_file_id) REFERENCES uploaded_files (id),
    FOREIGN KEY (voucher_id) REFERENCES vouchers (id)
)");

// Uploaded files remote auth table
$db->exec("CREATE TABLE IF NOT EXISTS uploaded_files_remote_auth (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    uploaded_file_id INTEGER NOT NULL,
    voucher_id INTEGER NULL,
    action VARCHAR(10) NOT NULL,
    url VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (uploaded_file_id) REFERENCES uploaded_files (id),
    FOREIGN KEY (voucher_id) REFERENCES vouchers (id)
)");

// Uploaded files tags table
$db->exec("CREATE TABLE IF NOT EXISTS uploaded_files_tags (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    uploaded_file_id INTEGER NOT NULL,
    tag_id INTEGER NULL,
    FOREIGN KEY (uploaded_file_id) REFERENCES uploaded_files (id),
    FOREIGN KEY (tag_id) REFERENCES tags (id)
)");

// roles table
$db->exec("CREATE TABLE IF NOT EXISTS roles (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT ''
)");

// user_roles table
$db->exec("CREATE TABLE IF NOT EXISTS user_roles (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    UNIQUE(user_id, role_id)
)");

// voucher_roles table
$db->exec("CREATE TABLE IF NOT EXISTS voucher_roles (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    voucher_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    UNIQUE(voucher_id, role_id)
)");

// User permissions table
$db->exec("CREATE TABLE IF NOT EXISTS user_permissions (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    permission_name VARCHAR(255) NOT NULL,
    permission_value VARCHAR(255) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id)
)");

// Voucher permissions table
$db->exec("CREATE TABLE IF NOT EXISTS voucher_permissions (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    voucher_id INTEGER NOT NULL,
    permission_name VARCHAR(255) NOT NULL,
    permission_value VARCHAR(255) NOT NULL,
    FOREIGN KEY (voucher_id) REFERENCES vouchers (id)
)");

Flight::logger()->info('V2 tables created.');

//create default roles
if ($db->query("SELECT COUNT(*) as count FROM roles")->fetchColumn() < 1) {
    Flight::logger()->info('No roles defined, creating default roles...');
    $db->exec("INSERT INTO roles (name, description) VALUES 
    ('admin', 'Administrator with full access'), 
    ('user', 'Regular user'), 
    ('guest', 'Guest user with limited access')");
}

// Set default settings
if ($db->query("SELECT COUNT(*) as count FROM settings")->fetchColumn() < 1) {
    $defaultSettings = [];
    $statement = $db->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)');
    foreach ($defaultSettings as $name => $value) {
        $statement->execute([$name, $value]);
    }
    $statement = null;
}

// create the default local filesystem connector
if ($db->query("SELECT COUNT(*) as count FROM storage_connectors WHERE name = 'local-default'")->fetchColumn() < 1) {
    $statement = $db->prepare('INSERT OR IGNORE INTO storage_connectors (name, type, config) VALUES (?, ?,?)');
    $statement->execute(['local-default', 'local', json_encode([
        'type' => 'local',
        'path' => null,
        'name' => 'Local Storage (Default)'
    ])]);
    $statement = null;

    $statement = $db->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)');
    $statement->execute(['active_storage_connector', 'local-default']);
    $statement = null;
}

Flight::logger()->info('Default settings set.');

// If v1 exists, migrate data
if ($v1Exists) {
    Flight::logger()->info('Migrating data from v1...');

    // Migrate users
    $userIdMap = [];
    $users = $db->query('SELECT * FROM fuppi_users')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        // Skip if username exists
        $exists = $db->query("SELECT id FROM users WHERE username = '{$user['username']}'")->fetchColumn();
        if ($exists) continue;
        $exists = null;

        $statement = $db->prepare('INSERT INTO users (username, password, created_at, updated_at, disabled_at, session_expires_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $user['username'],
            $user['password'],
            $user['created_at'],
            $user['updated_at'],
            $user['disabled_at'],
            $user['session_expires_at'],
            $user['notes']
        ]);
        $userIdMap[(string) $user['user_id']] = (int) $db->lastInsertId();
        $statement = null;
    }
    $users = null;

    // Migrate user permissions
    $permissions = $db->query('SELECT * FROM fuppi_user_permissions')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($permissions as $perm) {
        // make sure the user is still existing in the database
        if(!isset($userIdMap[(string) $perm['user_id']])) {
            continue;
        }
        switch($perm['permission_name']) {
            case 'IS_ADMINISTRATOR':
                if($perm['permission_value'] == 'true') {
                    // get the admin role id
                    $statement = $db->prepare('SELECT id FROM roles WHERE name = "admin"');
                    $statement->execute();
                    $adminRoleId = $statement->fetchColumn();
                    $statement = null;
                    
                    // add user to admin role
                    $statement = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                    $statement->execute([
                        $userIdMap[(string) $perm['user_id']],
                        $adminRoleId
                    ]);
                    $statement = null;
                    
                }
                break;
            case 'UPLOADEDFILES_PUT':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\FilePermission::PUT->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\FilePermission::CREATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\FilePermission::UPDATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'UPLOADEDFILES_DELETE':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\FilePermission::DELETE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'UPLOADEDFILES_LIST':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\FilePermission::LIST->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'UPLOADEDFILES_READ':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\FilePermission::VIEW->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'USERS_PUT':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\UserPermission::CREATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\UserPermission::UPDATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\UserPermission::PERMIT->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'USERS_DELETE':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\UserPermission::DELETE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'USERS_LIST':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\UserPermission::LIST->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'USERS_READ':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\UserPermission::VIEW->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'NOTES_PUT':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\NotePermission::CREATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\NotePermission::UPDATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'NOTES_DELETE':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\NotePermission::DELETE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'NOTES_LIST':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\NotePermission::LIST->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'NOTES_READ':
                $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $userIdMap[(string) $perm['user_id']],
                    \Phuppi\Permissions\NotePermission::VIEW->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
        }

    }
    $permissions = null;

    // Migrate settings
    $settings = $db->query('SELECT * FROM fuppi_settings')->fetchAll(PDO::FETCH_ASSOC);
    $settingsFlat = [];
    foreach($settings as $setting) {
        $settingsFlat[$setting['name']] = $setting['value'];
    }

    if(!empty($settingsFlat['remote_files_access_key']) && !empty($settingsFlat['remote_files_secret'])) {
        // create an s3 connector
        $statement = $db->prepare('INSERT INTO storage_connectors (name, type, config) VALUES (?, ?, ?)');
        $connectorName = $settingsFlat['file_storage_type'] === 'do_spaces' ? 'do-spaces' : 'aws-s3';
        $statement->execute([
            $connectorName,
            's3',
            json_encode([
                'type' => "s3", 
                'name' => $settingsFlat['file_storage_type'] === 'do_spaces' ? 'DigitalOcean Spaces' : 'AWS S3',
                'bucket' => $settingsFlat['remote_files_container'],
                'region' => $settingsFlat['remote_files_region'],
                'key' => $settingsFlat['remote_files_access_key'],
                'secret' => $settingsFlat['remote_files_secret'],
                'endpoint' => $settingsFlat['remote_files_endpoint'],
                'path_prefix' => "data/uploadedFiles"
            ])
        ]);
        $statement = null;
        
        if($settingsFlat['file_storage_type'] === 'do_spaces' || $settingsFlat['file_storage_type'] === 'aws_s3') {
            // update the settings to use the aws connector
            $statement = $db->prepare('UPDATE settings SET value = ? WHERE name = ?');
            $statement->execute([$connectorName, 'active_storage_connector']);
            $statement = null;
        }

    }

    // remove connector settings from settings table
    unset($settingsFlat['file_storage_type']);
    unset($settingsFlat['remote_files_container']);
    unset($settingsFlat['remote_files_region']);
    unset($settingsFlat['remote_files_access_key']);
    unset($settingsFlat['remote_files_secret']);
    unset($settingsFlat['remote_files_endpoint']);
    unset($settingsFlat['s3_bucket']);
    unset($settingsFlat['s3_region']);
    unset($settingsFlat['s3_secret']);
    unset($settingsFlat['s3_key']);
    unset($settingsFlat['remote_files_token_lifetime_seconds']);
    unset($settingsFlat['do_functions_multiple_zip_endpoint']);
    unset($settingsFlat['do_functions_multiple_zip_api_token']);
    unset($settingsFlat['aws_lambda_multiple_zip_function_name']);

    foreach ($settingsFlat as $settingName => $settingValue) {
        $statement = $db->prepare('INSERT OR REPLACE INTO settings (name, value) VALUES (?, ?)');
        $statement->execute([$settingName, $settingValue]);
        $statement = null;
    }
    $settings = null;

    // Migrate vouchers
    $voucherIdMap = [];
    $vouchers = $db->query('SELECT * FROM fuppi_vouchers')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vouchers as $voucher) {
        if(!isset($userIdMap[(string) $voucher['user_id']])) {
            continue;
        }
        $statement = $db->prepare('INSERT INTO vouchers (user_id, voucher_code, session_id, created_at, updated_at, expires_at, redeemed_at, deleted_at, valid_for, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $userIdMap[(string) $voucher['user_id']],
            $voucher['voucher_code'],
            $voucher['session_id'],
            $voucher['created_at'],
            $voucher['updated_at'],
            $voucher['expires_at'],
            $voucher['redeemed_at'],
            $voucher['deleted_at'],
            $voucher['valid_for'],
            $voucher['notes']
        ]);
        $voucherIdMap[(string) $voucher['voucher_id']] = (int) $db->lastInsertId();
        $statement = null;
    }
    $vouchers = null;

    // Migrate uploaded_files
    $uploadedFileIdMap = [];
    $files = $db->query('SELECT * FROM fuppi_uploaded_files')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as $file) {
        if($file['voucher_id'] > 0 && !isset($voucherIdMap[(string) $file['voucher_id']])) {
            continue;
        }
        if(!isset($userIdMap[(string) $file['user_id']])) {
            continue;
        }
        $statement = $db->prepare('INSERT INTO uploaded_files (user_id, voucher_id, filename, display_filename, filesize, mimetype, extension, uploaded_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $userIdMap[(string) $file['user_id']],
            $file['voucher_id'] > 0 ? $voucherIdMap[(string) $file['voucher_id']] : null,
            $file['filename'],
            $file['display_filename'],
            $file['filesize'],
            $file['mimetype'] ?: '',
            $file['extension'] ?: '',
            $file['uploaded_at'],
            $file['notes']
        ]);
        $uploadedFileIdMap[(string) $file['uploaded_file_id']] = (int) $db->lastInsertId();
        $statement = null;
    }
    $files = null;

    // Migrate notes
    $noteIdMap = [];
    $notes = $db->query('SELECT * FROM fuppi_notes')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notes as $note) {
        if($note['voucher_id'] > 0 && !isset($voucherIdMap[(string) $note['voucher_id']])) {
            continue;
        }
        if($note['user_id'] > 0 && !isset($userIdMap[(string) $note['user_id']])) {
            continue;
        }
        $statement = $db->prepare('INSERT INTO notes (user_id, voucher_id, filename, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $note['user_id'] > 0 ? $userIdMap[(string) $note['user_id']] : null,
            $note['voucher_id'] > 0 ? $voucherIdMap[(string) $note['voucher_id']] : null,
            $note['filename'],
            $note['content'],
            $note['created_at'],
            $note['updated_at']
        ]);
        $noteIdMap[(string) $note['note_id']] = (int) $db->lastInsertId();
        $statement = null;
    }
    $notes = null;

    // Migrate note_tokens
    $noteTokens = $db->query('SELECT * FROM fuppi_note_tokens')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($noteTokens as $token) {
        if (!isset($noteIdMap[(string) $token['note_id']])) {
            continue;
        }
        if (!is_null($token['voucher_id']) && !isset($voucherIdMap[(string) $token['voucher_id']])) {
            continue;
        }
        $statement = $db->prepare('INSERT INTO note_tokens (note_id, voucher_id, token, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([
            $noteIdMap[(string) $token['note_id']],
            is_null($token['voucher_id']) ? null : $voucherIdMap[(string) $token['voucher_id']],
            $token['token'],
            $token['created_at'],
            $token['expires_at']
        ]);
        $statement = null;
    }
    $noteTokens = null;

    // Migrate tags
    $tagIdMap = [];
    $tags = $db->query('SELECT * FROM fuppi_tags')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tags as $tag) {
        $statement = $db->prepare('INSERT INTO tags (slug, tagname, notes) VALUES (?, ?, ?)');
        $statement->execute([
            $tag['slug'],
            $tag['tagname'],
            $tag['notes']
        ]);
        $tagIdMap[(string) $tag['tag_id']] = (int) $db->lastInsertId();
        $statement = null;
    }
    $tags = null;

    // Migrate temporary_files
    $tempFiles = $db->query('SELECT * FROM fuppi_temporary_files')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tempFiles as $temp) {
        if(!isset($voucherIdMap[(string) $temp['voucher_id']])) {
            continue;
        }
        if(!isset($userIdMap[(string) $temp['user_id']])) {
            continue;
        }
        $statement = $db->prepare('INSERT INTO temporary_files (user_id, voucher_id, filename, filesize, mimetype, extension, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $userIdMap[(string) $temp['user_id']],
            $voucherIdMap[(string) $temp['voucher_id']],
            $temp['filename'],
            $temp['filesize'],
            $temp['mimetype'],
            $temp['extension'],
            $temp['created_at'],
            $temp['expires_at']
        ]);
        $statement = null;
    }
    $tempFiles = null;

    // Migrate uploaded_file_tokens
    $fileTokens = $db->query('SELECT * FROM fuppi_uploaded_file_tokens')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fileTokens as $token) {
        if (!isset($uploadedFileIdMap[(string) $token['uploaded_file_id']])) {
            continue;
        }
        if(!is_null($token['voucher_id']) && !isset($voucherIdMap[(string) $token['voucher_id']])) {
            continue;
        }
        $statement = $db->prepare('INSERT INTO uploaded_file_tokens (uploaded_file_id, voucher_id, token, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([
            $uploadedFileIdMap[(string) $token['uploaded_file_id']],
            is_null($token['voucher_id']) ? null : $voucherIdMap[(string) $token['voucher_id']],
            $token['token'],
            $token['created_at'],
            $token['expires_at']
        ]);
        $statement = null;
    }
    $fileTokens = null;

    // Migrate uploaded_files_remote_auth
    $remoteAuths = $db->query('SELECT * FROM fuppi_uploaded_files_remote_auth')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($remoteAuths as $auth) {
        if (!isset($uploadedFileIdMap[(string) $auth['uploaded_file_id']])) {
            continue;
        }
        if(!isset($voucherIdMap[(string) $auth['voucher_id']])) {
            continue;
        }
        $statement = $db->prepare('INSERT INTO uploaded_files_remote_auth (uploaded_file_id, voucher_id, action, url, expires_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([
            $uploadedFileIdMap[(string) $auth['uploaded_file_id']],
            $voucherIdMap[(string) $auth['voucher_id']],
            $auth['action'],
            $auth['url'],
            $auth['expires_at']
        ]);
        $statement = null;
    }
    $remoteAuths = null;

    // Migrate uploaded_files_tags
    $fileTags = $db->query('SELECT * FROM fuppi_uploaded_files_tags')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fileTags as $fileTag) {
        if(!isset($uploadedFileIdMap[(string) $fileTag['uploaded_file_id']])) {
            continue;
        }
        if(!isset($tagIdMap[(string) $fileTag['tag_id']])) {
            continue;
        }
        $statement = $db->prepare('INSERT INTO uploaded_files_tags (uploaded_file_id, tag_id) VALUES (?, ?)');
        $statement->execute([
            $uploadedFileIdMap[(string) $fileTag['uploaded_file_id']],
            $tagIdMap[(string) $fileTag['tag_id']]
        ]);
        $statement = null;
    }
    $fileTags = null;

    // Migrate voucher_permissions
    $permissions = $db->query('SELECT * FROM fuppi_voucher_permissions')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($permissions as $perm) {
        // make sure the voucher is still existing in the database
        if(!isset($voucherIdMap[(string) $perm['voucher_id']])) {
            continue;
        }
        $statement = null;
        switch($perm['permission_name']) {
            case 'IS_ADMINISTRATOR':
                if($perm['permission_value'] == 'true') {
                    // get the admin role id
                    $statement = $db->prepare('SELECT id FROM roles WHERE name = "admin"');
                    $statement->execute();
                    $adminRoleId = $statement->fetchColumn();
                    $statement = null;
                    
                    // add voucher to admin role
                    $statement = $db->prepare('INSERT INTO voucher_roles (voucher_id, role_id) VALUES (?, ?)');
                    $statement->execute([
                        $voucherIdMap[(string) $perm['voucher_id']],
                        $adminRoleId
                    ]);
                    $statement = null;
                    
                }
                break;
            case 'UPLOADEDFILES_PUT':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\FilePermission::PUT->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\FilePermission::CREATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\FilePermission::UPDATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'UPLOADEDFILES_DELETE':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\FilePermission::DELETE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'UPLOADEDFILES_LIST':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\FilePermission::LIST->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'UPLOADEDFILES_READ':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\FilePermission::VIEW->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'USERS_PUT':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\UserPermission::CREATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\UserPermission::UPDATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\UserPermission::PERMIT->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'USERS_DELETE':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\UserPermission::DELETE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'USERS_LIST':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\UserPermission::LIST->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'USERS_READ':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\UserPermission::VIEW->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'NOTES_PUT':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\NotePermission::CREATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\NotePermission::UPDATE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'NOTES_DELETE':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\NotePermission::DELETE->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'NOTES_LIST':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\NotePermission::LIST->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
            case 'NOTES_READ':
                $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
                $statement->execute([
                    $voucherIdMap[(string) $perm['voucher_id']],
                    \Phuppi\Permissions\NotePermission::VIEW->value,
                    json_encode(true)
                ]);
                $statement = null;
                break;
        }

    }
    $permissions = null;

    // delete the v1 tables
    $db->exec('PRAGMA foreign_keys = OFF');
    $db->exec('DROP TABLE fuppi_users');
    $db->exec('DROP TABLE fuppi_user_permissions');
    $db->exec('DROP TABLE fuppi_settings');
    $db->exec('DROP TABLE fuppi_vouchers');
    $db->exec('DROP TABLE fuppi_uploaded_files');
    $db->exec('DROP TABLE fuppi_notes');
    $db->exec('DROP TABLE fuppi_note_tokens');
    $db->exec('DROP TABLE fuppi_tags');
    $db->exec('DROP TABLE fuppi_temporary_files');
    $db->exec('DROP TABLE fuppi_uploaded_file_tokens');
    $db->exec('DROP TABLE fuppi_uploaded_files_remote_auth');
    $db->exec('DROP TABLE fuppi_uploaded_files_tags');
    $db->exec('DROP TABLE fuppi_user_sessions');
    $db->exec('DROP TABLE fuppi_voucher_permissions');
    $db->exec('DROP TABLE fuppi_migrations');

    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec("VACUUM");

    Flight::logger()->info('Data migration from v1 completed.');
}


// Record migration
if ($db->query("SELECT COUNT(*) as count FROM migrations WHERE name = '001_install_migration'")->fetchColumn() < 1) {
    $db->exec("INSERT INTO migrations (name) VALUES ('001_install_migration')");
}

Flight::logger()->info('V2 Migration completed successfully.');
