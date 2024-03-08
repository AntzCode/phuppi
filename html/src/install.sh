#!/bin/bash

echo "Enter the username for the administrator (default: fuppi)"
read username

echo "Enter the password for the administrator"
read password

echo "Enter the userId for the administrator (default: 1)"
read userId

php ./migrations/migrate.php username=$username password=$password 

