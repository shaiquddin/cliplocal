<?php

declare(strict_types=1);

header("Content-Security-Policy: default-src 'self'; script-src 'self' https://www.youtube.com; style-src 'self'; img-src 'self' data: https://i.ytimg.com; media-src 'self'; frame-src https://www.youtube-nocookie.com https://www.youtube.com; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>ClipLocal — Private local video cutter</title>
    <meta name="description" content="Trim local and YouTube videos with streamed output and no retained media.">
    <link rel="stylesheet" href="/assets/styles.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
    <header class="site-header">
        <a class="brand" href="/" aria-label="ClipLocal home">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 36 36" role="img"><path d="M9 7.5v21l19-10.5L9 7.5Z"/><path d="M6.5 6.5 29.5 29.5M29.5 6.5 6.5 29.5"/></svg>
            </span>
            <span>ClipLocal</span>
        </a>
        <div class="privacy-pill"><span class="status-dot"></span> Local-only · zero retention</div>
    </header>

    <main>
        <section class="hero">
            <p class="eyebrow">A private cutter for media you own</p>
            <h1>Find the moment.<br><span>Keep only the clip.</span></h1>
            <p class="hero-copy">Turn a local source or YouTube URL into a precise downloadable clip. Processing is streamed, and the app never keeps a media copy.</p>
        </section>

        <section class="workspace" aria-labelledby="source-title">
            <div class="workspace-head">
                <div>
                    <p class="section-kicker">01 · Source</p>
                    <h2 id="source-title">Choose where to start</h2>
                </div>
                <div class="mode-switch" role="tablist" aria-label="Video source">
                    <button class="mode-tab active" type="button" role="tab" aria-selected="true" aria-controls="local-panel" data-mode="local">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h6l1.5 2H20v11H4v-13Z"/></svg>
                        Local media
                    </button>
                    <button class="mode-tab" type="button" role="tab" aria-selected="false" aria-controls="youtube-panel" data-mode="youtube">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12s0-4.5-.6-6c-.3-.8-.9-1.4-1.7-1.6C17.2 4 12 4 12 4s-5.2 0-6.7.4c-.8.2-1.4.8-1.7 1.6C3 7.5 3 12 3 12s0 4.5.6 6c.3.8.9 1.4 1.7 1.6 1.5.4 6.7.4 6.7.4s5.2 0 6.7-.4c.8-.2 1.4-.8 1.7-1.6.6-1.5.6-6 .6-6Z"/><path class="fill" d="m10 9 5 3-5 3V9Z"/></svg>
                        YouTube download
                    </button>
                </div>
            </div>

            <div id="local-panel" class="source-panel" role="tabpanel">
                <div class="source-controls">
                    <label for="media-select">Read-only media folder</label>
                    <div class="control-row">
                        <div class="select-wrap grow">
                            <select id="media-select">
                                <option value="">Loading your media…</option>
                            </select>
                        </div>
                        <button id="refresh-media" class="button secondary" type="button" title="Refresh media list">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6"/></svg>
                            Refresh
                        </button>
                    </div>
                    <p id="media-hint" class="field-note">Files are read directly from the folder mounted in <code>MEDIA_DIR</code>. Nothing is uploaded or copied.</p>
                </div>

                <div id="local-empty" class="empty-player">
                    <div class="empty-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8.5 7.5 8 4.5-8 4.5v-9Z"/><rect x="3" y="4" width="18" height="16" rx="3"/></svg></div>
                    <p>Select a video from your mounted folder</p>
                </div>
                <div id="local-player-wrap" class="player-shell hidden">
                    <video id="local-player" controls preload="metadata" playsinline></video>
                    <div id="media-badge" class="media-badge"></div>
                </div>
            </div>

            <div id="youtube-panel" class="source-panel hidden" role="tabpanel">
                <div class="source-controls">
                    <label for="youtube-url">YouTube video URL</label>
                    <div class="control-row">
                        <input id="youtube-url" class="grow" type="url" inputmode="url" autocomplete="off" placeholder="https://www.youtube.com/watch?v=…">
                        <button id="load-youtube" class="button secondary" type="button">Load video</button>
                    </div>
                    <p class="field-note warning-note"><span aria-hidden="true">◇</span> Uses unofficial yt-dlp retrieval. YouTube may throttle or reject downloads; no account cookies are used.</p>
                    <p id="youtube-status" class="field-note" role="status" aria-live="polite"></p>
                </div>
                <div id="youtube-empty" class="empty-player">
                    <div class="empty-icon youtube-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12s0-4.5-.6-6c-.3-.8-.9-1.4-1.7-1.6C17.2 4 12 4 12 4s-5.2 0-6.7.4c-.8.2-1.4.8-1.7 1.6C3 7.5 3 12 3 12s0 4.5.6 6c.3.8.9 1.4 1.7 1.6 1.5.4 6.7.4 6.7.4s5.2 0 6.7-.4c.8-.2 1.4-.8 1.7-1.6.6-1.5.6-6 .6-6Z"/><path d="m10 9 5 3-5 3V9Z"/></svg></div>
                    <p>Paste a YouTube URL to load and download a clip</p>
                </div>
                <div id="youtube-player-wrap" class="player-shell hidden">
                    <div id="youtube-player"></div>
                </div>
            </div>
        </section>

        <section id="trim-section" class="workspace editor-workspace is-disabled" aria-labelledby="trim-title">
            <div class="workspace-head compact">
                <div>
                    <p class="section-kicker">02 · Trim</p>
                    <h2 id="trim-title">Mark your moment</h2>
                </div>
                <div id="clip-duration" class="duration-chip">00:00 selected</div>
            </div>

            <div class="timeline-wrap">
                <div id="selection-track" class="selection-track">
                    <input id="start-range" class="range range-start" type="range" min="0" max="100" step="0.1" value="0" aria-label="Clip start">
                    <input id="end-range" class="range range-end" type="range" min="0" max="100" step="0.1" value="100" aria-label="Clip end">
                </div>
                <div class="timeline-labels"><span>00:00</span><span id="source-duration">00:00</span></div>
            </div>

            <div class="time-grid">
                <div class="time-card">
                    <div class="time-label"><span class="start-marker"></span> Start</div>
                    <input id="start-time" type="text" inputmode="numeric" value="00:00:00.000" aria-label="Start timestamp">
                    <button class="text-button" id="set-start-current" type="button">Use current time</button>
                </div>
                <div class="time-card">
                    <div class="time-label"><span class="end-marker"></span> End</div>
                    <input id="end-time" type="text" inputmode="numeric" value="00:00:00.000" aria-label="End timestamp">
                    <button class="text-button" id="set-end-current" type="button">Use current time</button>
                </div>
                <button id="preview-selection" class="preview-button" type="button">
                    <span class="preview-play"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 7 8 5-8 5V7Z"/></svg></span>
                    <span><strong>Preview selection</strong><small>Plays from start to end</small></span>
                </button>
            </div>
            <p id="editor-message" class="message" role="status" aria-live="polite"></p>
        </section>

        <section id="output-section" class="workspace output-workspace is-disabled" aria-labelledby="output-title">
            <div class="workspace-head compact">
                <div>
                    <p class="section-kicker">03 · Output</p>
                    <h2 id="output-title">Choose the finish</h2>
                </div>
                <span class="streaming-badge"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"/></svg> Streams directly</span>
            </div>

            <div class="output-grid">
                <fieldset class="resolution-fieldset">
                    <legend>Maximum output resolution</legend>
                    <div class="resolution-options">
                        <label class="resolution-option">
                            <input type="radio" name="resolution-choice" value="480">
                            <span><strong>480p</strong><small>SD · smallest</small></span>
                        </label>
                        <label class="resolution-option selected">
                            <input type="radio" name="resolution-choice" value="720" checked>
                            <span><strong>720p</strong><small>HD · balanced</small></span>
                        </label>
                        <label class="resolution-option">
                            <input type="radio" name="resolution-choice" value="1080">
                            <span><strong>1080p</strong><small>Full HD · largest</small></span>
                        </label>
                    </div>
                    <p class="field-note">Smaller sources are never enlarged.</p>
                </fieldset>

                <div class="format-field">
                    <label for="format-select">Video format</label>
                    <div class="select-wrap">
                        <select id="format-select">
                            <option value="mp4">MP4 — recommended</option>
                            <option value="webm">WebM — modern web</option>
                            <option value="wmv">WMV — Windows legacy</option>
                            <option value="mkv">MKV — flexible container</option>
                        </select>
                    </div>
                    <p id="format-note" class="field-note">H.264 video with AAC audio. Best compatibility.</p>
                </div>
            </div>

            <div class="download-row">
                <div class="retention-note">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s8-3.5 8-10V5l-8-3-8 3v6c0 6.5 8 10 8 10Z"/><path d="m8.5 11.5 2.2 2.2 4.8-5"/></svg>
                    <span><strong>No retained copy</strong><small>FFmpeg streams the clip straight to your browser.</small></span>
                </div>
                <form id="clip-form" action="/api/clip.php" method="post">
                    <input id="form-media" type="hidden" name="media">
                    <input id="form-youtube-url" type="hidden" name="url">
                    <input id="form-source-duration" type="hidden" name="source_duration">
                    <input id="form-title" type="hidden" name="title">
                    <input id="form-start" type="hidden" name="start">
                    <input id="form-end" type="hidden" name="end">
                    <input id="form-resolution" type="hidden" name="resolution" value="720">
                    <input id="form-format" type="hidden" name="format" value="mp4">
                    <button id="download-clip" class="button primary" type="submit">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"/></svg>
                        Stream &amp; download clip
                    </button>
                </form>
            </div>
        </section>

    </main>

    <footer>
        <span>ClipLocal</span>
        <span>Streamed processing · No retained media · Anonymous YouTube retrieval</span>
    </footer>
</body>
</html>
