Phuppi v1.1.6

Instructions for importing AWS Sdk for PHP

21 September 2024

Do not unzip the aws.zip file manually!

Use the script "unzip-sdk.sh" to extract only the files that are whitelisted in the project. Otherwise, you will end up with a lot of redundant files.

whitelist.txt defines all the files that should remain after unzip. Any files not found in whitelist.txt will be removed.

blacklist.txt defines any files that should be removed & overrides whitelist.txt.

Source file aws.zip downloaded at 20240921 from https://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.zip

