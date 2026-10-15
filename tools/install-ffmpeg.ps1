param(
    [Parameter(Mandatory = $true)]
    [string]$Destination
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$packageUrl = 'https://www.gyan.dev/ffmpeg/builds/packages/ffmpeg-9.0.2-essentials_build.zip'
$expectedSha256 = '60f467265b1e312373dbcd92200c2618a74850f98d3d078e94296bb3fa2047ba'
$temporaryRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('cinemacms-ffmpeg-' + [guid]::NewGuid().ToString('N'))
$archivePath = Join-Path $temporaryRoot 'ffmpeg.zip'
$extractPath = Join-Path $temporaryRoot 'extracted'

try {
    New-Item -ItemType Directory -Path $temporaryRoot -Force | Out-Null
    Invoke-WebRequest -Uri $packageUrl -OutFile $archivePath -UseBasicParsing

    $actualSha256 = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualSha256 -ne $expectedSha256) {
        throw "FFmpeg archive checksum mismatch. Expected $expectedSha256; received $actualSha256."
    }

    Expand-Archive -LiteralPath $archivePath -DestinationPath $extractPath
    $ffmpeg = Get-ChildItem -LiteralPath $extractPath -Filter 'ffmpeg.exe' -File -Recurse |
        Where-Object { $_.Directory.Name -eq 'bin' } |
        Select-Object -First 1
    if ($null -eq $ffmpeg) {
        throw 'The verified FFmpeg archive does not contain bin\ffmpeg.exe.'
    }

    $ffprobePath = Join-Path $ffmpeg.Directory.FullName 'ffprobe.exe'
    if (-not (Test-Path -LiteralPath $ffprobePath -PathType Leaf)) {
        throw 'The verified FFmpeg archive does not contain bin\ffprobe.exe.'
    }

    $encoderOutput = (& $ffmpeg.FullName -hide_banner -encoders 2>&1 | Out-String)
    if ($LASTEXITCODE -ne 0 -or $encoderOutput -notmatch 'libx264') {
        throw 'The downloaded FFmpeg build did not pass the H.264 encoder check.'
    }
    & $ffprobePath -version *> $null
    if ($LASTEXITCODE -ne 0) {
        throw 'The downloaded ffprobe executable did not pass its version check.'
    }

    $license = Get-ChildItem -LiteralPath $extractPath -Filter 'LICENSE' -File -Recurse |
        Select-Object -First 1
    if ($null -eq $license) {
        throw 'The verified FFmpeg archive does not contain its LICENSE file.'
    }

    $destination = [System.IO.Path]::GetFullPath($Destination)
    New-Item -ItemType Directory -Path $destination -Force | Out-Null

    Copy-Item -LiteralPath $ffmpeg.FullName -Destination (Join-Path $destination 'ffmpeg.exe') -Force
    Copy-Item -LiteralPath $ffprobePath -Destination (Join-Path $destination 'ffprobe.exe') -Force
    Copy-Item -LiteralPath $license.FullName -Destination (Join-Path $destination 'FFMPEG-LICENSE.txt') -Force
    Set-Content -LiteralPath (Join-Path $destination 'FFMPEG-VERSION.txt') `
        -Value "FFmpeg 9.0.2 essentials build; archive SHA-256: $actualSha256"

    Write-Output 'FFmpeg 9.0.2 was downloaded, checksum-verified, and installed successfully.'
}
finally {
    if (Test-Path -LiteralPath $temporaryRoot) {
        Remove-Item -LiteralPath $temporaryRoot -Recurse -Force
    }
}
