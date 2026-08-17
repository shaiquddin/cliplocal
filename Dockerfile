FROM denoland/deno:bin-2.9.4 AS deno

FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg python3 python3-pip \
    && python3 -m pip install --break-system-packages --no-cache-dir "yt-dlp[default]" \
    && rm -rf /var/lib/apt/lists/*

COPY --from=deno /deno /usr/local/bin/deno

COPY php.ini /usr/local/etc/php/conf.d/cliplocal.ini
COPY public /app/public
COPY src /app/src
COPY tests /app/tests

WORKDIR /app
USER www-data

ENV MEDIA_ROOT=/media \
    FFMPEG_BIN=/usr/bin/ffmpeg \
    FFPROBE_BIN=/usr/bin/ffprobe \
    YTDLP_BIN=/usr/local/bin/yt-dlp \
    DENO_BIN=/usr/local/bin/deno \
    DENO_DIR=/tmp/deno \
    HOME=/tmp \
    PHP_CLI_SERVER_WORKERS=4

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public"]
