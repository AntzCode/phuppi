# Digital Ocean Spaces CORS Configuration

When using Digital Ocean Spaces as a storage connector, you need to configure Cross-Origin Resource Sharing (CORS) to allow uploads from your web application.

## Why CORS is Required

CORS is required because your browser-based upload code runs on a different domain than your Digital Ocean Space. Without proper CORS configuration, the browser will block the upload requests for security reasons.

## Adding CORS Policy

1. Log in to the [Digital Ocean Control Panel](https://cloud.digitalocean.com)
2. Navigate to **Spaces** in the left sidebar
3. Select your Space
4. Click on the **Settings** tab
5. Find the **CORS Policy** section
6. Add the following configuration:

![Digital Ocean Spaces CORS Settings](/docs/do-spaces-cors.png)

```xml
<CORSConfiguration>
    <CORSRule>
        <AllowedOrigin>https://your-domain.com</AllowedOrigin>
        <AllowedOrigin>http://localhost</AllowedOrigin>
        <AllowedMethod>GET</AllowedMethod>
        <AllowedMethod>PUT</AllowedMethod>
        <AllowedMethod>POST</AllowedMethod>
        <AllowedMethod>DELETE</AllowedMethod>
        <AllowedHeader>*</AllowedHeader>
    </CORSRule>
</CORSConfiguration>
```

## Configuration Options

- **AllowedOrigin**: The domain(s) that will be uploading files. Add both your production domain and `http://localhost` for development.
- **AllowedMethod**: HTTP methods needed for uploads (GET, PUT, POST, DELETE)
- **AllowedHeader**: Headers that will be sent with the request. Use `*` to allow all headers.

## Example Configurations

### Development (Localhost only)
```xml
<CORSRule>
    <AllowedOrigin>http://localhost:8080</AllowedOrigin>
    <AllowedOrigin>http://localhost</AllowedOrigin>
    <AllowedMethod>GET</AllowedMethod>
    <AllowedMethod>PUT</AllowedMethod>
    <AllowedMethod>POST</AllowedMethod>
    <AllowedMethod>DELETE</AllowedMethod>
    <AllowedHeader>*</AllowedHeader>
</CORSRule>
```

### Production
```xml
<CORSRule>
    <AllowedOrigin>https://yourdomain.com</AllowedOrigin>
    <AllowedMethod>GET</AllowedMethod>
    <AllowedMethod>PUT</AllowedMethod>
    <AllowedMethod>POST</AllowedMethod>
    <AllowedMethod>DELETE</AllowedMethod>
    <AllowedHeader>*</AllowedHeader>
</CORSRule>
```

## Troubleshooting

If you see CORS errors after configuring:
1. Ensure your domain is exactly correct (including the http:// or https:// prefix)
2. Check that the Space is in the same region your connector settings have specified
3. Wait a few minutes for CORS changes to propagate
4. Make sure your Space is set to "Restricted" or "Public" depending on your needs

For more information, see the [Digital Ocean Spaces Documentation](https://docs.digitalocean.com/products/spaces/how-to/configure-cors/).
