<?php

require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php');


// Initialize Flight
Flight::route('/', function() {
    Flight::render('home.latte', ['name' => 'Phuppi!']);
});

Flight::start();