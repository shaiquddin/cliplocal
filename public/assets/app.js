(() => {
    'use strict';

    const $ = (selector) => document.querySelector(selector);
    const $$ = (selector) => [...document.querySelectorAll(selector)];

    const elements = {
        modeTabs: $$('.mode-tab'),
        localPanel: $('#local-panel'),
        youtubePanel: $('#youtube-panel'),
        mediaSelect: $('#media-select'),
        refreshMedia: $('#refresh-media'),
        mediaHint: $('#media-hint'),
        localEmpty: $('#local-empty'),
        localWrap: $('#local-player-wrap'),
        localPlayer: $('#local-player'),
        mediaBadge: $('#media-badge'),
        youtubeUrl: $('#youtube-url'),
        loadYoutube: $('#load-youtube'),
        youtubeStatus: $('#youtube-status'),
        youtubeEmpty: $('#youtube-empty'),
        youtubeWrap: $('#youtube-player-wrap'),
        trimSection: $('#trim-section'),
        outputSection: $('#output-section'),
        startRange: $('#start-range'),
        endRange: $('#end-range'),
        selectionTrack: $('#selection-track'),
        startTime: $('#start-time'),
        endTime: $('#end-time'),
        sourceDuration: $('#source-duration'),
        clipDuration: $('#clip-duration'),
        editorMessage: $('#editor-message'),
        setStartCurrent: $('#set-start-current'),
        setEndCurrent: $('#set-end-current'),
        preview: $('#preview-selection'),
        format: $('#format-select'),
        formatNote: $('#format-note'),
        clipForm: $('#clip-form'),
        formMedia: $('#form-media'),
        formYoutubeUrl: $('#form-youtube-url'),
        formSourceDuration: $('#form-source-duration'),
        formTitle: $('#form-title'),
        formStart: $('#form-start'),
        formEnd: $('#form-end'),
        formResolution: $('#form-resolution'),
        formFormat: $('#form-format'),
        download: $('#download-clip'),
    };

    const state = {
        mode: 'local',
        local: { ready: false, file: '', duration: 0, start: 0, end: 0 },
        youtube: { ready: false, id: '', url: '', title: '', duration: 0, start: 0, end: 0 },
        youtubePlayer: null,
        youtubeApiPromise: null,
        previewTimer: null,
    };

    const formatNotes = {
        mp4: 'H.264 video with AAC audio. Best compatibility.',
        webm: 'VP9 video with Opus audio. Efficient, but slower to encode.',
        wmv: 'Legacy Windows Media format. Use only for older software.',
        mkv: 'H.264 and AAC in a flexible Matroska container.',
    };

    function activeSource() {
        return state[state.mode];
    }

    function formatTime(seconds, milliseconds = true) {
        const safe = Math.max(0, Number.isFinite(seconds) ? seconds : 0);
        const hours = Math.floor(safe / 3600);
        const minutes = Math.floor((safe % 3600) / 60);
        const wholeSeconds = Math.floor(safe % 60);
        const millis = Math.round((safe - Math.floor(safe)) * 1000) % 1000;
        const base = [hours, minutes, wholeSeconds].map((value) => String(value).padStart(2, '0')).join(':');
        return milliseconds ? `${base}.${String(millis).padStart(3, '0')}` : (hours > 0 ? base : base.slice(3));
    }

    function parseTime(value) {
        const clean = String(value).trim();
        if (/^\d+(?:\.\d+)?$/.test(clean)) return Number(clean);
        const parts = clean.split(':');
        if (parts.length < 2 || parts.length > 3 || parts.some((part) => !/^\d+(?:\.\d+)?$/.test(part))) return NaN;
        let seconds = 0;
        for (const part of parts) seconds = (seconds * 60) + Number(part);
        return seconds;
    }

    function formatBytes(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const power = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return `${(bytes / (1024 ** power)).toFixed(power > 1 ? 1 : 0)} ${units[power]}`;
    }

    function showMessage(message = '', error = false) {
        elements.editorMessage.textContent = message;
        elements.editorMessage.style.color = error ? '#b15444' : '#14785f';
    }

    function stopPreview() {
        if (state.previewTimer) {
            clearInterval(state.previewTimer);
            state.previewTimer = null;
        }
    }

    function pauseActive() {
        stopPreview();
        if (state.mode === 'local') elements.localPlayer.pause();
        if (state.youtubePlayer?.pauseVideo) state.youtubePlayer.pauseVideo();
    }

    function currentTime() {
        if (state.mode === 'local') return elements.localPlayer.currentTime || 0;
        if (state.youtubePlayer?.getCurrentTime) return state.youtubePlayer.getCurrentTime() || 0;
        return 0;
    }

    function configureEditor(source) {
        const ready = source.ready && source.duration > 0;
        elements.trimSection.classList.toggle('is-disabled', !ready);
        elements.outputSection.classList.toggle('is-disabled', !ready);

        if (!ready) return;
        elements.startRange.max = String(source.duration);
        elements.endRange.max = String(source.duration);
        elements.startRange.value = String(source.start);
        elements.endRange.value = String(source.end);
        renderTimeline();
    }

    function renderTimeline() {
        const source = activeSource();
        if (!source.ready || source.duration <= 0) return;
        const startPercent = (source.start / source.duration) * 100;
        const endPercent = (source.end / source.duration) * 100;
        elements.selectionTrack.style.setProperty('--start', `${startPercent}%`);
        elements.selectionTrack.style.setProperty('--end', `${endPercent}%`);
        elements.startRange.value = String(source.start);
        elements.endRange.value = String(source.end);
        elements.startTime.value = formatTime(source.start);
        elements.endTime.value = formatTime(source.end);
        elements.sourceDuration.textContent = formatTime(source.duration, false);
        elements.clipDuration.textContent = `${formatTime(source.end - source.start, false)} selected`;

        if (state.mode === 'local') {
            elements.formMedia.value = source.file;
            elements.formYoutubeUrl.value = '';
            elements.formTitle.value = '';
        } else {
            elements.formMedia.value = '';
            elements.formYoutubeUrl.value = source.url;
            elements.formTitle.value = source.title;
        }
        elements.formSourceDuration.value = source.duration.toFixed(3);
        elements.formStart.value = source.start.toFixed(3);
        elements.formEnd.value = source.end.toFixed(3);
    }

    function initializeSelection(source, duration) {
        source.duration = duration;
        source.start = 0;
        source.end = Math.min(duration, 30);
        source.ready = true;
        configureEditor(source);
        showMessage('');
    }

    async function loadMediaList(preserveSelection = true) {
        const previous = preserveSelection ? elements.mediaSelect.value : '';
        elements.mediaSelect.disabled = true;
        elements.mediaSelect.innerHTML = '<option value="">Loading your media…</option>';

        try {
            const response = await fetch('/api/media.php', { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Could not read the media folder.');

            elements.mediaSelect.innerHTML = '';
            const prompt = document.createElement('option');
            prompt.value = '';
            prompt.textContent = data.media.length ? 'Select a video…' : 'No supported videos found';
            elements.mediaSelect.append(prompt);

            for (const item of data.media) {
                const option = document.createElement('option');
                option.value = item.path;
                option.textContent = `${item.path} · ${formatBytes(item.size)}`;
                elements.mediaSelect.append(option);
            }

            if (previous && data.media.some((item) => item.path === previous)) {
                elements.mediaSelect.value = previous;
            }
            elements.mediaHint.textContent = data.media.length
                ? `${data.media.length} supported video${data.media.length === 1 ? '' : 's'} found. The folder is mounted read-only.`
                : 'Add a video to MEDIA_DIR, then press Refresh. The folder is mounted read-only.';
        } catch (error) {
            elements.mediaSelect.innerHTML = '<option value="">Media folder unavailable</option>';
            elements.mediaHint.textContent = error.message;
        } finally {
            elements.mediaSelect.disabled = false;
        }
    }

    async function selectLocalMedia(relativePath) {
        pauseActive();
        state.local.ready = false;
        state.local.file = '';
        configureEditor(state.local);

        if (!relativePath) {
            elements.localPlayer.removeAttribute('src');
            elements.localPlayer.load();
            elements.localWrap.classList.add('hidden');
            elements.localEmpty.classList.remove('hidden');
            return;
        }

        elements.mediaHint.textContent = 'Reading media details…';
        try {
            const response = await fetch(`/api/media-info.php?file=${encodeURIComponent(relativePath)}`, { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'This file could not be opened.');

            state.local.file = relativePath;
            initializeSelection(state.local, Number(data.duration));
            elements.localPlayer.src = `/media.php?file=${encodeURIComponent(relativePath)}`;
            elements.localWrap.classList.remove('hidden');
            elements.localEmpty.classList.add('hidden');
            elements.mediaBadge.textContent = `${data.width}×${data.height} · ${String(data.videoCodec).toUpperCase()}${data.audioCodec ? ` + ${String(data.audioCodec).toUpperCase()}` : ''}`;
            elements.mediaHint.textContent = `${data.name} · ${formatTime(data.duration, false)} · read-only source`;
        } catch (error) {
            elements.mediaHint.textContent = error.message;
            showMessage(error.message, true);
        }
    }

    function extractYoutubeId(value) {
        try {
            const url = new URL(value.trim());
            const host = url.hostname.toLowerCase().replace(/^www\./, '');
            let id = '';
            if (host === 'youtu.be') id = url.pathname.split('/').filter(Boolean)[0] || '';
            if (['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'].includes(host)) {
                if (url.pathname === '/watch') id = url.searchParams.get('v') || '';
                else id = url.pathname.match(/^\/(?:shorts|embed|live)\/([^/?]+)/)?.[1] || '';
            }
            return /^[A-Za-z0-9_-]{11}$/.test(id) ? id : null;
        } catch {
            return null;
        }
    }

    function loadYoutubeApi() {
        if (window.YT?.Player) return Promise.resolve();
        if (state.youtubeApiPromise) return state.youtubeApiPromise;
        state.youtubeApiPromise = new Promise((resolve) => {
            window.onYouTubeIframeAPIReady = resolve;
            const script = document.createElement('script');
            script.src = 'https://www.youtube.com/iframe_api';
            script.referrerPolicy = 'strict-origin-when-cross-origin';
            document.head.append(script);
        });
        return state.youtubeApiPromise;
    }

    async function loadYoutubeVideo() {
        const id = extractYoutubeId(elements.youtubeUrl.value);
        if (!id) {
            showMessage('Enter a valid YouTube video URL.', true);
            elements.youtubeUrl.focus();
            return;
        }

        pauseActive();
        state.youtube.ready = false;
        state.youtube.id = id;
        state.youtube.url = elements.youtubeUrl.value.trim();
        state.youtube.title = '';
        configureEditor(state.youtube);
        elements.loadYoutube.disabled = true;
        elements.loadYoutube.textContent = 'Checking…';
        elements.youtubeStatus.textContent = 'Retrieving video information with yt-dlp…';

        try {
            const response = await fetch(`/api/youtube-info.php?url=${encodeURIComponent(elements.youtubeUrl.value.trim())}`, { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'YouTube video information could not be retrieved.');

            state.youtube.id = data.id;
            state.youtube.url = data.url;
            state.youtube.title = data.title;
            initializeSelection(state.youtube, Number(data.duration));
            elements.youtubeStatus.textContent = `${data.title}${data.channel ? ` · ${data.channel}` : ''} · ${formatTime(Number(data.duration), false)}`;

            await loadYoutubeApi();
            if (state.youtubePlayer?.destroy) state.youtubePlayer.destroy();
            const oldTarget = $('#youtube-player');
            if (!oldTarget) {
                const replacement = document.createElement('div');
                replacement.id = 'youtube-player';
                elements.youtubeWrap.append(replacement);
            }

            state.youtubePlayer = new window.YT.Player('youtube-player', {
                videoId: id,
                host: 'https://www.youtube-nocookie.com',
                playerVars: { playsinline: 1, rel: 0, modestbranding: 1 },
                events: {
                    onReady: (event) => {
                        const duration = Number(event.target.getDuration());
                        if (!state.youtube.ready && duration > 0) initializeSelection(state.youtube, duration);
                        elements.youtubeWrap.classList.remove('hidden');
                        elements.youtubeEmpty.classList.add('hidden');
                    },
                    onError: () => showMessage('YouTube could not play this video in the embedded player.', true),
                },
            });
        } catch (error) {
            elements.youtubeStatus.textContent = error.message;
            showMessage(error.message, true);
        } finally {
            elements.loadYoutube.disabled = false;
            elements.loadYoutube.textContent = 'Load video';
        }
    }

    function switchMode(mode) {
        if (mode === state.mode) return;
        pauseActive();
        state.mode = mode;
        for (const tab of elements.modeTabs) {
            const active = tab.dataset.mode === mode;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', String(active));
        }
        elements.localPanel.classList.toggle('hidden', mode !== 'local');
        elements.youtubePanel.classList.toggle('hidden', mode !== 'youtube');
        configureEditor(activeSource());
        showMessage('');
    }

    function updateRange(changed) {
        const source = activeSource();
        const minimumGap = Math.min(0.1, source.duration);
        let start = Number(elements.startRange.value);
        let end = Number(elements.endRange.value);
        if (changed === 'start' && start >= end) start = Math.max(0, end - minimumGap);
        if (changed === 'end' && end <= start) end = Math.min(source.duration, start + minimumGap);
        source.start = start;
        source.end = end;
        renderTimeline();
    }

    function updateTimeInput(which) {
        const source = activeSource();
        const input = which === 'start' ? elements.startTime : elements.endTime;
        let value = parseTime(input.value);
        if (!Number.isFinite(value)) {
            input.value = formatTime(source[which]);
            showMessage('Use a timestamp such as 00:01:23.500.', true);
            return;
        }
        value = Math.max(0, Math.min(value, source.duration));
        if (which === 'start' && value >= source.end) value = Math.max(0, source.end - 0.1);
        if (which === 'end' && value <= source.start) value = Math.min(source.duration, source.start + 0.1);
        source[which] = value;
        showMessage('');
        renderTimeline();
    }

    function setFromCurrent(which) {
        const source = activeSource();
        if (!source.ready) return;
        const value = Math.max(0, Math.min(currentTime(), source.duration));
        if (which === 'start') source.start = Math.min(value, source.end - 0.1);
        else source.end = Math.max(value, source.start + 0.1);
        renderTimeline();
    }

    function previewSelection() {
        const source = activeSource();
        if (!source.ready) return;
        pauseActive();

        if (state.mode === 'local') {
            elements.localPlayer.currentTime = source.start;
            elements.localPlayer.play().catch(() => showMessage('Press play in the video player to allow playback.', true));
        } else if (state.youtubePlayer?.seekTo) {
            state.youtubePlayer.seekTo(source.start, true);
            state.youtubePlayer.playVideo();
        }

        state.previewTimer = window.setInterval(() => {
            if (currentTime() >= source.end - 0.04) pauseActive();
        }, 80);
    }

    elements.modeTabs.forEach((tab) => tab.addEventListener('click', () => switchMode(tab.dataset.mode)));
    elements.refreshMedia.addEventListener('click', () => loadMediaList(true));
    elements.mediaSelect.addEventListener('change', () => selectLocalMedia(elements.mediaSelect.value));
    elements.loadYoutube.addEventListener('click', loadYoutubeVideo);
    elements.youtubeUrl.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') loadYoutubeVideo();
    });
    elements.startRange.addEventListener('input', () => updateRange('start'));
    elements.endRange.addEventListener('input', () => updateRange('end'));
    elements.startTime.addEventListener('change', () => updateTimeInput('start'));
    elements.endTime.addEventListener('change', () => updateTimeInput('end'));
    elements.setStartCurrent.addEventListener('click', () => setFromCurrent('start'));
    elements.setEndCurrent.addEventListener('click', () => setFromCurrent('end'));
    elements.preview.addEventListener('click', previewSelection);

    $$('input[name="resolution-choice"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            $$('.resolution-option').forEach((option) => option.classList.toggle('selected', option.contains(radio)));
            elements.formResolution.value = radio.value;
        });
    });

    elements.format.addEventListener('change', () => {
        elements.formFormat.value = elements.format.value;
        elements.formatNote.textContent = formatNotes[elements.format.value];
    });

    elements.clipForm.addEventListener('submit', (event) => {
        const source = activeSource();
        if (!source.ready) {
            event.preventDefault();
            showMessage(`Load a ${state.mode === 'local' ? 'local' : 'YouTube'} source video first.`, true);
            return;
        }
        elements.clipForm.action = state.mode === 'youtube' ? '/api/youtube-clip.php' : '/api/clip.php';
        renderTimeline();
        showMessage(`${state.mode === 'youtube' ? 'YouTube retrieval and encoding have' : 'Encoding has'} started. Keep this tab open until your browser finishes the download.`);
    });

    elements.localPlayer.addEventListener('error', () => {
        if (state.local.ready) showMessage('Your browser cannot preview this container, but FFmpeg may still be able to cut it.', true);
    });

    loadMediaList(false);
})();
