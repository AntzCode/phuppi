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
$db->exec("CREATE TABLE users (
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
$db->exec("CREATE TABLE settings (
    name VARCHAR(32) PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
)");

// Vouchers table
$db->exec("CREATE TABLE vouchers (
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
$db->exec("CREATE TABLE uploaded_files (
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
$db->exec("CREATE TABLE notes (
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
$db->exec("CREATE TABLE note_tokens (
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
$db->exec("CREATE TABLE tags (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    slug VARCHAR(40) NOT NULL,
    tagname VARCHAR(255) NOT NULL,
    notes TEXT NOT NULL
)");

// Temporary files table
$db->exec("CREATE TABLE temporary_files (
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
$db->exec("CREATE TABLE uploaded_file_tokens (
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
$db->exec("CREATE TABLE uploaded_files_remote_auth (
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
$db->exec("CREATE TABLE uploaded_files_tags (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    uploaded_file_id INTEGER NOT NULL,
    tag_id INTEGER NULL,
    FOREIGN KEY (uploaded_file_id) REFERENCES uploaded_files (id),
    FOREIGN KEY (tag_id) REFERENCES tags (id)
)");

// User permissions table
$db->exec("CREATE TABLE user_permissions (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    permission_name VARCHAR(255) NOT NULL,
    permission_value VARCHAR(255) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id)
)");

// User sessions table
$db->exec("CREATE TABLE user_sessions (
    session_id VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    session_expires_at DATETIME NULL DEFAULT NULL,
    last_login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    client_ip VARCHAR(40) NOT NULL DEFAULT '',
    FOREIGN KEY (user_id) REFERENCES users (id)
)");

// Voucher permissions table
$db->exec("CREATE TABLE voucher_permissions (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    voucher_id INTEGER NOT NULL,
    permission_name VARCHAR(255) NOT NULL,
    permission_value VARCHAR(255) NOT NULL,
    FOREIGN KEY (voucher_id) REFERENCES vouchers (id)
)");

Flight::logger()->info('V2 tables created.');

// Set default settings
$defaultSettings = [
    'file_storage_type' => '',
    'remote_files_region' => '',
    'remote_files_endpoint' => '',
    'remote_files_container' => '',
    'remote_files_access_key' => '',
    'remote_files_secret' => '',
    'remote_files_token_lifetime_seconds' => '',
    'aws_lambda_multiple_zip_function_name' => '',
    'do_functions_multiple_zip_endpoint' => '',
    'do_functions_multiple_zip_api_token' => '',
];

$statement = $db->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)');
foreach ($defaultSettings as $name => $value) {
    $statement->execute([$name, $value]);
}
$statement = null;

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
        $userIdMap[$user['user_id']] = $db->lastInsertId();
        $statement = null;
    }
    $users = null;

    // Migrate user permissions
    $permissions = $db->query('SELECT * FROM fuppi_user_permissions')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($permissions as $perm) {
        $statement = $db->prepare('INSERT INTO user_permissions (user_id, permission_name, permission_value) VALUES (?, ?, ?)');
        $statement->execute([
            $userIdMap[$perm['user_id']] ?? null,
            $perm['permission_name'],
            $perm['permission_value']
        ]);
        $statement = null;
    }
    $permissions = null;

    // Migrate settings
    $settings = $db->query('SELECT * FROM fuppi_settings')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settings as $setting) {
        $statement = $db->prepare('INSERT OR REPLACE INTO settings (name, value) VALUES (?, ?)');
        $statement->execute([$setting['name'], $setting['value']]);
        $statement = null;
    }
    $settings = null;

    // Migrate vouchers
    $voucherIdMap = [];
    $vouchers = $db->query('SELECT * FROM fuppi_vouchers')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vouchers as $voucher) {
        $statement = $db->prepare('INSERT INTO vouchers (user_id, voucher_code, session_id, created_at, updated_at, expires_at, redeemed_at, deleted_at, valid_for, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $userIdMap[$voucher['user_id']] ?? null,
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
        $voucherIdMap[$voucher['voucher_id']] = $db->lastInsertId();
        $statement = null;
    }
    $vouchers = null;

    // Migrate uploaded_files
    $uploadedFileIdMap = [];
    $files = $db->query('SELECT * FROM fuppi_uploaded_files')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as $file) {
        $statement = $db->prepare('INSERT INTO uploaded_files (user_id, voucher_id, filename, display_filename, filesize, mimetype, extension, uploaded_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $userIdMap[$file['user_id']] ?? null,
            $voucherIdMap[$file['voucher_id']] ?? null,
            $file['filename'],
            $file['display_filename'],
            $file['filesize'],
            $file['mimetype'] ?: '',
            $file['extension'] ?: '',
            $file['uploaded_at'],
            $file['notes']
        ]);
        $uploadedFileIdMap[$file['uploaded_file_id']] = $db->lastInsertId();
        $statement = null;
    }
    $files = null;

    // Migrate notes
    $noteIdMap = [];
    $notes = $db->query('SELECT * FROM fuppi_notes')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notes as $note) {
        $statement = $db->prepare('INSERT INTO notes (user_id, voucher_id, filename, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $userIdMap[$note['user_id']] ?? null,
            $voucherIdMap[$note['voucher_id']] ?? null,
            $note['filename'],
            $note['content'],
            $note['created_at'],
            $note['updated_at']
        ]);
        $noteIdMap[$note['note_id']] = $db->lastInsertId();
        $statement = null;
    }
    $notes = null;

    // Migrate note_tokens
    $noteTokens = $db->query('SELECT * FROM fuppi_note_tokens')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($noteTokens as $token) {
        $statement = $db->prepare('INSERT INTO note_tokens (note_id, voucher_id, token, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([
            $noteIdMap[$token['note_id']] ?? null,
            $voucherIdMap[$token['voucher_id']] ?? null,
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
        $tagIdMap[$tag['tag_id']] = $db->lastInsertId();
        $statement = null;
    }
    $tags = null;

    // Migrate temporary_files
    $tempFiles = $db->query('SELECT * FROM fuppi_temporary_files')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tempFiles as $temp) {
        $statement = $db->prepare('INSERT INTO temporary_files (user_id, voucher_id, filename, filesize, mimetype, extension, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $userIdMap[$temp['user_id']] ?? null,
            $voucherIdMap[$temp['voucher_id']] ?? null,
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
        $statement = $db->prepare('INSERT INTO uploaded_file_tokens (uploaded_file_id, voucher_id, token, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([
            $uploadedFileIdMap[$token['uploaded_file_id']] ?? null,
            $voucherIdMap[$token['voucher_id']] ?? null,
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
        $statement = $db->prepare('INSERT INTO uploaded_files_remote_auth (uploaded_file_id, voucher_id, action, url, expires_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([
            $uploadedFileIdMap[$auth['uploaded_file_id']] ?? null,
            $voucherIdMap[$auth['voucher_id']] ?? null,
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
        $statement = $db->prepare('INSERT INTO uploaded_files_tags (uploaded_file_id, tag_id) VALUES (?, ?)');
        $statement->execute([
            $uploadedFileIdMap[$fileTag['uploaded_file_id']] ?? null,
            $tagIdMap[$fileTag['tag_id']] ?? null
        ]);
        $statement = null;
    }
    $fileTags = null;

    // Migrate user_sessions
    $sessions = $db->query('SELECT * FROM fuppi_user_sessions')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sessions as $session) {
        $statement = $db->prepare('INSERT INTO user_sessions (session_id, user_id, session_expires_at, last_login_at, user_agent, client_ip) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $session['session_id'],
            $userIdMap[$session['user_id']] ?? null,
            $session['session_expires_at'],
            $session['last_login_at'],
            $session['user_agent'],
            $session['client_ip']
        ]);
        $statement = null;
    }
    $sessions = null;

    // Migrate voucher_permissions
    $voucherPerms = $db->query('SELECT * FROM fuppi_voucher_permissions')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($voucherPerms as $perm) {
        if (!isset($voucherIdMap[$perm['voucher_id']])) {
            continue;
        }
        $statement = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
        $statement->execute([
            $voucherIdMap[$perm['voucher_id']] ?? null,
            $perm['permission_name'],
            $perm['permission_value']
        ]);
        $statement = null;
    }
    $voucherPerms = null;

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
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec("VACUUM");

    Flight::logger()->info('Data migration from v1 completed.');
}


// Record migration
$db->exec("INSERT INTO migrations (name) VALUES ('001_install_migration')");

Flight::logger()->info('V2 Migration completed successfully.');
