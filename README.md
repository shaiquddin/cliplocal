# ClipLocal

ClipLocal is a private, local-first video cutter built with PHP, yt-dlp, Deno,
and FFmpeg. Select an exact interval from a local video or a permitted YouTube
source, choose an output format and resolution, and stream the finished clip
directly to your browser.

The application does not retain source copies, intermediate media, or finished
clips.

## Features

- Exact start and end timestamps with a dual-handle timeline
- Local video preview and YouTube timestamp preview
- YouTube clipping and download through yt-dlp
- 480p, 720p HD, and 1080p Full HD output
- MP4, WebM, WMV, and MKV export
- Aspect-ratio preservation without enlarging smaller sources
- Read-only local-media mount
- Streamed processing with no retained media output
- Responsive browser interface
- Localhost-only request and network binding
- No browser cookies, Google credentials, proxy rotation, or account import

## Requirements

- Docker Desktop on Windows or macOS, or Docker Engine on Linux
- Docker Compose v2

PHP, Python, yt-dlp, Deno, FFmpeg, and FFprobe are installed inside the
application image.

## Quick start

Clone the repository and create the local environment file:

```sh
git clone https://github.com/shaiquddin/cliplocal.git
cd cliplocal
cp .env.example .env
```

Edit `.env` if you want to mount an existing media directory:

```dotenv
MEDIA_DIR=./media
```

Windows paths should use forward slashes:

```dotenv
MEDIA_DIR=E:/Videos
```

Start the application:

```sh
docker compose up --build -d
```

On Windows, the included helper performs the same operation:

```powershell
./start.ps1
```

Open <http://localhost:8080>.

Stop the application with:

```sh
docker compose down
```

or on Windows:

```powershell
./stop.ps1
```

## Processing model

```text
Local:   read-only source file -> FFmpeg -> HTTP response -> browser download
YouTube: yt-dlp -> pipe -> FFmpeg -> HTTP response -> browser download
```

- Local sources are read directly from the mounted folder.
- yt-dlp writes YouTube media bytes to a pipe instead of an output pathname.
- FFmpeg reads from the source or pipe and writes the encoded clip to standard
  output.
- PHP forwards that stream to the browser response.
- The container root filesystem is read-only and `/tmp` is RAM-backed.
- The finished clip exists only where you choose to save it in the browser.

Because the response is generated as a stream, the browser may not display a
known file size while the clip is processing. Do not close the page or stop the
container during an active export.

## Output formats

| Format | Video and audio | Notes |
| --- | --- | --- |
| MP4 | H.264 and AAC | Fragmented MP4 for broad compatibility |
| WebM | VP9 and Opus | Modern web format; encoding may be slower |
| WMV | WMV2 and WMA | Intended for legacy Windows workflows |
| MKV | H.264 and AAC | Flexible Matroska container |

The selected resolution is a maximum height. ClipLocal preserves the source
aspect ratio and never enlarges a smaller source.

## Privacy and security boundaries

ClipLocal is intended for a trusted local machine:

- Docker publishes the service only on `127.0.0.1:8080`.
- PHP rejects non-local request hosts and cross-site origins.
- The container runs without Linux capabilities and with a read-only root
  filesystem.
- The local-media directory is mounted read-only.
- YouTube retrieval does not use account cookies or Google credentials.

Do not expose the application directly to the public internet. A remote
deployment requires authentication, HTTPS, request limits, trusted-host
configuration, and appropriately isolated processing resources.

## YouTube behavior and permitted use

The YouTube tab uses the official embedded player for timestamp selection and
the unofficial [yt-dlp](https://github.com/yt-dlp/yt-dlp) project for media
retrieval. ClipLocal does not attempt to access private, paid, members-only,
DRM-protected, geographically restricted, or login-protected media.

YouTube can change its delivery mechanisms, require additional tokens, throttle
requests, reject an IP address, or make a video unavailable without notice.
When extraction stops working, rebuild without the cached dependency layer:

```sh
docker compose build --no-cache
docker compose up -d
```

Downloading may be restricted by YouTube's terms and by applicable copyright
law. Use ClipLocal only with media you own or are legally authorized to
download and process. You are responsible for the source and intended use.
ClipLocal is not affiliated with or endorsed by YouTube.

## Tests

Build the image, check all PHP files, and run the unit suite:

```sh
docker compose build
docker compose run --rm --no-deps cliplocal sh -lc \
  "find /app -name '*.php' -print0 | xargs -0 -n1 php -l && php /app/tests/unit.php"
```

The local end-to-end suite expects a six-second test video at
`media/synthetic.mp4` and a running application:

```sh
docker compose up -d
docker compose exec -T cliplocal php /app/tests/integration.php
```

The YouTube integration check is deliberately opt-in. Set `TEST_YOUTUBE_URL`
to a public video you own or are authorized to test:

```sh
docker compose exec -T \
  -e TEST_YOUTUBE_URL="https://www.youtube.com/watch?v=VIDEO_ID" \
  cliplocal php /app/tests/youtube-integration.php
```

Continuous integration runs syntax and unit checks only. It does not contact
YouTube.

## Troubleshooting

- **`localhost:8080` refuses the connection:** Start Docker, then run
  `docker compose up -d`.
- **Port 8080 is already in use:** Stop the conflicting service before starting
  ClipLocal.
- **No local videos appear:** Verify `MEDIA_DIR`, then recreate the container
  with `docker compose up -d`.
- **YouTube metadata or download fails:** Rebuild without cache to install the
  current yt-dlp release. YouTube may still reject a source or IP address.
- **Encoding is slow:** Video conversion is CPU-intensive, particularly WebM
  and high-resolution output.

Inspect service logs with:

```sh
docker compose logs --tail 200 cliplocal
```

## Core components

- [PHP](https://www.php.net/) serves the local interface and streams responses.
- [FFmpeg](https://ffmpeg.org/) probes, clips, scales, and encodes media.
- [yt-dlp](https://github.com/yt-dlp/yt-dlp) retrieves permitted YouTube media.
- [Deno](https://deno.com/) provides the JavaScript runtime used by current
  YouTube extraction support.

These projects retain their own licenses and are installed into the Docker image
from their respective distributions.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change. Report
suspected vulnerabilities privately according to [SECURITY.md](SECURITY.md).

## License

ClipLocal is available under the [MIT License](LICENSE).
