<?php

declare(strict_types=1);

namespace Grav\Plugin\Easyforms;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use ZipArchive;

/**
 * Checks a configured GitHub repository's latest release against this
 * plugin's own version, and can apply it in place.
 *
 * Grav's built-in GPM update mechanism only checks a single hardcoded
 * getgrav.org index and has no per-plugin override, so a plugin outside
 * that catalog (like this one) can never show "update available" through
 * the stock Plugins list — this class is a self-contained substitute.
 */
final class EasyformsUpdater
{
    private const CACHE_KEY = 'easyforms-update-check';
    private const CACHE_TTL = 1800;
    private const CACHE_TTL_ERROR = 300;
    private const USER_AGENT = 'easyforms-grav-plugin-updater';

    public static function pluginRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function getCurrentVersion(): string
    {
        $file = CompiledYamlFile::instance(self::pluginRoot() . '/blueprints.yaml');
        $data = (array) $file->content();
        $file->free();

        return (string) ($data['version'] ?? '0.0.0');
    }

    public static function getRepo(): string
    {
        $repo = (string) (Grav::instance()['config']->get('plugins.easyforms.github_repo') ?? '');

        return trim($repo, "/ \t\n\r\0\x0B");
    }

    /**
     * @return array{available:bool,current:string,latest:?string,url:?string,zip_url:?string,body:?string,error:?string}
     */
    public static function checkLatestRelease(bool $useCache = true): array
    {
        $current = self::getCurrentVersion();
        $repo = self::getRepo();

        $base = [
            'available' => false,
            'current' => $current,
            'latest' => null,
            'url' => null,
            'zip_url' => null,
            'body' => null,
            'error' => null,
        ];

        if ($repo === '' || !str_contains($repo, '/')) {
            $base['error'] = 'No GitHub repository configured (plugins.easyforms.github_repo).';

            return $base;
        }

        $cache = Grav::instance()['cache'];
        $cacheId = self::CACHE_KEY . '-' . md5($repo);

        if ($useCache) {
            $cached = $cache->fetch($cacheId);
            if (is_array($cached)) {
                $cached['current'] = $current;
                $cached['available'] = self::isNewer($cached['latest'] ?? null, $current);

                return $cached;
            }
        }

        $response = self::httpGet('https://api.github.com/repos/' . $repo . '/releases/latest');
        if ($response === null) {
            $base['error'] = 'Could not reach GitHub (network error).';
            $cache->save($cacheId, $base, self::CACHE_TTL_ERROR);

            return $base;
        }

        [$status, $body] = $response;
        if ($status !== 200) {
            $base['error'] = $status === 404
                ? 'No releases found for this repository yet.'
                : "GitHub API returned HTTP {$status}.";
            $cache->save($cacheId, $base, self::CACHE_TTL_ERROR);

            return $base;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['tag_name'])) {
            $base['error'] = 'Unexpected response from GitHub.';
            $cache->save($cacheId, $base, self::CACHE_TTL_ERROR);

            return $base;
        }

        $version = ltrim((string) $data['tag_name'], 'vV');

        // Prefer a hand-built .zip release asset if one was uploaded (e.g. via
        // CI), otherwise fall back to GitHub's automatic source zip — always
        // present for any tagged release, no extra step required.
        $zipUrl = (string) ($data['zipball_url'] ?? '');
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (is_array($asset) && str_ends_with((string) ($asset['name'] ?? ''), '.zip')) {
                $zipUrl = (string) $asset['browser_download_url'];
                break;
            }
        }

        $result = [
            'available' => false,
            'current' => $current,
            'latest' => $version,
            'url' => (string) ($data['html_url'] ?? ''),
            'zip_url' => $zipUrl,
            'body' => (string) ($data['body'] ?? ''),
            'error' => null,
        ];

        $cache->save($cacheId, $result, self::CACHE_TTL);

        $result['available'] = self::isNewer($version, $current);

        return $result;
    }

    private static function isNewer(?string $latest, string $current): bool
    {
        return $latest !== null && $latest !== '' && version_compare($latest, $current, '>');
    }

    /**
     * @return array{0:int,1:string}|null [status, body], or null on transport failure.
     */
    private static function httpGet(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            curl_close($ch);

            return null;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }

    private static function downloadTo(string $url, string $destination): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $fp = fopen($destination, 'wb');
        if ($fp === false) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $status !== 200) {
            @unlink($destination);

            return false;
        }

        return true;
    }

    /**
     * Downloads the latest release zip, verifies it looks like this plugin,
     * and atomically swaps it in for the currently running plugin folder.
     *
     * The previous folder is renamed aside rather than deleted, so a bad
     * release can be recovered by hand; if moving the new folder into place
     * fails, the old one is restored immediately. Renaming a directory that
     * a running PHP process is currently executing code from is safe on
     * Linux (the process keeps its open file handles) but not guaranteed on
     * every OS — this assumes a typical Linux hosting environment.
     *
     * @return array{success:bool,message:string,version:?string}
     */
    public static function applyUpdate(): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['success' => false, 'message' => 'The PHP zip extension is not available on this server.', 'version' => null];
        }

        $release = self::checkLatestRelease(false);
        if ($release['error']) {
            return ['success' => false, 'message' => $release['error'], 'version' => null];
        }
        if (!$release['zip_url']) {
            return ['success' => false, 'message' => 'No downloadable release asset found.', 'version' => null];
        }

        $locator = Grav::instance()['locator'];
        $workDir = $locator->findResource('cache://easyforms-update', true, true);
        Folder::create($workDir);

        $zipPath = $workDir . '/release.zip';
        $extractPath = $workDir . '/extracted';

        if (is_dir($extractPath)) {
            Folder::delete($extractPath);
        }

        if (!self::downloadTo($release['zip_url'], $zipPath)) {
            return ['success' => false, 'message' => 'Failed to download the release archive.', 'version' => null];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            @unlink($zipPath);

            return ['success' => false, 'message' => 'The downloaded file is not a valid zip archive.', 'version' => null];
        }
        $zip->extractTo($extractPath);
        $zip->close();
        @unlink($zipPath);

        $pluginSource = self::resolveExtractedPluginRoot($extractPath);

        if ($pluginSource === null) {
            Folder::delete($extractPath);

            return [
                'success' => false,
                'message' => 'The downloaded release does not look like a valid easyforms plugin (easyforms.php not found).',
                'version' => null,
            ];
        }

        $liveRoot = self::pluginRoot();
        $parent = dirname($liveRoot);
        $backup = $parent . '/easyforms-backup-' . date('Ymd-His');

        if (!@rename($liveRoot, $backup)) {
            Folder::delete($extractPath);

            return ['success' => false, 'message' => 'Could not move the current plugin folder aside (check file permissions).', 'version' => null];
        }

        if (!@rename($pluginSource, $liveRoot)) {
            // Leaving the site with no plugin folder at all is worse than the
            // old version, so restore it immediately.
            @rename($backup, $liveRoot);
            Folder::delete($extractPath);

            return ['success' => false, 'message' => 'Could not install the new version; the previous version was restored.', 'version' => null];
        }

        Folder::delete($extractPath);

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        return [
            'success' => true,
            'message' => "Updated to v{$release['latest']}. The previous version was kept at " . basename($backup) . '.',
            'version' => $release['latest'],
        ];
    }

    /**
     * GitHub's auto-generated source zips wrap everything in a single
     * top-level "{owner}-{repo}-{sha}/" folder; a hand-built release asset
     * may not. Detect and descend into it either way.
     */
    private static function resolveExtractedPluginRoot(string $extractPath): ?string
    {
        if (is_file($extractPath . '/easyforms.php')) {
            return $extractPath;
        }

        $entries = array_values(array_diff(scandir($extractPath) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($extractPath . '/' . $entries[0])) {
            $candidate = $extractPath . '/' . $entries[0];
            if (is_file($candidate . '/easyforms.php')) {
                return $candidate;
            }
        }

        return null;
    }
}
