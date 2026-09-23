<?php
/* Copyright (C) 2026 EVARISK <technique@evarisk.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/digiboardremoteinstance.class.php
 * \ingroup digiboard
 * \brief   Class file for manage DigiBoardRemoteInstance
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

/**
 * Class for DigiBoardRemoteInstance
 *
 * A remote instance is another Dolibarr DigiBoard reads through its REST API. The instances are kept in a single
 * configuration constant rather than in a table of their own: they are a handful of connection settings written by
 * an administrator, they carry no history and nothing points at them.
 *
 * Every instance computes its own indicators and answers with them already rendered, so DigiBoard displays what it
 * receives instead of recomputing anything: what the time of a ticket means is known by the instance owning the
 * ticket, not here.
 */
class DigiBoardRemoteInstance
{
    /**
     * @var string Name of the constant holding the instances
     */
    public const CONF_NAME = 'DIGIBOARD_REMOTE_INSTANCES';

    /**
     * @var int Number of seconds a cached answer is served before the instance is called again
     */
    public const DEFAULT_CACHE_TTL = 300;

    /**
     * @var int Number of seconds a call is given before it is given up on
     */
    public const CALL_TIMEOUT = 30;

    /**
     * @var DoliDB Database handler
     */
    public DoliDB $db;

    /**
     * @var string Error message of the last call
     */
    public string $error = '';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db = $db;
    }

    /**
     * Get the configured instances
     *
     * @param  bool  $onlyEnabled True to leave out the instances an administrator switched off
     * @return array              Instances keyed by their id
     */
    public function getInstances(bool $onlyEnabled = false): array
    {
        $instances = json_decode(getDolGlobalString(self::CONF_NAME, '[]'), true);
        if (!is_array($instances)) {
            return [];
        }

        $result = [];
        foreach ($instances as $instance) {
            if (empty($instance['id']) || empty($instance['url'])) {
                continue;
            }
            if ($onlyEnabled && empty($instance['enabled'])) {
                continue;
            }
            $result[$instance['id']] = $instance;
        }

        return $result;
    }

    /**
     * Get one configured instance
     *
     * @param  string     $id Id of the instance
     * @return array|null     Instance, or null when it is not configured anymore
     */
    public function getInstance(string $id): ?array
    {
        return $this->getInstances()[$id] ?? null;
    }

    /**
     * Save the instances
     *
     * @param  array $instances Instances to save, keyed by their id
     * @return int              1 when saved, -1 when the constant could not be written
     */
    public function setInstances(array $instances): int
    {
        global $conf;

        $result = dolibarr_set_const($this->db, self::CONF_NAME, json_encode(array_values($instances)), 'chaine', 0, '', $conf->entity);

        return $result > 0 ? 1 : -1;
    }

    /**
     * Add or update an instance
     *
     * The token is kept encrypted: it opens the API of another Dolibarr, and the configuration constants of an
     * instance are read by more people than the administrators who wrote them.
     *
     * @param  array $instance Instance to save, its id empty for a new one
     * @return int             1 when saved, -1 on error
     */
    public function saveInstance(array $instance): int
    {
        global $langs;

        $instance['label'] = trim($instance['label'] ?? '');
        $instance['url']   = rtrim(trim($instance['url'] ?? ''), '/');

        if (empty($instance['label']) || empty($instance['url'])) {
            $this->error = $langs->transnoentities('RemoteInstanceLabelAndUrlRequired');
            return -1;
        }
        if (!preg_match('#^https?://#i', $instance['url'])) {
            $this->error = $langs->transnoentities('RemoteInstanceUrlMustBeAbsolute');
            return -1;
        }

        $instances = $this->getInstances();

        if (empty($instance['id'])) {
            $instance['id'] = substr(md5(dol_now() . $instance['url'] . mt_rand()), 0, 12);
        }

        // An empty token on an existing instance means the administrator did not retype it, not that it was removed
        if (empty($instance['token']) && !empty($instances[$instance['id']]['token'])) {
            $instance['token'] = $instances[$instance['id']]['token'];
        } elseif (!empty($instance['token'])) {
            $instance['token'] = dolEncrypt($instance['token']);
        }

        $instances[$instance['id']] = [
            'id'      => $instance['id'],
            'label'   => $instance['label'],
            'url'     => $instance['url'],
            'token'   => $instance['token'] ?? '',
            'enabled' => empty($instance['enabled']) ? 0 : 1,
            'local'   => empty($instance['local']) ? 0 : 1
        ];

        return $this->setInstances($instances);
    }

    /**
     * Delete an instance
     *
     * @param  string $id Id of the instance
     * @return int        1 when deleted, -1 on error
     */
    public function deleteInstance(string $id): int
    {
        $instances = $this->getInstances();
        unset($instances[$id]);

        $this->deleteCache($id);

        return $this->setInstances($instances);
    }

    /**
     * Get the ticket dashboard of an instance
     *
     * @param  array $instance  Instance to read
     * @param  string $period   Number of days the flow indicators cover, 0 for the whole history
     * @param  int   $userId    Id, on the remote instance, of the assignee the dashboard is restricted to
     * @param  int   $nbTickets Number of ticket rows to bring back, 0 for none, -1 for all
     * @param  int   $openOnly  1 to keep only the tickets still open in those rows
     * @param  bool  $useCache  False to call the instance even when a fresh answer is cached
     * @return array            Result of the call: success, data, error, httpCode, duration and fromCache
     */
    public function getTicketDashboard(array $instance, string $period, int $userId = 0, int $nbTickets = 0, int $openOnly = 0, bool $useCache = true): array
    {
        return $this->call($instance, 'reedcrm/ticketdashboard', [
            'period'   => $period,
            'userid'   => $userId,
            'tickets'  => $nbTickets,
            'openonly' => $openOnly,
            'lang'     => $this->getReadingLang()
        ], $useCache);
    }

    /**
     * Get the ticket counters of an instance, without its dashboard
     *
     * @param  array  $instance Instance to read
     * @param  string $period   Number of days the flow indicators cover, 0 for the whole history
     * @param  int    $userId   Id, on the remote instance, of the assignee the counters are restricted to
     * @param  bool   $useCache False to call the instance even when a fresh answer is cached
     * @return array            Result of the call: success, data, error, httpCode, duration and fromCache
     */
    public function getTicketSummary(array $instance, string $period, int $userId = 0, bool $useCache = true): array
    {
        return $this->call($instance, 'reedcrm/ticketsummary', [
            'period' => $period,
            'userid' => $userId,
            'lang'   => $this->getReadingLang()
        ], $useCache);
    }

    /**
     * Get the tickets of an instance
     *
     * @param  array  $instance Instance to read
     * @param  string $period   Number of days the list covers, 0 for the whole history
     * @param  int    $userId   Id, on the remote instance, of the assignee the list is restricted to
     * @param  int    $openOnly 1 to keep only the tickets still open
     * @param  int    $limit    Maximum number of rows, 0 for every ticket of the period
     * @param  bool   $useCache False to call the instance even when a fresh answer is cached
     * @return array            Result of the call: success, data, error, httpCode, duration and fromCache
     */
    public function getTickets(array $instance, string $period, int $userId = 0, int $openOnly = 0, int $limit = 0, bool $useCache = true): array
    {
        return $this->call($instance, 'reedcrm/tickets', [
            'period'   => $period,
            'userid'   => $userId,
            'openonly' => $openOnly,
            'limit'    => $limit,
            'lang'     => $this->getReadingLang()
        ], $useCache);
    }

    /**
     * Get the language the answers are asked to be rendered in
     *
     * A remote instance renders its labels itself, and would otherwise render them in the language of the user
     * whose token it recognised: the reader is here, so the language of the reader is sent along.
     *
     * @return string Language code, empty when it cannot be read
     */
    protected function getReadingLang(): string
    {
        global $langs;

        return preg_match('/^[a-z]{2}_[A-Z]{2}$/', $langs->defaultlang ?? '') ? $langs->defaultlang : '';
    }

    /**
     * Check an instance answers and recognises the token
     *
     * @param  array $instance Instance to reach
     * @return array           Result of the call: success, data, error, httpCode, duration and fromCache
     */
    public function testConnection(array $instance): array
    {
        return $this->call($instance, 'reedcrm/test', [], false);
    }

    /**
     * Call the API of an instance
     *
     * @param  array  $instance Instance to call
     * @param  string $path     Path of the endpoint, after the api entry point
     * @param  array  $params   Query parameters of the call
     * @param  bool   $useCache False to call the instance even when a fresh answer is cached
     * @return array            Result of the call: success, data, error, httpCode, duration and fromCache
     */
    public function call(array $instance, string $path, array $params = [], bool $useCache = true): array
    {
        global $langs;

        $result = ['success' => false, 'data' => [], 'error' => '', 'httpCode' => 0, 'duration' => 0, 'fromCache' => false];

        if (empty($instance['url'])) {
            $result['error'] = $langs->transnoentities('RemoteInstanceUrlMissing');
            return $result;
        }

        $cacheKey = $this->getCacheKey($instance, $path, $params);
        if ($useCache) {
            $cached = $this->readCache($instance['id'] ?? '', $cacheKey);
            if ($cached !== null) {
                $result['success']   = true;
                $result['data']      = $cached;
                $result['fromCache'] = true;
                return $result;
            }
        }

        $url = $instance['url'] . '/api/index.php/' . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = ['Accept: application/json'];
        if (!empty($instance['token'])) {
            $headers[] = 'DOLAPIKEY: ' . dolDecrypt($instance['token']);
        }

        // An instance reached on a private network is refused by default: an administrator who needs it says so
        $localUrl = empty($instance['local']) ? 0 : 1;

        $start    = microtime(true);
        $response = getURLContent($url, 'GET', '', 1, $headers, ['http', 'https'], $localUrl, -1, self::CALL_TIMEOUT, self::CALL_TIMEOUT);

        $result['duration'] = microtime(true) - $start;
        $result['httpCode'] = (int) ($response['http_code'] ?? 0);

        if (!empty($response['curl_error_msg'])) {
            $result['error'] = $response['curl_error_msg'];
            return $result;
        }

        $data = json_decode($response['content'] ?? '', true);

        if ($result['httpCode'] !== 200) {
            // A Dolibarr API answers its errors in json, the message it gives is more useful than the http code
            $result['error'] = $data['error']['message'] ?? ($data['error'] ?? $langs->transnoentities('RemoteInstanceHttpError', $result['httpCode']));
            if (is_array($result['error'])) {
                $result['error'] = json_encode($result['error']);
            }
            return $result;
        }
        if (!is_array($data)) {
            $result['error'] = $langs->transnoentities('RemoteInstanceInvalidAnswer');
            return $result;
        }

        $data = $this->cleanRemoteHtml($data);

        $result['success'] = true;
        $result['data']    = $data;

        $this->writeCache($instance['id'] ?? '', $cacheKey, $data);

        return $result;
    }

    /**
     * Take out of the received HTML what only makes sense on the instance that rendered it
     *
     * A ticket link rendered by Dolibarr carries an ajax tooltip, which would be filled here by asking this
     * instance about a ticket id belonging to another one: the tooltip is dropped rather than showing a stranger.
     *
     * @param  mixed $data Payload to walk through
     * @return mixed       Payload whose HTML can be displayed here
     */
    protected function cleanRemoteHtml($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->cleanRemoteHtml($value);
            }

            return $data;
        }

        if (!is_string($data) || strpos($data, 'classforajaxtooltip') === false) {
            return $data;
        }

        $data = preg_replace('/\s*data-params="[^"]*"/', '', $data);
        $data = preg_replace('/\s*title="tocomplete"/', '', $data);

        return str_replace('classforajaxtooltip', '', $data);
    }

    /**
     * Get the number of seconds a cached answer stays valid
     *
     * @return int Cache lifetime, 0 when the cache is switched off
     */
    public function getCacheTtl(): int
    {
        return getDolGlobalInt('DIGIBOARD_REMOTE_CACHE_TTL', self::DEFAULT_CACHE_TTL);
    }

    /**
     * Drop the answers cached for an instance
     *
     * @param  string $id Id of the instance, empty for every instance
     * @return int        Number of dropped files
     */
    public function deleteCache(string $id = ''): int
    {
        $dir = $this->getCacheDir();
        if (empty($dir) || !dol_is_dir($dir)) {
            return 0;
        }

        $files = dol_dir_list($dir, 'files', 0, '^' . preg_quote('remote_' . $id, '/') . '.*\.json$');

        $nb = 0;
        foreach ($files as $file) {
            $nb += dol_delete_file($file['fullname'], 0, 1) ? 1 : 0;
        }

        return $nb;
    }

    /**
     * Get the key naming the cached answer of a call
     *
     * @param  array  $instance Instance called
     * @param  string $path     Path of the endpoint
     * @param  array  $params   Query parameters of the call
     * @return string           Key of the answer
     */
    protected function getCacheKey(array $instance, string $path, array $params): string
    {
        return md5($path . '|' . http_build_query($params) . '|' . ($instance['url'] ?? ''));
    }

    /**
     * Get the directory the answers are cached in
     *
     * @return string Cache directory, empty when the module has no output directory
     */
    protected function getCacheDir(): string
    {
        global $conf;

        $dir = $conf->digiboard->multidir_output[$conf->entity] ?? '';
        if (empty($dir)) {
            $dir = DOL_DATA_ROOT . ($conf->entity > 1 ? '/' . $conf->entity : '') . '/digiboard';
        }

        return $dir . '/temp';
    }

    /**
     * Read the cached answer of a call
     *
     * @param  string     $id  Id of the instance called
     * @param  string     $key Key of the answer
     * @return array|null      Cached answer, or null when there is none fresh enough
     */
    protected function readCache(string $id, string $key): ?array
    {
        $ttl = $this->getCacheTtl();
        if (empty($ttl)) {
            return null;
        }

        $file = $this->getCacheFile($id, $key);
        if (empty($file) || !dol_is_file($file) || filemtime($file) < (dol_now() - $ttl)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Cache the answer of a call
     *
     * @param  string $id   Id of the instance called
     * @param  string $key  Key of the answer
     * @param  array  $data Answer to cache
     * @return void
     */
    protected function writeCache(string $id, string $key, array $data): void
    {
        if (empty($this->getCacheTtl())) {
            return;
        }

        $file = $this->getCacheFile($id, $key);
        if (empty($file)) {
            return;
        }

        dol_mkdir(dirname($file));
        file_put_contents($file, json_encode($data));
    }

    /**
     * Get the file the answer of a call is cached in
     *
     * @param  string $id  Id of the instance called
     * @param  string $key Key of the answer
     * @return string      Cache file, empty when the module has no output directory
     */
    protected function getCacheFile(string $id, string $key): string
    {
        $dir = $this->getCacheDir();

        return empty($dir) ? '' : $dir . '/remote_' . dol_sanitizeFileName($id) . '_' . $key . '.json';
    }
}
