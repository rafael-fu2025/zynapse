# Kiosk media security and deployment

Kiosk playlist settings remain local to each browser device. The application stores only configuration, announcement text, and media URLs in `localStorage`; it never stores image/video binaries or base64 data there. Administrators with `rbac.manage` may upload approved files to the authenticated server-backed media gallery.

## Accepted sources

- Same-origin public paths beginning with `/`, such as `/media/orientation.mp4`.
- `https://` URLs.
- An `http://` URL only when its origin exactly matches the currently loaded application (primarily local development).

The application rejects protocol-relative URLs and unsafe schemes such as `data:`, `javascript:`, `blob:`, and `file:`. Announcement content is rendered as React text and is never inserted as raw HTML.

## Content Security Policy

URL validation does not override the browser's Content Security Policy. Prefer deploying media as same-origin public assets. If an approved external host is required, add only that host to the deployment's relevant `img-src` or `media-src` directive after security review. Do not add a wildcard source or weaken the global policy for arbitrary playlist domains.

## Uploads and centralized management

Uploads are restricted to server-detected JPEG, PNG, WebP, GIF, MP4, and WebM content, and the application imposes no file-size ceiling. The bundled PHP development runtime uses a 1 TB request ceiling—larger than realistic local storage—because PHP interprets `post_max_size=0` as a zero-byte limit for JSON API requests. Production web servers and reverse proxies should likewise use a ceiling beyond available storage or provide an upload-specific unlimited route. Metadata is tenant-scoped, mutations require `rbac.manage`, and upload/archive/restore actions are audited. Gallery removal is a recoverable archive: the unguessable public content URL remains readable so a playlist saved on another kiosk does not break.

Photo and video uploads use the executable configured by `FFMPEG_BINARY` (default `ffmpeg`) to create a 640×360 JPEG gallery poster under the public, UUID-addressed `/kiosk-thumbnails/` path. Existing media is backfilled lazily on its first thumbnail request. Serving posters as static files avoids loading full videos or booting the API/database for each gallery card. Thumbnail generation failure never invalidates the original uploaded media.

During local development, Vite reads posters directly from the backend public directory. This bypasses the single-threaded PHP development server, so previews cannot wait behind uploads or API requests. Poster responses use a one-year immutable browser cache.

Files are stored below the backend writable directory and served through `/api/v1/kiosk-media/{uuid}/content`; original filenames are never used as storage paths. Production deployments should add antivirus/content scanning at the upload boundary if required by institutional policy.

The playlist itself remains device-local. Centrally synchronized playlists would still require a separate settings API and conflict/version policy.
