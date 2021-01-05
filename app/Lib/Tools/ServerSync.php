<?php
App::uses('SyncTool', 'Tools');

class ServerSyncException extends Exception
{
    /** @var HttpSocketResponse|null */
    private $response;

    public function __construct($message, HttpSocketResponseExtended $response = null, Throwable $previous = null)
    {
        $this->response = $response;
        $code = isset($response) ? (int)$response->code : 0;
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return HttpSocketResponse|null
     */
    public function getResponse()
    {
        return $this->response;
    }
}

class ServerSync
{
    const FEATURE_PROPOSALS = 'proposals',
        FEATURE_CHECK_UUID = 'checkuuid',
        FEATURE_GALAXY_CLUSTER_EDIT = 'supportEditOfGalaxyCluster',
        FEATURE_PUSH = 'push',
        FEATURE_ORG_RULE_AS_ARRAY = 'orgRuleAsArray',
        FEATURE_SIGHTINGS_FILTER = 'sightingsFilter',
        FEATURE_POST_TEST = 'postTest',
        FEATURE_GZIP_REQUESTS = 'gzipRequests',
        FEATURE_BROTLI_REQUESTS = 'brotliRequests';

    /** @var array */
    private $defaultRequest;

    /** @var HttpSocketExtended */
    private $socket;

    /** @var array */
    private $server;

    /** @var array */
    private $version;

    /**
     * @param array $server
     * @param array $mispVersion
     * @param string|null $mispCommit
     * @param null|int $timeout
     * @throws Exception
     */
    public function __construct(array $server, array $mispVersion, $mispCommit = null, $timeout = null)
    {
        if (!isset($server['Server']['id'])) {
            throw new InvalidArgumentException("Invalid server array provided.");
        }

        $this->server = $server;

        $version = implode('.', $mispVersion);
        $this->defaultRequest = [
            'header' => [
                'Authorization' => $server['Server']['authkey'],
                'Accept' => 'application/json',
                'MISP-version' => $version, // Ugly, but to keep BC
                'User-Agent' => 'MISP ' . $version . (empty($mispCommit) ? '' : " - #$mispCommit"),
            ],
        ];
        if ($mispCommit) {
            $this->defaultRequest['header']['commit'] = $mispCommit; // Ugly, but to keep BC
        }

        $syncTool = new SyncTool();
        $this->socket = $syncTool->setupHttpSocket($server, $timeout);
        $this->socket->responseClass = 'JsonHttpSocketResponse';
    }

    /**
     * @return array
     */
    public function getServer()
    {
        return $this->server;
    }

    /**
     * @return int
     */
    public function getServerId()
    {
        return $this->server['Server']['id'];
    }

    /**
     * @return array
     * @throws HttpClientJsonException|ServerSyncException|SocketException
     */
    public function getRemoteUser()
    {
        return $this->get('/users/view/me.json')->json();
    }

    /**
     * @return array
     * @throws HttpClientJsonException|ServerSyncException|SocketException
     */
    public function getVersion()
    {
        if ($this->version) {
            return $this->version;
        }

        $response = $this->get('/servers/getVersion');
        $info = $response->json();
        if (!isset($info['version'])) {
            throw new HttpClientJsonException("Server returns JSON response, but doesn't contain required 'version' field. This may be because the remote server version is outdated.", $response);
        }
        $this->version = $info;
        return $info;
    }

    /**
     * @return string New auth key
     * @throws ServerSyncException
     * @throws HttpClientJsonException|ServerSyncException|SocketException|Exception
     */
    public function resetAuthKey()
    {
        $response = $this->post('/users/resetauthkey/me');
        $json = $response->json();
        if (!isset($json['message'])) {
            throw new HttpClientJsonException("Response key 'message' is missing.", $response);
        }
        $authkey = $json['message'];
        if (substr($authkey, 0, 17) === 'Authkey updated: ') {
            $authkey = substr($authkey, 17, 57);
        } else {
            throw new HttpClientJsonException("Message doesn't contain 'Authkey updated' string.", $response);
        }
        return $authkey;
    }

    /**
     * @param string $testString
     * @return array
     * @throws ServerSyncException
     * @throws HttpClientJsonException|ServerSyncException|SocketException
     */
    public function postTest($testString)
    {
        $response = $this->post('/servers/postTest', $this->encode(['testString' => $testString]));
        return [$response->json(), $response->getHeader('Content-Encoding')];
    }

    /**
     * @param string $eventUuid
     * @return bool
     * @throws HttpClientJsonException
     * @throws ServerSyncException
     * @throws SocketException
     */
    public function eventExists($eventUuid)
    {
        if ($this->isSupported(self::FEATURE_CHECK_UUID)) {
            $response = $this->get("/events/checkuuid/$eventUuid");
            $json = $response->json();
            if (!isset($json['exists'])) {
                throw new HttpClientJsonException("Response JSON doesn't contain 'exists' field.", $response);
            }
            if (!is_bool($json['exists'])) {
                throw new HttpClientJsonException("Response JSON 'exists' field is not boolean.", $response);
            }
            return $json['exists'];
        } else {
            return $this->head("/events/view/$eventUuid");
        }
    }

    /**
     * @param string $eventId Event ID or UUID
     * @param array $params
     * @return array
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     * @throws SocketException
     */
    public function event($eventId, array $params = [])
    {
        return $this->get("/events/view/$eventId", $params)->json();
    }

    /**
     * @param array $filterRules
     * @return array
     * @throws HttpClientJsonException|ServerSyncException|SocketException
     */
    public function eventIndex(array $filterRules)
    {
        return $this->post('/events/index', $this->encode($filterRules))->json();
    }

    /**
     * @param array $events
     * @return array
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     * @see EventsController::filterEventIdsForPush
     */
    public function filterEventIdsForPush(array $events)
    {
        // Keep just required fields to reduce bandwidth
        $onlyRequired = [];
        foreach ($events as $event) {
            $onlyRequired[] = ['Event' => [
                'uuid' => $event['Event']['uuid'],
                'timestamp' => $event['Event']['timestamp'],
            ]];
        }

        return $this->post('/events/filterEventIdsForPush', $this->encode($onlyRequired))->json();
    }

    /**
     * @param array $event
     * @return array
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     */
    public function pushEvent(array $event)
    {
        if (!isset($event['Event']['uuid'])) {
            throw new InvalidArgumentException("Passed event doesn't contain UUID.");
        }
        $data = $this->encode($event);
        $this->syncAudit("Pushing Event #{$event['Event']['uuid']}", $data);
        if (!$this->eventExists($event['Event']['uuid'])) {
            return $this->post('/events/add/metadata:1', $data)->json();
        } else {
            return $this->post("/events/edit/{$event['Event']['uuid']}/metadata:1", $data)->json();
        }
    }

    /**
     * @param int $chunkSize
     * @return Generator<string>|void
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     */
    public function attributeCache($chunkSize = 1000)
    {
        $rules = [
            'returnFormat' => 'cache',
            'includeEventUuid' => 1,
            'limit' => $chunkSize,
        ];

        $page = 1;
        while (true) {
            $rules['page'] = $page;
            $attributes = $this->post('/attributes/restSearch.json', $this->encode($rules));
            $attributes = explode(PHP_EOL, trim($attributes));
            if (empty($attributes)) {
                return;
            }
            yield $attributes;
            if (count($attributes) < $chunkSize) {
                return;
            }
            $page++;
        }
    }

    /**
     * @param array $sightings
     * @return array Sighting UUIDs that should be push
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     * @see SightingsController::filterSightingsForPush
     */
    public function filterSightingsForPush(array $sightings)
    {
        $sightingsUuid = array_column($sightings, 'uuid');
        return $this->post('/sightings/filterSightingsForPush', $this->encode($sightingsUuid))->json();
    }

    /**
     * @param int|string $eventId Event remote ID or UUID
     * @param array $sightings
     * @return array
     * @throws Exception
     */
    public function pushSightings($eventId, array $sightings)
    {
        $data = $this->encode($sightings);
        $this->syncAudit("Pushing Sightings for Event #{$eventId}", $data);
        return $this->post("/sightings/bulkSaveSightings/$eventId", $data)->json();
    }

    /**
     * @param int|string $eventId
     * @return array
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     */
    public function pullSightings($eventId)
    {
        $event = $this->event($eventId, [
            'includeAttachments' => 0,
            'deleted' => [0, 1],
            'excludeGalaxy' => 1,
        ]);

        $sightings = [];
        if (!empty($event['Event']['Attribute'])) {
            foreach ($event['Event']['Attribute'] as $attribute) {
                if (!empty($attribute['Sighting'])) {
                    foreach ($attribute['Sighting'] as $sighting)  {
                        $sightings[] = $sighting;
                    }
                }
            }
        }

        if (!empty($event['Event']['Object'])) {
            foreach ($event['Event']['Object'] as $object) {
                if (!empty($object['Attribute'])) {
                    foreach ($object['Attribute'] as $attribute) {
                        if (!empty($attribute['Sighting'])) {
                            foreach ($attribute['Sighting'] as $sighting)  {
                                $sightings[] = $sighting;
                            }
                        }
                    }
                }
            }
        }

        return $sightings;
    }

    /**
     * @param int|string $eventId Event remote ID or UUID
     * @param array $shadowAttribute
     * @return array
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     */
    public function pushProposals($eventId, array $shadowAttribute)
    {
        $data = $this->encode($shadowAttribute);
        $this->syncAudit("Pushing Proposals for Event #{$eventId}", $data);
        return $this->post("/events/pushProposals/$eventId", $data)->json();
    }

    /**
     * @param int $timestamp
     * @param int $chunkSize
     * @return Generator<array>|void
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     */
    public function pullProposals($timestamp, $chunkSize = 1000)
    {
        $page = 1;
        while (true) {
            $url = "/shadow_attributes/index/all:1/timestamp:$timestamp/limit:$chunkSize/page:$page/deleted[]:0/deleted[]:1.json";
            $proposals = $this->get($url)->json();
            if (empty($data)) {
                return;
            }
            foreach ($proposals as $proposal) {
                yield $proposal;
            }
            if (count($data) < $chunkSize) {
                return;
            }
            $page++;
        }
    }

    /**
     * @param int|string $clusterId
     * @return array
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     */
    public function galaxyCluster($clusterId)
    {
        return $this->get("/galaxy_clusters/view/$clusterId")->json();
    }

    /**
     * @param array $rules
     * @return array
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     */
    public function galaxyClusterSearch(array $rules)
    {
        $response = $this->post('/galaxy_clusters/restSearch', $this->encode($rules));
        $json = $response->json();
        if (!isset($json['response'])) {
            throw new HttpClientJsonException("Response JSON doesn't contain 'response' field.", $response);
        }
        return $json['response'];
    }

    /**
     * @param array $cluster
     * @return array
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     */
    public function pushGalaxyCluster(array $cluster)
    {
        if (!isset($cluster['GalaxyCluster']['id'])){
            throw new InvalidArgumentException("Invalid galaxy cluster provided.");
        }
        $clusterId = $cluster['GalaxyCluster']['id'];
        $data = $this->encode($cluster);
        $this->syncAudit("Pushing Galaxy Cluster #$clusterId", $data);
        return $this->post('/galaxies/pushCluster', $data)->json();
    }

    /**
     * Check if feature is supported by remote server.
     * @param string $feature
     * @return bool
     * @throws ServerSyncException
     * @throws HttpClientJsonException
     */
    public function isSupported($feature)
    {
        switch ($feature) {
            case self::FEATURE_PROPOSALS:
            case self::FEATURE_CHECK_UUID:
            case self::FEATURE_ORG_RULE_AS_ARRAY:
            case self::FEATURE_SIGHTINGS_FILTER:
            case self::FEATURE_POST_TEST:
                $version = explode('.', $this->getVersion()['version']);
                if ($feature === self::FEATURE_PROPOSALS) {
                    return $version[0] == 2 && $version[1] == 4 && $version[2] >= 111;
                } else if ($feature === self::FEATURE_CHECK_UUID || $feature === self::FEATURE_SIGHTINGS_FILTER) {
                    return true; // TODO: Just for testing
                    return $version[0] == 2 && $version[1] == 4 && $version[2] > 136;
                } else if ($feature === self::FEATURE_ORG_RULE_AS_ARRAY) {
                    return $version[0] == 2 && $version[1] == 4 && $version[2] > 123;
                } else if ($feature === self::FEATURE_POST_TEST) {
                    return $version[0] == 2 && $version[1] == 4 && $version[2] > 68;
                }
                break;
            case self::FEATURE_GALAXY_CLUSTER_EDIT:
                return isset($this->getVersion()['perm_galaxy_editor']);
            case self::FEATURE_PUSH:
                $version = $this->getVersion();
                return isset($version['perm_sync']) ? $version['perm_sync'] : false;
            case self::FEATURE_GZIP_REQUESTS:
            case self::FEATURE_BROTLI_REQUESTS:
                $version = $this->getVersion();
                $needle = $feature === self::FEATURE_BROTLI_REQUESTS ? 'br' : 'gzip';
                return isset($version['compressed_requests']) ? in_array($needle, $version['compressed_requests'], true) : false;
        }

        throw new InvalidArgumentException("Invalid feature constant, '$feature' given.");
    }

    /**
     * @param string $url
     * @param array $params
     * @return HttpSocketResponseExtended
     * @throws SocketException
     * @throws ServerSyncException
     */
    public function get($url, array $params = [])
    {
        $url = $this->constructUrl($url, $params);
        $response = $this->socket->get($url, [], $this->defaultRequest);
        $this->validateResponse($url, $response);
        return $response;
    }

    /**
     * @param string $url
     * @param array|string $data
     * @return HttpSocketResponseExtended
     * @throws SocketException
     * @throws ServerSyncException|HttpClientJsonException
     */
    public function post($url, $data = [])
    {
        // For bigger request than 1 kB use compression if remote server supports it
        $contentType = 'application/json';
        if (is_string($data) && strlen($data) > 1024) {
            if (function_exists('brotli_compress') && $this->isSupported(self::FEATURE_BROTLI_REQUESTS)) {
                $data = brotli_compress($data, 3, BROTLI_TEXT);
                $contentType = 'application/x-br';
            } else if (function_exists('gzencode') && $this->isSupported(self::FEATURE_GZIP_REQUESTS)) {
                $data = gzencode($data, 3);
                $contentType = 'application/x-gzip';
            }
        }

        $request = $this->defaultRequest;
        $request['header']['Content-Type'] = $contentType;

        $url = $this->constructUrl($url);
        $response = $this->socket->post($url, $data, $request);
        $this->validateResponse($url, $response);
        return $response;
    }

    /**
     * @param string $url
     * @return bool
     * @throws ServerSyncException
     */
    public function head($url)
    {
        $url = $this->constructUrl($url);
        $response = $this->socket->head($url, [], $this->defaultRequest);
        if ($response && $response->code == 200) {
            return true;
        } else if ($response && $response->code == 404) {
            return false;
        } else {
            $this->validateResponse($url, $response);
            throw new ServerSyncException(__("Invalid HTTP code for '%s', expected 200 or 404, %s given.", $url, $response->code));
        }
    }

    /**
     * @param string $url
     * @param HttpSocketResponseExtended $response
     * @return HttpSocketResponseExtended
     * @throws ServerSyncException
     */
    private function validateResponse($url, HttpSocketResponseExtended $response)
    {
        if ($response->code == 0) {
            if ($this->socket->lastError()) {
                throw new ServerSyncException(__("Fetching the '%s' failed: %s", $url, $this->socket->lastError['str']), $response);
            } else {
                throw new ServerSyncException(__("Fetching the '%s' failed with unknown error.", $url), $response);
            }
        }
        if (!$response->isOk()) {
            $reason = null;
            try {
                $responseJson = $response->json();
                if (isset($responseJson['errors'])) {
                    $reason = $responseJson['errors'];
                }
            } catch (Exception $e) {}
            $message = __("Fetching the '%s' failed with HTTP error %s.", $url, $response->code);
            if ($reason) {
                $message .= "\nReason: '$reason'";
            }
            throw new ServerSyncException($message, $response);
        }
        return $response;
    }

    /**
     * @param string $title
     * @param string $data
     */
    private function syncAudit($title, $data)
    {
        if (empty(Configure::read('Security.sync_audit'))) {
            return;
        }

        $date = date("Y-m-d H:i:s");
        $serverId = $this->getServer()['Server']['id'];
        $pushLogEntry = "==============================================================\n\n[$date] $title to Server #$serverId:\n\n$data\n\n";
        file_put_contents(APP . 'files/scripts/tmp/debug_server_' . $serverId. '.log', $pushLogEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param string $url
     * @return string
     */
    private function constructUrl($url, array $params = [])
    {
        $url = $this->getServer()['Server']['url'] . $url;
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $url .= "/{$key}[]=$v";
                }
            } else {
                $url .= "/$key=$value";
            }
        }
        return $url;
    }

    /**
     * Encodes array as JSON, keep Unicode unescaped (to save bandwidth) and throw exception if something wrong happen.
     * @param array $content
     * @return string
     */
    private function encode(array $content)
    {
        $flags = defined('JSON_THROW_ON_ERROR') ? JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE : JSON_UNESCAPED_UNICODE;
        return json_encode($content, $flags);
    }
}
