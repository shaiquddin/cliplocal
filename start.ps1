$ErrorActionPreference = 'Stop'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker Desktop is required. Install or start Docker Desktop, then run this script again.'
}

docker compose up --build -d
if ($LASTEXITCODE -ne 0) {
    throw 'ClipLocal could not be started.'
}

Write-Host ''
Write-Host 'ClipLocal is running at http://localhost:8080' -ForegroundColor Green
Write-Host 'Use .env to point MEDIA_DIR at an existing video folder.'
