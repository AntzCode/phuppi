<?php

//password to gain access (set an empty password to disable authentication completely, set to true to prevent access)

// fuppi_update_begin: phpLiteAdmin_password
$password = true;
// fuppi_update_end: phpLiteAdmin_password

//directory relative to this file to search for databases (if false, manually list databases in the $databases variable)
$directory = false;

//whether or not to scan the subdirectories of the above directory infinitely deep
$subdirectories = false;

//if the above $directory variable is set to false, you must specify the databases manually in an array as the next variable
//if any of the databases do not exist as they are referenced by their path, they will be created automatically
// fuppi_update_begin: phpLiteAdminDatabases
$databases = [
    [
        'path'=> '../data/FUPPI_DB.sqlite3',
        'name'=> 'Fuppi DB (Sqlite3)'
    ]
];
// fuppi_update_end: phpLiteAdminDatabases


// ---- Interface settings ----

// Theme! If you want to change theme, save the CSS file in same folder of phpliteadmin or in folder "themes"
// fuppi_update_begin: phpLiteAdminTheme
$theme = 'FluffyPhpuppi/phpliteadmin.css';
// fuppi_update_begin: phpLiteAdminTheme

// the default language! If you want to change it, save the language file in same folder of phpliteadmin or in folder "languages"
// More about localizations (downloads, how to translate etc.): https://bitbucket.org/phpliteadmin/public/wiki/Localization
$language = 'en';

// set default number of rows. You need to relog after changing the number
$rowsNum = 30;

// reduce string characters by a number bigger than 10
$charsNum = 300;

// maximum number of SQL queries to save in the history
$maxSavedQueries = 10;

// ---- Custom functions ----

//a list of custom functions that can be applied to columns in the databases
//make sure to define every function below if it is not a core PHP function
$custom_functions = [
    'md5', 'sha1', 'strtotime',
    // add the names of your custom functions to this array
    // 'leet_text',
];

// define your custom functions here
/*
function leet_text($value)
{
  return strtr($value, 'eaAsSOl', '344zZ01');
}
*/


// ---- Advanced options ----

//changing the following variable allows multiple phpLiteAdmin installs to work under the same domain.
$cookie_name = 'pla3412';

//whether or not to put the app in debug mode where errors are outputted
$debug = false;

// the user is allowed to create databases with only these extensions
$allowed_extensions = ['db', 'db3', 'sqlite', 'sqlite3'];

// BLOBs are displayed and edited as hex string
$hexblobs = false;
