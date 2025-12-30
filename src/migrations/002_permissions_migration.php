<?php

// Permissions Migration: Adds roles and user_roles tables for role-based permissions.

use Flight;

$db = Flight::db();

Flight::logger()->info('Starting Permissions Migration...');

// Create roles table
$db->exec("CREATE TABLE IF NOT EXISTS roles (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT ''
)");

// Create user_roles table
$db->exec("CREATE TABLE IF NOT EXISTS user_roles (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    UNIQUE(user_id, role_id)
)");

// Insert default roles
$defaultRoles = [
    ['name' => 'admin', 'description' => 'Administrator with full access'],
    ['name' => 'user', 'description' => 'Regular user'],
    ['name' => 'guest', 'description' => 'Guest user with limited access']
];

$statement = $db->prepare('INSERT OR IGNORE INTO roles (name, description) VALUES (?, ?)');
foreach ($defaultRoles as $role) {
    $statement->execute([$role['name'], $role['description']]);
}

Flight::logger()->info('Permissions Migration completed successfully.');