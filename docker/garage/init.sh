#!/bin/sh
# Sets up the dev Garage once it is up: the node's storage role, Phuppi's access key and bucket.
# Safe to run again: each step is skipped when it is already done.
#
# Environment: GARAGE_ADMIN_URL, GARAGE_S3_URL, GARAGE_ADMIN_TOKEN, S3_DEV_ACCESS_KEY, S3_DEV_SECRET_KEY, S3_DEV_BUCKET
set -eu

apk add --no-cache curl jq openssl >/dev/null

api() {
  # api METHOD PATH [JSON_BODY]
  curl -sS -X "$1" -H "Authorization: Bearer $GARAGE_ADMIN_TOKEN" -H "Content-Type: application/json" \
    ${3:+-d "$3"} "$GARAGE_ADMIN_URL/v2/$2"
}

# status_of METHOD PATH: prints only the HTTP status
status_of() {
  curl -sS -o /dev/null -w '%{http_code}' -X "$1" -H "Authorization: Bearer $GARAGE_ADMIN_TOKEN" \
    "$GARAGE_ADMIN_URL/v2/$2"
}

until [ "$(status_of GET GetClusterStatus)" = "200" ]; do
  echo "garage: waiting for the admin API..."
  sleep 2
done

node_id=$(api GET GetClusterStatus | jq -r '.nodes[0].id')
node_role=$(api GET GetClusterStatus | jq -r '.nodes[0].role')
if [ "$node_role" = "null" ]; then
  api POST UpdateClusterLayout "{\"roles\":[{\"id\":\"$node_id\",\"zone\":\"dev\",\"capacity\":10737418240,\"tags\":[]}]}" >/dev/null
  version=$(api GET GetClusterLayout | jq -r '.version')
  api POST ApplyClusterLayout "{\"version\":$((version + 1))}" >/dev/null
  echo "garage: node given its storage role"
fi

if [ "$(status_of GET "GetKeyInfo?id=$S3_DEV_ACCESS_KEY")" = "404" ]; then
  api POST ImportKey "{\"accessKeyId\":\"$S3_DEV_ACCESS_KEY\",\"secretAccessKey\":\"$S3_DEV_SECRET_KEY\",\"name\":\"phuppi\"}" >/dev/null
  echo "garage: key $S3_DEV_ACCESS_KEY imported"
fi

if [ "$(status_of GET "GetBucketInfo?globalAlias=$S3_DEV_BUCKET")" = "404" ]; then
  api POST CreateBucket "{\"globalAlias\":\"$S3_DEV_BUCKET\"}" >/dev/null
  echo "garage: bucket $S3_DEV_BUCKET created"
fi
bucket_id=$(api GET "GetBucketInfo?globalAlias=$S3_DEV_BUCKET" | jq -r '.id')
api POST AllowBucketKey "{\"bucketId\":\"$bucket_id\",\"accessKeyId\":\"$S3_DEV_ACCESS_KEY\",\"permissions\":{\"read\":true,\"write\":true,\"owner\":true}}" >/dev/null

# Browsers upload straight to the bucket through presigned URLs, so the bucket needs CORS rules.
# Garage has none by default (MinIO allowed everything). Development only: any origin may use it.
cors='<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod><AllowedMethod>PUT</AllowedMethod><AllowedMethod>HEAD</AllowedMethod><AllowedHeader>*</AllowedHeader><ExposeHeader>ETag</ExposeHeader><MaxAgeSeconds>3600</MaxAgeSeconds></CORSRule></CORSConfiguration>'
cors_status=$(printf '%s' "$cors" | curl -sS -o /dev/null -w '%{http_code}' -X PUT \
  --aws-sigv4 "aws:amz:us-east-1:s3" --user "$S3_DEV_ACCESS_KEY:$S3_DEV_SECRET_KEY" \
  -H "Content-Type: application/xml" -H "Content-MD5: $(printf '%s' "$cors" | openssl md5 -binary | base64)" \
  --data-binary @- "$GARAGE_S3_URL/$S3_DEV_BUCKET?cors")
[ "$cors_status" = "200" ] || { echo "garage: setting bucket CORS failed (HTTP $cors_status)"; exit 1; }

echo "garage: ready"
