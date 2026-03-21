# AWS SDK Replacement

## Summary

Replace the bundled AWS SDK (located in `src/aws/`) with a lightweight, custom S3-compatible REST service using Guzzle HTTP client. This will significantly reduce installation size and complexity while maintaining full compatibility with S3-compatible storage providers.

## Background

The project currently includes a full copy of the AWS SDK directly in `src/aws/` (not as a Composer dependency). Despite the SDK's size (~5,000 files), only a handful of S3 operations are actually used:

| Operation | Purpose |
|-----------|---------|
| `putObject` | Upload files to S3 |
| `getObject` | Download/stream files |
| `headObject` | Get file metadata (size, content-type) |
| `deleteObject` | Delete files |
| `doesObjectExist` | Check file existence |
| `listObjectsV2` | List files (orphaned file scan) |
| `headBucket` | Test connection |
| `createPresignedRequest` | Generate presigned URLs |

Additionally, `FileController` uses the S3Client directly for streaming files with Range header support (HTTP 206 partial content) and video preview streaming.

## Proposed Solution

Create a custom `S3Service` class that implements all required S3 operations using simple REST API calls via Guzzle HTTP client. This service will:

1. Handle all authentication using AWS Signature V4
2. Support all S3-compatible providers (AWS S3, Digital Ocean Spaces, MinIO, etc.)
3. Provide the same functionality as the current AWS SDK usage
4. Add support for Range requests for video/file streaming

## Implementation Plan

### Phase 1: Create Custom S3 Service

Create `src/Phuppi/Storage/S3Service.php` with the following interface:

```php
class S3Service
{
    public function putObject(string $key, string $filePath): bool;
    public function getObject(string $key): StreamInterface;
    public function headObject(string $key): array;
    public function deleteObject(string $key): bool;
    public function doesObjectExist(string $key): bool;
    public function listObjectsV2(string $prefix): array;
    public function headBucket(): bool;
    public function createPresignedUrl(string $method, string $key, int $expiresIn): string;
}
```

Key implementation details:
- Use `guzzlehttp/guzzle` for HTTP requests
- Use `guzzlehttp/psr7` for PSR-7 stream implementation
- Implement AWS Signature V4 signing for authentication
- Support Range headers for partial content streaming

### Phase 2: Update S3Storage Wrapper

Update `src/Phuppi/Storage/S3Storage.php`:
- Replace `Aws\S3\S3Client` property with `S3Service`
- Update all method implementations to delegate to `S3Service`
- Maintain the same public interface for backward compatibility

### Phase 3: Handle Direct S3Client Usage

Refactor `src/Phuppi/Controllers/FileController.php`:
- Add methods to `S3Service` for Range request streaming
- Update `S3Storage` to expose these capabilities
- Remove direct `$storage->getS3Client()` calls

Also update `src/Phuppi/Controllers/SettingsController.php` for orphaned file scanning.

### Phase 4: Remove AWS SDK

- Delete entire `src/aws/` directory
- Remove any AWS-related imports and references

## Implementation Checklist

- [ ] Create `src/Phuppi/Storage/S3Service.php` with Guzzle HTTP client
- [ ] Implement AWS Signature V4 signing for authentication
- [ ] Implement all required S3 operations (put, get, head, delete, list)
- [ ] Implement presigned URL generation
- [ ] Add Range request support for streaming
- [ ] Update `S3Storage` to use `S3Service`
- [ ] Refactor `FileController` to remove direct S3Client usage
- [ ] Update `SettingsController` orphaned file scanning
- [ ] Delete `src/aws/` directory
- [ ] Test all storage operations
- [ ] Verify presigned URLs work
- [ ] Verify file streaming with Range headers works

## Benefits

1. **Reduced Installation Size** - Remove ~5,000 files from the bundle
2. **Faster Installation** - No need to download/install massive AWS SDK
3. **Easier Maintenance** - Single custom service vs. massive SDK
4. **Better Performance** - Lightweight HTTP calls only
5. **Full S3 Compatibility** - Works with AWS S3, Digital Ocean Spaces, MinIO, etc.

## Dependencies

The new implementation will require:
- `guzzlehttp/guzzle` - HTTP client (already available in project)
- `guzzlehttp/psr7` - PSR-7 stream implementation (for file streaming)

No additional Composer dependencies required.

## Files to Modify

- `src/Phuppi/Storage/S3Storage.php` - Update to use new S3Service
- `src/Phuppi/Controllers/FileController.php` - Remove direct S3Client usage
- `src/Phuppi/Controllers/SettingsController.php` - Update orphaned file scanning
- `src/aws/` - Delete entire directory

## Files to Create

- `src/Phuppi/Storage/S3Service.php` - New lightweight S3 REST client
