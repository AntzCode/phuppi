# phuppi

A "File-Uppie" thing. A quick way to upload your files to a webserver.

![Preview of Phuppi file uploader](/assets/screenshots/preview.png)

# Features

- Works with Standard PHP Hosting Services
- Does not Require a Database (uses Sqlite3 + Local Filesystem)
- Support for Multiple User Accounts
- Simple & Flexible Permissions Management
- Generate Vouchers for Limited Access
- Generate Temporary Links for Sharing Uploaded Files
- Supports AWS S3 Buckets for Large File Uploads

# How to Install

1. Upload the entire contents of the "html" folder into the public_html or htdocs directory of your PHP server. 
2. Enter the username and password that you want to use for the Super Administrator account and click "Submit".
3. Log in using the username and password you chose at step 2.

# How to Configure AWS Bucket Storage

The feature to support storing files on AWS has been added because PHP servers normally have a limit on the maximum size allowed for file uploads. This way, it allows you to upload large files even while using a standard PHP hosting plan. 

You will need to create an account with [Amazon Web Services](https://aws.amazon.com/resources/create-account/) and configure the security policies as described below.

> [!Tip]
> AWS has a free-tier plan which means you can use a fixed amount of cloud storage for free in the first year! 

1. Create a new IAM policy:

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
            "Resource": "arn:aws:s3:::bucketname"
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
                "s3:DeleteObject",
                "s3:PutObject",
                "s3:GetObject"
            ],
            "Resource": "arn:aws:s3:::bucketname/*"
        }
    ]
}
```
> [!Note]
> Be sure to replace ```bucketname``` with the actual name of your S3 bucket, and ```iam_userid``` & ```iam_username``` with the user id and username of the IAM user you created at step 2.

5. Enter the Access Key, Secret, bucket name and region of the bucket in the Phuppi settings tab.

![Screenshot of S3 Settings](/assets/screenshots/aws-s3-settings.png)

