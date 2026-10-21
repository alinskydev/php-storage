# PHP Storage

A small file storage service built on [Slim 4](https://www.slimframework.com/) for [Larbox 2](https://github.com/alinskydev/larbox-2). It accepts image and document uploads, organizes them into date-based folders, and returns links to the originals and their thumbnails.

## Requirements

- PHP 8.0+ with the `gd`, `fileinfo` and `mbstring` extensions
- Composer

## Installation

```bash
composer install
cp .env.example .env
```

Fill in `.env`:

| Variable           | Description                                                                   |
|--------------------|-------------------------------------------------------------------------------|
| `IS_DEBUG_ENABLED` | Detailed Slim error output. `1` to enable, `0` to disable                     |
| `SECRET_KEY`       | Access key that the client sends in the `Storage-Secret-Key` header           |

## Authorization

Every request must include the header:

```
Storage-Secret-Key: <SECRET_KEY>
```

Requests with a missing or invalid key are rejected.

## Response format

All responses are JSON. Errors are returned with status `400`:

```json
{ "message": "Error message" }
```

## API

### `GET /welcome`

Checks that the service is up.

```json
{ "message": "Welcome!" }
```

### `POST /api/upload`

Uploads files. The request body is `multipart/form-data`.

| Field     | Required | Description                                                                         |
|-----------|----------|-------------------------------------------------------------------------------------|
| `files[]` | yes      | 1 to 10 files                                                                       |
| `type`    | yes      | `images` or `files`                                                                 |
| `folder`  | no       | Subfolder to save into, `all` by default                                            |
| `size`    | no       | `images` only: maximum dimensions as `WIDTHxHEIGHT`, for example `1920x1080`        |

Limits by type:

| `type`   | Extensions            | Max file size |
|----------|-----------------------|---------------|
| `images` | `jpg`, `jpeg`, `png`  | 2 MB          |
| `files`  | `pdf`, `docx`, `xlsx` | 10 MB         |

If `size` is provided, the image is scaled down proportionally: landscape images are fitted to the width, portrait images to the height. Images smaller than the given size are not enlarged.

Files are saved to `public/storage/<type>/<folder>/YYYY/MM/DD/` under a unique name.

Example:

```bash
curl -X POST https://php-storage.loc/api/upload \
  -H "Storage-Secret-Key: <SECRET_KEY>" \
  -F "type=images" \
  -F "folder=avatars" \
  -F "size=1920x1080" \
  -F "files[]=@photo.jpg"
```

Response:

```json
{
  "files": [
    "/storage/images/avatars/2026/10/20/6714f0c2a1b3e_9b2f...e1.jpg"
  ]
}
```

### `POST /api/get/images`

Returns links to images and their scaled-down copies. The request body is `application/x-www-form-urlencoded` or `multipart/form-data`.

| Field              | Required | Description                                                          |
|--------------------|----------|----------------------------------------------------------------------|
| `files[N][path]`   | yes      | File path returned by `/api/upload` (1 to 10 files)                  |
| `files[N][action]` | yes      | Action to apply to the image. Only `scaleDown` is currently supported |
| `files[N][sizes]`  | no       | Thumbnail widths separated by `\|`, from 1 to 1920, for example `300\|600` |

`scaleDown` scales the image down to the given width, preserving the aspect ratio. Thumbnails are cached in `public/storage/thumbs/scaleDown/<width>/` and regenerated when the source file changes. If the source image is already narrower than the requested width, a link to the original is returned instead of a thumbnail.

Example:

```bash
curl -X POST https://php-storage.loc/api/get/images \
  -H "Storage-Secret-Key: <SECRET_KEY>" \
  -d "files[0][path]=storage/images/avatars/2026/10/20/photo.jpg" \
  -d "files[0][action]=scaleDown" \
  -d "files[0][sizes]=300|600"
```

Response (keys match the indexes from the request):

```json
{
  "files": {
    "0": {
      "original":    { "raw": "https://php-storage.loc/storage/images/avatars/2026/10/20/photo.jpg" },
      "scaleDown_300": { "raw": "https://php-storage.loc/..." },
      "scaleDown_600": { "raw": "https://php-storage.loc/..." }
    }
  }
}
```

If a file is not found or a thumbnail cannot be created, no error is returned; the response contains a link to the original instead.
