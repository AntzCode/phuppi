import aws from 'aws-sdk';
import archiver from 'archiver';
import { PassThrough } from 'stream';

const S3 = new aws.S3({ signatureVersion: 'v4' });

const downloadAndZipFiles = async (bucket, filenames, zipFileName) => {
    const archive = archiver('zip', { zlib: { level: 9 } });
    const passThrough = new PassThrough();

    archive.pipe(passThrough);

    const uploadParams = {
        Bucket: bucket,
        Key: zipFileName,
        Body: passThrough,
        ContentType: 'application/zip'
    };

    const uploadPromise = S3.upload(uploadParams).promise();

    for (const filename of filenames) {

        if(typeof filename === 'object'){
            try {
                const response = await S3.getObject({ Bucket: bucket, Key: filename.filename }).createReadStream();
                archive.append(response, { name: filename.displayFilename });
            } catch (error) {
                console.error(`Error streaming file ${filename}: ${JSON.stringify(error)}`);
                throw error;
            }
        }else if(typeof filename === 'string'){
            try {
                const response = await S3.getObject({ Bucket: bucket, Key: filename }).createReadStream();
                archive.append(response, { name: filename.split('/').pop() });
            } catch (error) {
                console.error(`Error streaming file ${filename}: ${JSON.stringify(error)}`);
                throw error;
            }
        }
    }

    archive.finalize();

    await uploadPromise;
    console.log(`Zip file ${zipFileName} uploaded successfully to ${bucket} bucket.`);
};

const handler = async (event, context) => {

    try {
        const bucket = event.bucket;
        const filenames = event.filenames || [];
        const zipFileName = event.zipFileName || 'archive.zip';

        if (filenames.length === 0) {
            console.log('No files specified for download. Exiting.');
            return {
                statusCode: 400,
                body: JSON.stringify({ error: 'No files specified for download.' }),
            };
        };

        await downloadAndZipFiles(bucket, filenames, zipFileName);

        return {
            statusCode: 200,
            body: JSON.stringify({ message: `Zip file ${zipFileName} created and uploaded successfully.` }),
        };
    } catch (error) {
        console.error(`Error in handler: ${JSON.stringify(error)}`);
        return {
            statusCode: 500,
            body: JSON.stringify({ error: 'Internal Server Error' }),
        };
    }
};

export { handler };