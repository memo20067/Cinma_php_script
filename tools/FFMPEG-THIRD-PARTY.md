# FFmpeg third-party binary

The Windows installer downloads the FFmpeg 9.0.2 essentials build directly from
[gyan.dev](https://www.gyan.dev/ffmpeg/builds/). It verifies the archive against
the pinned SHA-256 checksum before extracting `ffmpeg.exe` and `ffprobe.exe`
into the local `tools/ffmpeg/bin/` directory. The archive and generated
executables are not committed to this repository.

The downloaded build is GPLv3-enabled. The installer retains the archive's
`LICENSE` file beside the executables as `FFMPEG-LICENSE.txt`. The corresponding
FFmpeg source is available from the
[FFmpeg 9.0.2 source tag](https://github.com/FFmpeg/FFmpeg/tree/n9.0.2);
the build provider's license and build information are available on
[gyan.dev](https://www.gyan.dev/ffmpeg/builds/). This project does not
redistribute the FFmpeg archive.
