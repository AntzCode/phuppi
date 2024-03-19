# phuppi

A "File-Uppie" thing. A quick way to upload your files to a webserver.

![Preview of Phuppi file uploader](/assets/screenshots/preview.png)

# How to configure AWS bucket storage

The feature to support storing files on AWS has been added because typically PHP servers have a fairly low limit on the maximum size allowed for file uploads. This way, it allows you to upload large files even while using a standard PHP hosting plan. 

You will need to create an account with Amazon Web Services and configure the security policies as described below.

> [!Information]
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

