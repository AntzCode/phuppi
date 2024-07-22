# phuppi

A "File-Uppie" thing. A quick way to upload your files to a webserver.

![Preview of Phuppi file uploader](/assets/screenshots/preview.png)

# What's it For?

Have you ever wanted to quickly transfer a file from your phone to a computer, but you didn't have the right cable... or have you ever asked someone to send you a copy of a file but they didn't have the first idea of how to do it? 

Phuppi is designed to run on a free PHP hosting account and with Amazon Web Services' S3 Cloud Storage service - so you get the best of both worlds!

All uploads are protected so that only authorised users can access them. You can [generate a URL to share](/assets/screenshots/v1.0.6/create-sharable-file-link.png), that will give one-click access to the file without having to log in.

If you want to give someone else the ability to upload, you can [create a voucher](/assets/screenshots/v1.0.6/voucher-management.png) to use instead of a username/password. That way, they can use the [voucher to authenticate](/assets/screenshots/v1.0.6/voucher-login.png) and then upload files. You can delete the voucher at any time without affecting the user profile.

Phuppi can also [take notes](/assets/screenshots/v1.0.6/notes-list.png), which is quite handy if you ever want to make a quick note of something and you left your phone in the car, or you might need to share sensitive information like passwords and you don't want your password to be found by someone else in an email one day.

# Features

- <b><u>Works with any PHP</b></u> Hosting Service!
- <b><u>Easy and Intuitive</b></u>: two-step install
- Does not Require a Database (uses <u><b>Sqlite3 + Filesystem</b></u>)
- Supports AWS S3 Buckets for <u><b>Large File Uploads</b></u>
- <u><b>Unlimited User Accounts</b></u>
- <u><b>Unlimited File Uploads</b></u>
- <u><b>Share Links</b></u> to Uploaded Files & Text Notes
- <u><b>Generate Vouchers</b></u> for Temporary Access to Upload
- Simple & Flexible <u><b>Permissions Management</b></u>
- Bundled <u><b>Database Explorer</b></u> ([phpLiteAdmin](/assets/screenshots/v1.0.6/phpliteadmin-dashboard.png))
- Fully Open-Source - <u><b>Free to use and to modify!</b></u>
- <b><u>No backdoors, ads, spyware or viruses!</b></u>
- <u><b>You own everything</b></u> - it's all hosted on your own server! :)

View more Screenshots in the [/assets/screenshots](/assets/screenshots/) folder.

# How to Install

1. Upload the entire contents of the "html" folder into the public_html or htdocs directory of your PHP server. 
2. Open the URL in a browser, then you will see the [install screen](/assets/screenshots/v1.0.6/install.png).
3. Enter the username and password that you want to use for the Super Administrator account and click "Submit".
4. [Log in](/assets/screenshots/v1.0.6/login.png) using the username and password you chose at step 2.

# How to Configure AWS Bucket Storage

The feature for storing files on AWS is handy, because PHP servers normally have a limit on the maximum size allowed for file uploads. This way, it allows you to upload large files even while using a standard PHP hosting plan. 

You will need to create an account with [Amazon Web Services](https://aws.amazon.com/resources/create-account/) and configure the security policies as described below.

> [!Tip]
> AWS has a free-tier plan which means you can use a fixed amount of cloud storage for free in the first year! 

1. In the [Amazon Web Services Console](https://console.aws.amazon.com), create a new IAM policy:

```
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "Stmt1528735049406",
            "Action": [
                "s3:DeleteObject",
                "s3:GetObject",
                "s3:ListBucket",
                "s3:PutObject"
            ],
            "Effect": "Allow",
            "Resource": [
                "arn:aws:s3:::bucketname",
                "arn:aws:s3:::bucketname/*"
            ]
        }
    ]
}
```
> [!Note]
> Be sure to replace ```bucketname``` with the actual name of your S3 bucket. 

2. Create a new IAM user, attach the policy to it, and generate a long-term access key. Make sure to write down and store the Access Key and Secret in a safe place.

3. Create a CORS configuration on the bucket's permissions tab:
```
[
    {
        "AllowedHeaders": [
            "*"
        ],
        "AllowedMethods": [
            "HEAD",
            "GET",
            "PUT",
            "POST",
            "DELETE"
        ],
        "AllowedOrigins": [
            "*"
        ],
        "ExposeHeaders": [
            "ETag",
            "x-amz-meta-custom-header"
        ]
    }
]
```
4. Create a bucket policy on the permissions tab of the bucket:

```
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Principal": {
                "AWS": "arn:aws:iam::iam_userid:user/iam_username"
            },
            "Action": [
                "s3:ListBucket",
                "s3:DeleteObject",
                "s3:PutObject",
                "s3:GetObject"
            ],
            "Resource": [
                "arn:aws:s3:::bucketname",
                "arn:aws:s3:::bucketname/*"
            ]
        }
    ]
}
```
> [!Note]
> Be sure to replace ```bucketname``` with the actual name of your S3 bucket, and ```iam_userid``` & ```iam_username``` with the user id and username of the IAM user you created at step 2.

5. Enter the Access Key, Secret, bucket name and region of the bucket in the Phuppi settings tab.

![Screenshot of S3 Settings](/assets/screenshots/aws-s3-settings.png)

# How to Configure AWS Lambda for Multiple File Downloads

![Screenshot of Multiple Files Downloader](/assets/screenshots/v1.0.7/multipe-file-download-selector-cropped.png)

1. In the [Amazon Web Services Console](https://console.aws.amazon.com), create a new IAM role (Services > IAM > Roles > Create Role). The "Trusted Entity Type" shall be "AWS Service" and "Use Case" shall be "Lambda".
2. Add the "AmazonS3FullAccess" selection under "Permissions Policy" and in the "Permissions Boundary" settings, search for the S3 IAM policy that you created when you configured AWS Bucket Storage (step #1 above). [Example Screenshot (IAM Role)](./assets/screenshots/v1.0.7/aws-lambda-role-policy.png).
3. In the [Amazon Web Services Console](https://console.aws.amazon.com), create a new Lambda Function (Services > Lambda > Create Function > Author from Scratch). Give it a descriptive name (eg: Phuppi_s3CreateZipArchive) and choose Node.js 20.x runtime and x86_64 architecture.
4. Under "Change default execution role", select "Use an existing role" and search for the role you created at steps #1 and #2. Leave other settings as default.
5. In the newly-created Lambda Function, go to the "configuration" tab and select "General Configuration" so that you can set the timeout and memory limit to their maximum values. [Example Screenshot](./assets//screenshots/v1.0.7/aws-lambda-function-configuration.png).
6. Under the "Code" tab, select "Upload From > .zip file" and browse to the location of the [Lambda Function source code](./Lambda/zipMultiple/Lambda_Function_s3CreateZipArchive.zip).
7. Copy the Lambda Function's ARN into the "Resources" list of the IAM User Permissions (Services > IAM > Users > (username) > Permissions > Edit) and add the action "lambda:InvokeFunction". [Example Screenshot (IAM User Permission)](./assets/screenshots/v1.0.7/aws-user-permission-policy.png).
7. In your Phuppi installation settings page, save the name of the function you created at step #3 under the setting "aws_lambda_multiple_zip_function_name".
![Screenshot of Lambda Settings](/assets/screenshots/v1.0.7/settings-lambda-zip-multiple.png)
