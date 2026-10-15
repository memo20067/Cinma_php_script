import { cp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const modules = path.join(root, 'node_modules');
const vendor = path.join(root, 'assets', 'vendor');

async function copyFile(source, destination) {
    await mkdir(path.dirname(destination), { recursive: true });
    await cp(source, destination);
}

async function copyLicense(packageDirectory, destinationDirectory) {
    const entries = await readdir(packageDirectory);
    const license = entries.find((entry) => /^licen[cs]e(?:\.|$)/i.test(entry));
    if (license) {
        await copyFile(path.join(packageDirectory, license), path.join(destinationDirectory, 'LICENSE'));
    }
}

async function copyPackageAsset(packageName, packagePath, destinationPath) {
    await copyFile(path.join(modules, packageName, packagePath), path.join(vendor, destinationPath));
}

async function copyPackageLicense(packageName, destinationDirectory) {
    await copyLicense(path.join(modules, packageName), path.join(vendor, destinationDirectory));
}

await rm(vendor, { recursive: true, force: true });

await copyPackageAsset('plyr', 'dist/plyr.min.js', 'plyr/plyr.min.js');
await copyPackageAsset('plyr', 'dist/plyr.css', 'plyr/plyr.css');
await copyPackageAsset('plyr', 'dist/plyr.svg', 'plyr/plyr.svg');
await copyPackageLicense('plyr', 'plyr');

await copyPackageAsset('video.js', 'dist/video.min.js', 'videojs/video.min.js');
await copyPackageAsset('video.js', 'dist/video-js.min.css', 'videojs/video-js.min.css');
await copyPackageLicense('video.js', 'videojs');

await copyPackageAsset('mediaelement', 'build/mediaelement-and-player.min.js', 'mediaelement/mediaelement-and-player.min.js');
await copyPackageAsset('mediaelement', 'build/mediaelementplayer.min.css', 'mediaelement/mediaelementplayer.min.css');
await copyPackageAsset('mediaelement', 'build/mejs-controls.svg', 'mediaelement/mejs-controls.svg');
await copyPackageLicense('mediaelement', 'mediaelement');

await copyPackageAsset('@clappr/player', 'dist/clappr.min.js', 'clappr/clappr.min.js');
await copyPackageAsset('@clappr/player', 'dist/clappr.plainhtml5.min.js', 'clappr/clappr.plainhtml5.min.js');
await copyPackageLicense('@clappr/player', 'clappr');

await copyPackageAsset('shaka-player', 'dist/shaka-player.compiled.js', 'shaka/shaka-player.compiled.js');
await copyPackageLicense('shaka-player', 'shaka');

await build({
    entryPoints: [path.join(root, 'tools', 'fluid-player-entry.js')],
    bundle: true,
    format: 'iife',
    target: ['es2017'],
    outdir: path.join(vendor, 'fluidplayer'),
    entryNames: 'fluid-player',
    assetNames: 'assets/[name]-[hash]',
    loader: { '.svg': 'file' },
    minify: true,
    legalComments: 'external',
    logLevel: 'info'
});
await copyPackageLicense('fluid-player', 'fluidplayer');

await build({
    entryPoints: [path.join(root, 'tools', 'vidstack-player-entry.js')],
    bundle: true,
    format: 'iife',
    target: ['es2017'],
    outfile: path.join(vendor, 'vidstack', 'vidstack.js'),
    minify: true,
    legalComments: 'external',
    logLevel: 'info'
});
await copyPackageLicense('@vidstack/player', 'vidstack');

const mediaElementLicense = await readFile(path.join(modules, 'mediaelement', 'LICENSE'), 'utf8');
const clapprLicense = await readFile(path.join(modules, '@clappr', 'player', 'LICENSE'), 'utf8').catch(() => '');
const videoJsLicense = await readFile(path.join(modules, 'video.js', 'LICENSE'), 'utf8');
const shakaLicense = await readFile(path.join(modules, 'shaka-player', 'LICENSE'), 'utf8');
await writeFile(path.join(vendor, 'THIRD-PARTY-NOTICES.txt'), [
    'CinemaCMS bundled player libraries',
    '',
    'Plyr (MIT), MediaElement.js (MIT), Video.js (Apache-2.0),',
    'Vidstack Player (MIT), Clappr (BSD-3-Clause), Fluid Player (MIT),',
    'and Shaka Player (Apache-2.0).',
    '',
    'Package license texts are included beside each player where provided.',
    'Additional upstream license files:',
    '',
    '--- MediaElement.js ---',
    mediaElementLicense,
    '--- Clappr ---',
    clapprLicense,
    '--- Video.js ---',
    videoJsLicense,
    '--- Shaka Player ---',
    shakaLicense
].join('\n'), 'utf8');
