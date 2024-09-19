import {
    GetObjectCommand,
    type GetObjectCommandOutput,
    PutObjectCommand,
    S3Client
} from "@aws-sdk/client-s3"
import { Upload } from "@aws-sdk/lib-storage"
import archiver from 'archiver'
import { PassThrough, Readable } from 'stream'
import { wrapFunction } from 'do-functions'

type functionArgs = {
    endpoint: string,
    region: string,
    accessKey: string,
    secret: string,
    container: string,
    filenames: s3ObjectItem[],
    archiveFileName: string,
}

type s3Credentials = {
    endpoint: string,
    region: string,
    accessKey: string,
    secret: string
}

type s3ObjectItem = {
    filename: string,
    displayFilename: string
}

export const getReadableStreamFromS3 = async (
    key: string,
    bucketName: string,
    credentials: s3Credentials
): Promise<GetObjectCommandOutput["Body"] | undefined> => {
    const client = new S3Client({
        forcePathStyle: false,
        region: credentials.region,
        endpoint: credentials.endpoint,
        credentials: {
            accessKeyId: credentials.accessKey,
            secretAccessKey: credentials.secret
        }
    })

    const command = new GetObjectCommand({
        Bucket: bucketName,
        Key: key,
    })

    const response = await client.send(command)

    return response.Body
}

export const getWritableStreamFromS3 = (zipFileKey: string, bucketName: string, credentials: s3Credentials): PassThrough => {
    const passthrough = new PassThrough();

    const client = new S3Client({
        forcePathStyle: true,
        region: credentials.region,
        endpoint: credentials.endpoint,
        credentials: {
            accessKeyId: credentials.accessKey,
            secretAccessKey: credentials.secret
        }
    })

    new Upload({
        client,
        params: {
            Bucket: bucketName,
            Key: zipFileKey,
            Body: passthrough,
        },
    }).done()

    return passthrough
}

export const generateAndStreamZipfileToS3 = async (
    s3KeyList: s3ObjectItem[],
    zipFileS3Key: string,
    bucketName: string,
    credentials: s3Credentials
): Promise<void> => {
    // eslint-disable-next-line no-async-promise-executor
    return new Promise(async (resolve, reject) => {

        const s3Client = new S3Client({
            forcePathStyle: true,
            region: credentials.region,
            endpoint: credentials.endpoint,
            credentials: {
                accessKeyId: credentials.accessKey,
                secretAccessKey: credentials.secret
            }
        })

        const pass = new PassThrough()
        const archive = archiver("zip", { zlib: { level: 9 } })
        const chunks: Buffer[] = []

        archive.on("error", (err) => reject(err))
        pass.on("error", (err) => reject(err))
        pass.on("data", (chunk) => chunks.push(chunk))
        pass.on("end", async () => {
            const buffer = Buffer.concat(chunks)

            const uploadParams = {
                Bucket: bucketName,
                Key: zipFileS3Key,
                Body: buffer,
            }

            await s3Client.send(new PutObjectCommand(uploadParams))

            resolve()
        })

        archive.pipe(pass)

        for (const filename of s3KeyList) {
            try {
                const response = (await getReadableStreamFromS3(filename.filename, bucketName, credentials)) as Readable
                archive.append(response, { name: filename.displayFilename })
            } catch (error) {
                console.error(`Error streaming file ${filename.filename}: ${JSON.stringify(error)}`, filename)
                throw error
            }
        }

        archive.finalize()
    })
}

const handler = async (event: functionArgs) => {

    try {
        const bucket = event.container
        const filenames = event.filenames || []
        const archiveFileName = event.archiveFileName || 'archive.zip'

        const credentials = {
            endpoint: event.endpoint,
            region: event.region,
            accessKey: event.accessKey,
            secret: event.secret
        }

        if (filenames.length === 0) {
            console.log('No files specified for download. Exiting.')
            throw new Error('No files specified for download.')
        }

        await generateAndStreamZipfileToS3(filenames, archiveFileName, bucket, credentials)

        return JSON.stringify({ message: `Zip file ${archiveFileName} created and uploaded successfully.` })
        
    } catch (error) {
        throw new Error(`Error in handler: ${JSON.stringify(error)}`)
    }
}

export const main = wrapFunction(handler)
