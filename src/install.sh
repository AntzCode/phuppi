#!/bin/bash

echo "Enter the username for the administrator"
read username

echo "Enter the password for the administrator"
read password

php ./migrations/migrate.php username=$username password=$password 

