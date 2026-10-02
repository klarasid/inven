<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

/**
 * Compares the installed version (the "Version:" line SLiMS reads from inventory.plugin.php) with the
 * latest GitHub release of the repository named in "Plugin URI:". Results are cached in SLiMS's
 * `setting` table for 12 hours (1 hour after a failed lookup) so page loads never wait on GitHub and
 * the unauthenticated API limit of 60 requests per hour is never approached.
 */
final class UpdateCheck
{
    public const SETTING = 'inventory_update_check';
    private const TTL = 43200;
    private const RETRY = 3600;

    /** @return array{version:string,repo:?string} */
    public static function plugin(): array
    {
        $header = (string) file_get_contents(dirname(__DIR__) . '/inventory.plugin.php', false, null, 0, 2048);
        preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $header, $version);
        preg_match('#^\s*\*\s*Plugin URI:\s*https://github\.com/([\w.-]+/[\w.-]+?)(?:\.git)?/?\s*$#mi', $header, $repo);
        return ['version' => $version[1] ?? '0.0.0', 'repo' => $repo[1] ?? null];
    }

    /**
     * @return array{current:string,latest:?string,available:bool,url:?string,download:?string,notes:string,published_at:?string,checked_at:?string,repo:?string}
     */
    public static function status(\PDO $db, bool $refresh = false): array
    {
        $plugin = self::plugin();
        $read = $db->prepare('SELECT setting_value FROM setting WHERE setting_name = ?');
        $read->execute([self::SETTING]);
        $value = $read->fetchColumn();
        $cache = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : null;
        $cache = is_array($cache) ? $cache : [];
        $fresh = isset($cache['checked']) && time() < (int) $cache['checked'] + (empty($cache['failed']) ? self::TTL : self::RETRY);
        // A manual refresh is honoured at most once a minute.
        $canRefresh = $refresh && time() - (int) ($cache['checked'] ?? 0) >= 60;

        if ($plugin['repo'] && (!$fresh || $canRefresh)) {
            $release = self::fetch($plugin['repo']);
            $cache = $release === false
                ? ['checked' => time(), 'failed' => true, 'release' => $cache['release'] ?? null]
                : ['checked' => time(), 'failed' => false, 'release' => $release];
            $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                ->execute([self::SETTING, serialize($cache)]);
        }

        $release = $cache['release'] ?? null;
        $latest = $release['version'] ?? null;
        return [
            'current' => $plugin['version'],
            'latest' => $latest,
            'available' => $latest !== null && version_compare($latest, $plugin['version'], '>'),
            'url' => $release['url'] ?? ($plugin['repo'] ? 'https://github.com/' . $plugin['repo'] . '/releases' : null),
            'download' => $release['download'] ?? null,
            'notes' => (string) ($release['notes'] ?? ''),
            'published_at' => $release['published_at'] ?? null,
            'checked_at' => isset($cache['checked']) ? date('c', (int) $cache['checked']) : null,
            'repo' => $plugin['repo'],
        ];
    }

    /** The release links become buttons for administrators; only links to GitHub itself are passed on. */
    private static function github($url): ?string
    {
        return is_string($url) && preg_match('#\Ahttps://github\.com/[^\s"\'<>\\\\]+\z#', $url) ? $url : null;
    }

    /**
     * Latest non-draft, non-prerelease release; null when the repository has none yet, false on failure.
     * @return array{version:string,url:string,download:?string,notes:string,published_at:?string}|null|false
     */
    private static function fetch(string $repo)
    {
        if (!function_exists('curl_init')) return false;
        $curl = curl_init('https://api.github.com/repos/' . $repo . '/releases/latest');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: klaras-inven-update-check', 'X-GitHub-Api-Version: 2022-11-28'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status === 404) return null;
        if ($status !== 200 || !is_string($body)) return false;
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['tag_name'])) return false;
        $download = null;
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (preg_match('/\A(?:klaras-inven|inventaris-barang)-.*\.zip\z/', (string) ($asset['name'] ?? ''))) { $download = self::github($asset['browser_download_url'] ?? null); break; }
        }
        return [
            'version' => ltrim((string) $data['tag_name'], 'vV'),
            'url' => self::github($data['html_url'] ?? null) ?? 'https://github.com/' . $repo . '/releases',
            'download' => $download,
            'notes' => mb_substr((string) ($data['body'] ?? ''), 0, 6000),
            'published_at' => $data['published_at'] ?? null,
        ];
    }
}
