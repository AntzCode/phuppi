<?php

use Phuppi\Messages\UserMessage\InfoMessage;
use Phuppi\Messages\UserMessage\ErrorMessage;
use Phuppi\Messages\UserMessage\SuccessMessage;

require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php');

// Initialize Flight
Flight::route('/', function() {
    Flight::messages()->addUserMessage(new SuccessMessage('Hello from a success message'));
    Flight::messages()->addUserMessage(new ErrorMessage('Hello from an error message'));
    Flight::messages()->addUserMessage(new InfoMessage('Hello from an info message'));
    Flight::render('home.latte', ['name' => 'Phuppi!', 'sessionId' => Flight::session()->get('id')]);
});

Flight::start();