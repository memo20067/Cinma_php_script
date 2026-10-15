# CinemaCMS

A PHP 8+ media catalog for movies, TV series, software, and games. It supports MySQL/MariaDB and SQLite.

## Install

1. Place the project folder under your web server document root (for example, `C:\xampp\htdocs\Cinma_php_script`).
2. Enable PHP PDO and either `pdo_mysql` or `pdo_sqlite`.
3. Open `http://localhost/Cinma_php_script/installer/` and follow the setup wizard.
4. Sign in with the administrator account created during installation.

The installer creates the required tables and writes `config.php` to the project root. Keep that file private and do not commit database credentials.

## Contact details

After signing in as an administrator, open **Admin Panel → Overview & Settings** to configure the public contact email, phone number, and WhatsApp number. These details appear on the Contact page and in the site footer. Leave a field empty to hide that contact method.

## Movie metadata and offline artwork

Configure a TMDB API key in Admin Panel → Overview & Settings. In **Movies & Media**, search by an Arabic or original title, select the matching TMDB result, and then save the uploaded video. Movie descriptions, original title, cast, ratings, genres, and artwork are saved in the local database and `uploads/`; they are not fetched again when browsing the site. Use the edit form to adjust metadata, replace poster/backdrop images, or explicitly refresh metadata from TMDB.

For TV series, choose **TV series** in the same media form to add episodes. Add individual episode rows with the **+ Add episode** button, or select multiple files and assign a season, starting episode number, and title pattern such as `Episode {episode}`. Bulk episode numbers follow the order in which the files are selected; `{season}` and `{episode}` in the title pattern are replaced with zero-padded numbers.

The Arabic/English switch changes the interface direction and the language used for TMDB metadata requests. TMDB data and images require an internet connection during search/import; previously imported catalog data and cached images remain local afterwards.

## Custom pages

Administrators can create, edit, publish, and remove pages from **Admin Panel → Site Pages**, with separate HTML, CSS, and JavaScript editors. Published pages are linked in the public navigation; their title is used in the browser tab and navigation, not displayed as a page heading. Page CSS and scripts execute as authored, so only trusted administrators should be allowed to author page content.

## Navigation and streaming sources

The public header uses a menu beside the site logo. Movies, TV series, software, and games can each be assigned a navigation category in **Admin Panel → Movies & Media**; each section opens grouped by category, and the menu's **+** control links directly to a selected category. The header search expands from its magnifying-glass button on hover or keyboard focus.

Administrators can add multiple Xtream Codes or M3U/M3U8 sources in **Admin Panel → Streaming sources**. Xtream sources import live channels, movies, series, episode lists, and provider EPG data when available. M3U entries are classified using their group/type metadata, URL, and episode naming; XMLTV is loaded only when a channel's guide is opened and its URL is embedded in the playlist header (for example, `url-tvg` or `x-tvg-url`). Source refresh stores the catalog metadata without downloading artwork or EPG data. Browsing shows 20 entries per page, and artwork is downloaded to `uploads/iptv/` on its first image request and reused afterward. A source is refreshed when opened after 12 hours or with **Refresh source**. PHP cURL must be enabled and the web server must be able to reach the configured source. Stream playback is proxied through the site so Xtream credentials are not placed in the player page URL. Protect database backups because they contain source credentials.

## Software and games

The media form switches to the relevant fields when you select a movie, TV series, software, or game. Every type can be assigned a category for public navigation. TV shows support individual and bulk episode uploads. Software and games also have platform/operating-system selections, version, activation/license notes, requirements, multiple download files, poster, gallery images, and tutorial/trailer. Trailers can be uploaded locally or linked; YouTube, Vimeo, and Dailymotion links embed on the local detail page, while unsupported providers open at their original URL. New uploads are organized under `uploads/movies/`, `uploads/series/`, `uploads/software/`, or `uploads/games/`, with per-item folders.

The file scanner only scans within `uploads/`; it can link selected video or installer files to existing movies, series, software, and games, or delete selected files and their media links. Linking copies the file into the selected item's content folder.

## Ads and user management

Administrators can create user accounts and assign the explicit `superuser` role. Superusers have admin privileges plus access to **Ad settings**, which provides header, content-top, content-bottom, and footer placements for AdSense or other trusted HTML/JavaScript ad code. Fresh installs also generate empty `AD_SLOT_*` constants in `config.php` for deployment-time defaults. Ad code is rendered as authored, so only grant superuser access to a trusted account.

For large movie uploads, increase PHP's `upload_max_filesize` and `post_max_size` in `php.ini` and restart Apache. For bulk episode uploads, also raise `max_file_uploads` if selecting more files than its configured limit. The upload form displays the configured per-file limit.

## Video playback compatibility

The watch page displays buttons directly below the video for the native browser player and seven locally bundled options: Plyr, Video.js, MediaElement.js, Vidstack, Clappr, Fluid Player, and Shaka Player. Player files are served from this project rather than a CDN. The native browser player is selected by default and remains the fallback for older devices; selecting another button switches the same video source, and episode selection updates the active player. Shaka requires an adaptive DASH or HLS stream and reports when the selected file is not compatible. The original uploaded file remains available for download.

To rebuild the bundled player files after changing a player dependency, run `npm install` followed by `npm run build:player-assets` from the project root. The generated assets are stored under `assets/vendor/`; the dependency versions and third-party license notices are included in `package.json` and `assets/vendor/THIRD-PARTY-NOTICES.txt`.

To create a legacy-friendly playback copy automatically when uploading a movie or episode, set the full path to `ffmpeg.exe` under **Admin Panel → Overview & Settings** (for example, `C:\ffmpeg\bin\ffmpeg.exe`). The generated copy uses H.264 Baseline Level 3.0 video with AAC audio in an MP4 container and is limited to standard-definition dimensions; the player uses it when it exists and otherwise streams the original. Existing titles can be converted from their edit page using **Generate or refresh legacy-compatible MP4 copies**.

For selectable and downloadable quality variants plus adaptive HLS playback on Windows/XAMPP, no separate FFmpeg installation is needed. Edit a movie or series in **Admin Panel → Movies & Media**, then use **Generate / refresh qualities** for the movie or each episode. On first use, the script downloads the FFmpeg 9.0.2 essentials package (about 110 MB), checks its pinned SHA-256 checksum, and installs the local `ffmpeg.exe` and `ffprobe.exe` under `tools/ffmpeg/`. The web-server account needs internet access and write permission to `tools/`; allow enough temporary and permanent disk space. Alternatively, configure a custom FFmpeg path in **Overview & Settings**.

Quality generation creates H.264/AAC MP4 variants at 360p, 480p, 720p, and 1080p where the source resolution permits, along with HLS playlists and segments. The original upload is preserved. The watch page's quality selector switches between adaptive playback, generated MP4 qualities, and the original/compatibility copy; the Download button downloads the selected MP4 quality. HLS is available through compatible browsers or the Video.js, Clappr, and Shaka players; older devices can use the MP4 variants. Generated data is stored locally under `uploads/renditions/` and is protected from direct web access. Encoding requires PHP's `proc_open`, write permission on `uploads/`, additional disk space, and can take a long time depending on video length and server hardware.

The downloaded Windows build is provided by [gyan.dev](https://www.gyan.dev/ffmpeg/builds/) and is GPLv3-enabled. Its `LICENSE` file is retained beside the local executables; see [the FFmpeg third-party notice](tools/FFMPEG-THIRD-PARTY.md) for version, checksum, and source links. The binary package is downloaded directly to each installation and is not part of this repository.

Browser and codec support varies by television manufacturer. Many smart TVs released before 2010 have no HTML5 video support or cannot run modern JavaScript, so a web player cannot guarantee playback on those sets even with a converted file. The native-player fallback and download link remain available; test on the target model before deploying.