<?php
App::uses('AppModel', 'Model');
App::uses('RandomTool', 'Tools');
App::uses('TmpFileTool', 'Tools');
App::uses('CacheTool', 'Tools');

class Feed extends AppModel
{
    public $actsAs = array('SysLogLogable.SysLogLogable' => array(
            'change' => 'full'
        ),
        'Trim',
        'Containable'
    );

    public $belongsTo = array(
            'SharingGroup' => array(
                    'className' => 'SharingGroup',
                    'foreignKey' => 'sharing_group_id',
            ),
            'Tag' => array(
                    'className' => 'Tag',
                    'foreignKey' => 'tag_id',
            ),
            'Orgc' => array(
                    'className' => 'Organisation',
                    'foreignKey' => 'orgc_id'
            )
    );

    public $validate = array(
        'url' => array( // TODO add extra validation to refuse multiple time the same url from the same org
            'rule' => array('urlOrExistingFilepath')
        ),
        'provider' => 'valueNotEmpty',
        'name' => [
            'rule' => 'valueNotEmpty',
            'required' => true,
        ],
        'event_id' => array(
            'rule' => array('numeric'),
            'message' => 'Please enter a numeric event ID or leave this field blank.',
        ),
        'input_source' => array(
            'rule' => 'validateInputSource',
            'message' => ''
        )
    );

    // currently we only have an internal name and a display name, but later on we can expand this with versions, default settings, etc
    public $feed_types = array(
        'misp' => array(
            'name' => 'MISP Feed'
        ),
        'freetext' => array(
            'name' => 'Freetext Parsed Feed'
        ),
        'csv' => array(
            'name' => 'Simple CSV Parsed Feed'
        )
    );

    const REDIS_CACHE_PREFIX = 'misp:cache:';

    public function __construct($id = false, $table = null, $ds = null)
    {
        parent::__construct($id, $table, $ds);

        // Convert to new format since 2020-02-04, can be removed in future
        $redis = $this->setupRedis();
        if ($redis && ($redis->exists('misp:feed_cache:combined') || $redis->exists('misp:server_cache:combined'))) {
            $this->convertToNewRedisCacheFormat();
        }
    }

    /**
     * Cleanup of empty belongsto relationships
     * @param mixed $results
     * @param false $primary
     * @return mixed
     */
    public function afterFind($results, $primary = false)
    {
        foreach ($results as $k => $result) {
            if (isset($result['SharingGroup']) && empty($result['SharingGroup']['id'])) {
                unset($results[$k]['SharingGroup']);
            }
            if (isset($result['Tag']) && empty($result['Tag']['id'])) {
                unset($results[$k]['Tag']);
            }
            if (isset($result['Orgc']) && empty($result['Orgc']['id'])) {
                unset($results[$k]['Orgc']);
            }
        }
        return $results;
    }

    public function validateInputSource($fields)
    {
        if (!empty($this->data['Feed']['input_source'])) {
            $localAllowed = empty(Configure::read('Security.disable_local_feed_access'));
            $validOptions = array('network');
            if ($localAllowed) {
                $validOptions[] = 'local';
            }
            if (!in_array($this->data['Feed']['input_source'], $validOptions)) {
                return __(
                    'Invalid input source. The only valid options are %s. %s',
                    implode(', ', $validOptions),
                    (!$localAllowed && $this->data['Feed']['input_source'] === 'local') ?
                    __('Security.disable_local_feed_access is currently enabled, local feeds are thereby not allowed.') :
                    ''
                );
            }
        }
        return true;
    }

    public function urlOrExistingFilepath($fields)
    {
        if ($this->isFeedLocal($this->data)) {
            if ($this->data['Feed']['source_format'] == 'misp') {
                if (!is_dir($this->data['Feed']['url'])) {
                    return 'For MISP type local feeds, please specify the containing directory.';
                }
            } else {
                if (!file_exists($this->data['Feed']['url'])) {
                    return 'Invalid path or file not found. Make sure that the path points to an existing file that is readable and watch out for typos.';
                }
            }
        } else {
            if (!filter_var($this->data['Feed']['url'], FILTER_VALIDATE_URL)) {
                return false;
            }
        }
        return true;
    }

    public function getFeedTypesOptions()
    {
        return array_column($this->feed_types, 'name');
    }

    /**
     * Gets the event UUIDs from the feed by ID
     * Returns an array with the UUIDs of events that are new or that need updating.
     *
     * @param array $feed
     * @param HttpSocket|null $HttpSocket
     * @return array
     * @throws Exception
     */
    public function getNewEventUuids($feed, HttpSocket $HttpSocket = null)
    {
        $manifest = $this->downloadManifest($feed, $HttpSocket);
        $this->Event = ClassRegistry::init('Event');
        $events = $this->Event->find('all', array(
            'conditions' => array(
                'Event.uuid' => array_keys($manifest),
            ),
            'recursive' => -1,
            'fields' => array('Event.id', 'Event.uuid', 'Event.timestamp')
        ));
        $result = array('add' => array(), 'edit' => array());
        foreach ($events as $event) {
            $eventUuid = $event['Event']['uuid'];
            if ($event['Event']['timestamp'] < $manifest[$eventUuid]['timestamp']) {
                $result['edit'][] = array('uuid' => $eventUuid, 'id' => $event['Event']['id']);
            } else {
                $this->__cleanupFile($feed, '/' . $eventUuid . '.json');
            }
            unset($manifest[$eventUuid]);
        }
        // Rest events in manifest does't exists, they will be added
        $result['add'] = array_keys($manifest);
        return $result;
    }

    /**
     * @param array $feed
     * @param HttpSocket|null $HttpSocket Null can be for local feed
     * @return Generator
     * @throws Exception
     */
    private function getCache(array $feed, HttpSocket $HttpSocket = null)
    {
        $uri = $feed['Feed']['url'] . '/hashes.csv';
        $data = $this->feedGetUri($feed, $uri, $HttpSocket);

        if (empty($data)) {
            throw new Exception("File '$uri' with hashes for cache filling is empty.");
        }

        // CSV file can be pretty big to do operations in memory, so we save content to temp and iterate line by line.
        $tmpFile = new TmpFileTool();
        $tmpFile->write(trim($data));
        unset($data);

        return $tmpFile->intoParsedCsv();
    }

    /**
     * @param array $feed
     * @param HttpSocket|null $HttpSocket Null can be for local feed
     * @return array
     * @throws Exception
     */
    private function downloadManifest($feed, HttpSocket $HttpSocket = null)
    {
        $manifestUrl = $feed['Feed']['url'] . '/manifest.json';
        $data = $this->feedGetUri($feed, $manifestUrl, $HttpSocket, true);

        try {
            return $this->jsonDecode($data);
        } catch (Exception $e) {
            throw new Exception("Could not parse '$manifestUrl' manifest JSON", 0, $e);
        }
    }

    /**
     * @param array $feed
     * @param HttpSocket|null $HttpSocket Null can be for local feed
     * @return array
     * @throws Exception
     */
    public function getManifest($feed, HttpSocket $HttpSocket = null)
    {
        $events = $this->downloadManifest($feed, $HttpSocket);
        $events = $this->__filterEventsIndex($events, $feed);
        return $events;
    }

    /**
     * @param array $feed
     * @param HttpSocket|null $HttpSocket Null can be for local feed
     * @param string $type
     * @param int|string $page
     * @param int $limit
     * @param array $params
     * @return array|bool
     * @throws Exception
     */
    public function getFreetextFeed($feed, HttpSocket $HttpSocket = null, $type = 'freetext', $page = 1, $limit = 60, &$params = array())
    {
        $isLocal = $this->isFeedLocal($feed);
        $data = false;

        if (!$isLocal) {
            $feedCache = APP . 'tmp' . DS . 'cache' . DS . 'misp_feed_' . intval($feed['Feed']['id']) . '.cache';
            if (file_exists($feedCache)) {
                $file = new File($feedCache);
                if (time() - $file->lastChange() < 600) {
                    $data = $file->read();
                    if ($data === false) {
                        throw new Exception("Could not read feed cache file '$feedCache'.");
                    }
                }
            }
        }

        if ($data === false) {
            $feedUrl = $feed['Feed']['url'];
            $data = $this->feedGetUri($feed, $feedUrl, $HttpSocket, true);

            if (!$isLocal) {
                file_put_contents($feedCache, $data); // save to cache
            }
        }

        App::uses('ComplexTypeTool', 'Tools');
        $complexTypeTool = new ComplexTypeTool();
        $this->Warninglist = ClassRegistry::init('Warninglist');
        $complexTypeTool->setTLDs($this->Warninglist->fetchTLDLists());
        $settings = array();
        if (!empty($feed['Feed']['settings']) && !is_array($feed['Feed']['settings'])) {
            $feed['Feed']['settings'] = json_decode($feed['Feed']['settings'], true);
        }
        if (isset($feed['Feed']['settings'][$type])) {
            $settings = $feed['Feed']['settings'][$type];
        }
        if (isset($feed['Feed']['settings']['common'])) {
            $settings = array_merge($settings, $feed['Feed']['settings']['common']);
        }
        $resultArray = $complexTypeTool->checkComplexRouter($data, $type, $settings);
        $this->Attribute = ClassRegistry::init('Attribute');
        foreach ($resultArray as $key => $value) {
            $resultArray[$key]['category'] = $this->Attribute->typeDefinitions[$value['default_type']]['default_category'];
        }
        App::uses('CustomPaginationTool', 'Tools');
        $customPagination = new CustomPaginationTool();
        $params = $customPagination->createPaginationRules($resultArray, array('page' => $page, 'limit' => $limit), 'Feed', $sort = false);
        if (!empty($page) && $page != 'all') {
            $start = ($page - 1) * $limit;
            if ($start > count($resultArray)) {
                return false;
            }
            $resultArray = array_slice($resultArray, $start, $limit);
        }
        return $resultArray;
    }

    public function getFreetextFeedCorrelations($data, $feedId)
    {
        $values = array();
        foreach ($data as $key => $value) {
            $values[] = $value['value'];
        }
        $this->Attribute = ClassRegistry::init('Attribute');
        $redis = $this->setupRedis();
        if ($redis !== false) {
            $feeds = $this->find('all', array(
                'recursive' => -1,
                'conditions' => array('Feed.id !=' => $feedId),
                'fields' => array('id', 'name', 'url', 'provider', 'source_format')
            ));
            foreach ($feeds as $k => $v) {
                if (!$redis->exists('misp:cache:F' . $v['Feed']['id'])) {
                    unset($feeds[$k]);
                }
            }
        } else {
            return array();
        }
        // Adding a 3rd parameter to a list find seems to allow grouping several results into a key. If we ran a normal list with value => event_id we'd only get exactly one entry for each value
        // The cost of this method is orders of magnitude lower than getting all id - event_id - value triplets and then doing a double loop comparison
        $correlations = $this->Attribute->find('list', array('conditions' => array('Attribute.value1' => $values, 'Attribute.deleted' => 0), 'fields' => array('Attribute.event_id', 'Attribute.event_id', 'Attribute.value1')));
        $correlations2 = $this->Attribute->find('list', array('conditions' => array('Attribute.value2' => $values, 'Attribute.deleted' => 0), 'fields' => array('Attribute.event_id', 'Attribute.event_id', 'Attribute.value2')));
        $correlations = array_merge_recursive($correlations, $correlations2);
        foreach ($data as $key => $value) {
            if (isset($correlations[$value['value']])) {
                $data[$key]['correlations'] = array_values($correlations[$value['value']]);
            }
            if ($redis) {
                foreach ($feeds as $k => $v) {
                    if ($redis->sismember('misp:cache:F' . $v['Feed']['id'], md5($value['value'], true))) {
                        $data[$key]['feed_correlations'][] = array($v);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Attach correlations from cached servers or feeds.
     *
     * @param array $attributes
     * @param array $user
     * @param array $event
     * @param string $scope `Feed`, `Server` or `Both`
     * @return array
     */
    public function attachFeedCorrelations(array $attributes, array $user, array &$event, $scope = 'Feed')
    {
        if (!in_array($scope, ['Feed', 'Server', 'Both'], true)) {
            throw new InvalidArgumentException("Invalid scope `$scope` provided.");
        }

        if (empty($attributes)) {
            return $attributes;
        }

        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return $attributes;
        }

        if (!isset($this->Attribute)) {
            $this->Attribute = ClassRegistry::init('Attribute');
        }
        $compositeTypes = $this->Attribute->getCompositeTypes();

        $pipe = $redis->pipeline();
        $redisResultToAttributePosition = [];

        foreach ($attributes as $k => $attribute) {
            if (in_array($attribute['type'], $this->Attribute->nonCorrelatingTypes, true)) {
                continue; // attribute type is not correlateable
            }
            if (!empty($attribute['disable_correlation'])) {
                continue; // attribute correlation is disabled
            }

            if (in_array($attribute['type'], $compositeTypes, true)) {
                list($value1, $value2) = explode('|', $attribute['value']);
                $parts = [$value1];

                if (!in_array($attribute['type'], $this->Attribute->primaryOnlyCorrelatingTypes, true)) {
                    $parts[] = $value2;
                }
            } else {
                $parts = [$attribute['value']];

                // Some feeds contains URL without protocol, so if attribute is URL and value contains protocol,
                // we will check also value without protocol.
                if ($attribute['type'] === 'url' || $attribute['type'] === 'uri') {
                    $protocolPos = strpos($attribute['value'], '://');
                    if ($protocolPos !== false) {
                        $parts[] = substr($attribute['value'], $protocolPos + 3);
                    }
                }
            }

            foreach ($parts as $part) {
                $redis->hGetAll(self::REDIS_CACHE_PREFIX . md5($part, true));
                $redisResultToAttributePosition[] = $k;
            }
        }

        if (empty($redisResultToAttributePosition)) {
            $pipe->discard();
            // No attribute that can be correlated
            return $attributes;
        }

        $results = $pipe->exec();

        $sources = null;
        foreach ($results as $k => $result) {
            if (!$result) {
                continue;
            }
            if (!isset($sources)) {
                $sources = $this->getCachedFeedsOrServers($user, $scope); // lazy load sources
            }
            foreach ($result as $sourceId => $uuids) {
                if (!isset($sources[$sourceId])) {
                    continue; // source is not enabled
                }
                $scopeForSource = $sourceId[0] === 'S' ? 'Server' : 'Feed';
                if (!isset($event[$scopeForSource][$sourceId])) {
                    $event[$scopeForSource][$sourceId] = $sources[$sourceId];
                }
                if ($uuids) {
                    $uuids = explode(',', $uuids);
                    // Attach event UUIDs to Server or Feed
                    foreach ($uuids as $uuid) {
                        if (!isset($event[$scopeForSource][$sourceId]['event_uuids']) || !in_array($uuid, $event[$scopeForSource][$sourceId]['event_uuids'], true)) {
                            $event[$scopeForSource][$sourceId]['event_uuids'][] = $uuid;
                        }
                    }
                }

                $attributePosition = $redisResultToAttributePosition[$k];

                $found = false;
                if (isset($attributes[$attributePosition][$scopeForSource])) {
                    foreach ($attributes[$attributePosition][$scopeForSource] as &$existing) {
                        if ($existing['id'] == $sources[$sourceId]['id']) {
                            $found = true;
                            if ($uuids) {
                                foreach ($uuids as $uuid) {
                                    if (!isset($existing['event_uuids']) || !in_array($uuid, $existing['event_uuids'], true)) {
                                        $existing['event_uuids'][] = $uuid;
                                    }
                                }
                            }
                            break;
                        }
                    }
                }

                if (!$found) {
                    $value = $sources[$sourceId];
                    if ($uuids) {
                        $value['event_uuids'] = $uuids;
                    }
                    $attributes[$attributePosition][$scopeForSource][] = $value;
                }
            }
        }

        if (isset($event['Feed'])) {
            $event['Feed'] = array_values($event['Feed']);
        }
        if (isset($event['Server'])) {
            $event['Server'] = array_values($event['Server']);
        }

        return $attributes;
    }

    /**
     * @param array $user
     * @param string $scope 'Feed', 'Server' or 'Both'
     * @return array Where key is Feed or Server ID prefixed by 'F' or 'S' letter
     */
    private function getCachedFeedsOrServers(array $user, $scope)
    {
        $output = [];
        if ($scope === 'Feed' || $scope === 'Both') {
            $params = array(
                'recursive' => -1,
                'fields' => array('id', 'name', 'url', 'provider', 'source_format')
            );
            if (!$user['Role']['perm_site_admin']) {
                $params['conditions'] = array('Feed.lookup_visible' => 1);
            }
            $feeds = $this->find('all', $params);
            foreach ($feeds as $feed) {
                $output['F' . $feed['Feed']['id']] = $feed['Feed'];
            }
        }

        if ($scope === 'Server' || $scope === 'Both') {
            $params = array(
                'recursive' => -1,
                'fields' => array('id', 'name', 'url')
            );
            if (!$user['Role']['perm_site_admin']) {
                $params['conditions'] = array('Server.caching_enabled' => 1);
            }
            $this->Server = ClassRegistry::init('Server');
            $servers = $this->Server->find('all', $params);
            foreach ($servers as $server) {
                $output['S' . $server['Server']['id']] = $server['Server'];
            }
        }

        return $output;
    }

    public function downloadFromFeed($actions, $feed, HttpSocket $HttpSocket = null, $user, $jobId = false)
    {
        $total = count($actions['add']) + count($actions['edit']);
        $currentItem = 0;
        $this->Event = ClassRegistry::init('Event');
        $results = array();
        $filterRules = $this->__prepareFilterRules($feed);

        foreach ($actions['add'] as $uuid) {
            try {
                $result = $this->__addEventFromFeed($HttpSocket, $feed, $uuid, $user, $filterRules);
                if ($result !== 'blocked') {
                    $results['add']['success'] = $uuid;
                }

            } catch (Exception $e) {
                $this->logException("Could not add event '$uuid' from feed {$feed['Feed']['id']}.", $e);
                $results['add']['fail'] = array('uuid' => $uuid, 'reason' => $e->getMessage());
            }

            $this->__cleanupFile($feed, '/' . $uuid . '.json');
            $this->jobProgress($jobId, null, 100 * (($currentItem + 1) / $total));
            $currentItem++;
        }

        foreach ($actions['edit'] as $editTarget) {
            $uuid = $editTarget['uuid'];
            try {
                $result = $this->__updateEventFromFeed($HttpSocket, $feed, $uuid, $editTarget['id'], $user, $filterRules);
                if ($result !== 'blocked') {
                    $results['edit']['success'] = $uuid;
                }
            } catch (Exception $e) {
                $this->logException("Could not edit event '$uuid' from feed {$feed['Feed']['id']}.", $e);
                $results['edit']['fail'] = array('uuid' => $uuid, 'reason' => $e->getMessage());
            }

            $this->__cleanupFile($feed, '/' . $uuid . '.json');
            if ($currentItem % 10 == 0) {
                $this->jobProgress($jobId, null, 100 * (($currentItem + 1) / $total));
            }
            $currentItem++;
        }

        return $results;
    }

    private function __createFeedRequest($headers = false)
    {
        $version = $this->checkMISPVersion();
        $version = implode('.', $version);

        $result = array(
            'header' => array(
                'Accept' => array('application/json', 'text/plain', 'text/*'),
                'MISP-version' => $version,
                'MISP-uuid' => Configure::read('MISP.uuid'),
            )
        );

        $commit = $this->checkMIPSCommit();
        if ($commit) {
            $result['header']['commit'] = $commit;
        }
        if (!empty($headers)) {
            $lines = explode("\n", $headers);
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $kv = explode(':', $line);
                    if (!empty($kv[0]) && !empty($kv[1])) {
                        if (!in_array($kv[0], array('commit', 'MISP-version', 'MISP-uuid'))) {
                            $result['header'][trim($kv[0])] = trim($kv[1]);
                        }
                    }
                }
            }
        }
        return $result;
    }

    private function __checkIfEventBlockedByFilter($event, $filterRules)
    {
        $fields = array('tags' => 'Tag', 'orgs' => 'Orgc');
        $prefixes = array('OR', 'NOT');
        foreach ($fields as $field => $fieldModel) {
            foreach ($prefixes as $prefix) {
                if (!empty($filterRules[$field][$prefix])) {
                    $found = false;
                    if (isset($event['Event'][$fieldModel]) && !empty($event['Event'][$fieldModel])) {
                        if (!isset($event['Event'][$fieldModel][0])) {
                            $event['Event'][$fieldModel] = array(0 => $event['Event'][$fieldModel]);
                        }
                        foreach ($event['Event'][$fieldModel] as $object) {
                            foreach ($filterRules[$field][$prefix] as $temp) {
                                if (stripos($object['name'], $temp) !== false) {
                                    $found = true;
                                    break 2;
                                }
                            }
                        }
                    }
                    if ($prefix === 'OR' && !$found) {
                        return false;
                    }
                    if ($prefix !== 'OR' && $found) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function __filterEventsIndex($events, $feed)
    {
        $filterRules = $this->__prepareFilterRules($feed);
        if (!$filterRules) {
            $filterRules = array();
        }
        foreach ($events as $k => $event) {
            if (isset($filterRules['orgs']['OR']) && !empty($filterRules['orgs']['OR']) && !in_array($event['Orgc']['name'], $filterRules['orgs']['OR'])) {
                unset($events[$k]);
                continue;
            }
            if (isset($filterRules['orgs']['NO']) && !empty($filterRules['orgs']['NOT']) && in_array($event['Orgc']['name'], $filterRules['orgs']['OR'])) {
                unset($events[$k]);
                continue;
            }
            if (isset($filterRules['tags']['OR']) && !empty($filterRules['tags']['OR'])) {
                if (!isset($event['Tag']) || empty($event['Tag'])) {
                    unset($events[$k]);
                }
                $found = false;
                foreach ($event['Tag'] as $tag) {
                    foreach ($filterRules['tags']['OR'] as $filterTag) {
                        if (strpos(strtolower($tag['name']), strtolower($filterTag))) {
                            $found = true;
                        }
                    }
                }
                if (!$found) {
                    unset($k);
                    continue;
                }
            }
            if (isset($filterRules['tags']['NOT']) && !empty($filterRules['tags']['NOT'])) {
                if (isset($event['Tag']) && !empty($event['Tag'])) {
                    $found = false;
                    foreach ($event['Tag'] as $tag) {
                        foreach ($filterRules['tags']['NOT'] as $filterTag) {
                            if (strpos(strtolower($tag['name']), strtolower($filterTag))) {
                                $found = true;
                            }
                        }
                    }
                    if ($found) {
                        unset($k);
                    }
                }
            }
        }
        return $events;
    }

    /**
     * @param array $feed
     * @param string $uuid
     * @param array $user
     * @return array|bool
     * @throws Exception
     */
    public function downloadAndSaveEventFromFeed(array $feed, $uuid, array $user)
    {
        $event = $this->downloadEventFromFeed($feed, $uuid);
        if (!is_array($event) || isset($event['code'])) {
            return false;
        }
        return $this->__saveEvent($event, $user);
    }

    /**
     * @param array $feed
     * @param string $uuid
     * @return bool|string|array
     * @throws Exception
     */
    public function downloadEventFromFeed(array $feed, $uuid)
    {
        $filerRules = $this->__prepareFilterRules($feed);
        $HttpSocket = $this->isFeedLocal($feed) ? null : $this->__setupHttpSocket($feed);
        $event = $this->downloadAndParseEventFromFeed($feed, $uuid, $HttpSocket);
        return $this->__prepareEvent($event, $feed, $filerRules);
    }

    /**
     * @param array $event
     * @param array $user
     * @return array
     */
    private function __saveEvent(array $event, array $user)
    {
        $this->Event = ClassRegistry::init('Event');
        $existingEvent = $this->Event->find('first', array(
                'conditions' => array('Event.uuid' => $event['Event']['uuid']),
                'recursive' => -1,
                'fields' => array('Event.uuid', 'Event.id', 'Event.timestamp')
        ));
        $result = array();
        if (!empty($existingEvent)) {
            $result['action'] = 'edit';
            if ($existingEvent['Event']['timestamp'] < $event['Event']['timestamp']) {
                $result['result'] = $this->Event->_edit($event, $user);
            } else {
                $result['result'] = 'No change';
            }
        } else {
            $result['action'] = 'add';
            $result['result'] = $this->Event->_add($event, true, $user);
        }
        return $result;
    }

    private function __prepareEvent($event, $feed, $filterRules)
    {
        if (isset($event['response'])) {
            $event = $event['response'];
        }
        if (isset($event[0])) {
            $event = $event[0];
        }
        if (!isset($event['Event']['uuid'])) {
            throw new Exception("Event uuid field missing.");
        }
        $event['Event']['distribution'] = $feed['Feed']['distribution'];
        $event['Event']['sharing_group_id'] = $feed['Feed']['sharing_group_id'];
        if (!empty($event['Event']['Attribute'])) {
            foreach ($event['Event']['Attribute'] as $key => $attribute) {
                $event['Event']['Attribute'][$key]['distribution'] = 5;
            }
        }
        if ($feed['Feed']['tag_id']) {
            if (!isset($event['Event']['Tag'])) {
                $event['Event']['Tag'] = array();
            }
            $found = false;
            foreach ($event['Event']['Tag'] as $tag) {
                if (strtolower($tag['name']) === strtolower($feed['Tag']['name'])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $feedTag = $this->Tag->find('first', array('conditions' => array('Tag.id' => $feed['Feed']['tag_id']), 'recursive' => -1, 'fields' => array('Tag.name', 'Tag.colour', 'Tag.exportable')));
                if (!empty($feedTag)) {
                    $event['Event']['Tag'][] = $feedTag['Tag'];
                }
            }
        }
        if ($feed['Feed']['sharing_group_id']) {
            $sg = $this->SharingGroup->find('first', array(
                    'recursive' => -1,
                    'conditions' => array('SharingGroup.id' => $feed['Feed']['sharing_group_id'])
            ));
            if (!empty($sg)) {
                $event['Event']['SharingGroup'] = $sg['SharingGroup'];
            } else {
                // We have an SG ID for the feed, but the SG is gone. Make the event private as a fall-back.
                $event['Event']['distribution'] = 0;
                $event['Event']['sharing_group_id'] = 0;
            }
        }
        if (!$this->__checkIfEventBlockedByFilter($event, $filterRules)) {
            return 'blocked';
        }
        return $event;
    }

    /**
     * @param array $feed
     * @return bool|mixed
     * @throws Exception
     */
    private function __prepareFilterRules($feed)
    {
        $filterRules = false;
        if (isset($feed['Feed']['rules']) && !empty($feed['Feed']['rules'])) {
            $filterRules = json_decode($feed['Feed']['rules'], true);
            if ($filterRules === null) {
                throw new Exception('Could not parse feed filter rules JSON: ' . json_last_error_msg(), json_last_error());
            }
        }
        return $filterRules;
    }

    private function __setupHttpSocket($feed)
    {
        App::uses('SyncTool', 'Tools');
        $syncTool = new SyncTool();
        return $syncTool->setupHttpSocketFeed($feed);
    }

    /**
     * @param HttpSocket|null $HttpSocket
     * @param array $feed
     * @param string $uuid
     * @param $user
     * @param array|bool $filterRules
     * @return array|bool|string
     * @throws Exception
     */
    private function __addEventFromFeed(HttpSocket $HttpSocket = null, $feed, $uuid, $user, $filterRules)
    {
        $event = $this->downloadAndParseEventFromFeed($feed, $uuid, $HttpSocket);
        $event = $this->__prepareEvent($event, $feed, $filterRules);
        if (is_array($event)) {
            $this->Event = ClassRegistry::init('Event');
            return $this->Event->_add($event, true, $user);
        } else {
            return $event;
        }
    }

    /**
     * @param HttpSocket|null $HttpSocket Null can be for local feed
     * @param array $feed
     * @param string $uuid
     * @param int $eventId
     * @param $user
     * @param array|bool $filterRules
     * @return mixed
     * @throws Exception
     */
    private function __updateEventFromFeed(HttpSocket $HttpSocket = null, $feed, $uuid, $eventId, $user, $filterRules)
    {
        $event = $this->downloadAndParseEventFromFeed($feed, $uuid, $HttpSocket);
        $event = $this->__prepareEvent($event, $feed, $filterRules);
        $this->Event = ClassRegistry::init('Event');
        return $this->Event->_edit($event, $user, $uuid, $jobId = null);
    }

    public function addDefaultFeeds($newFeeds)
    {
        foreach ($newFeeds as $newFeed) {
            $existingFeed = $this->find('list', array('conditions' => array('Feed.url' => $newFeed['url'])));
            $success = true;
            if (empty($existingFeed)) {
                $this->create();
                $feed = array(
                        'name' => $newFeed['name'],
                        'provider' => $newFeed['provider'],
                        'url' => $newFeed['url'],
                        'enabled' => $newFeed['enabled'],
                        'caching_enabled' => !empty($newFeed['caching_enabled']) ? $newFeed['caching_enabled'] : 0,
                        'distribution' => 3,
                        'sharing_group_id' => 0,
                        'tag_id' => 0,
                        'default' => true,
                );
                $result = $this->save($feed) && $success;
            }
        }
        return $success;
    }

    public function downloadFromFeedInitiator($feedId, $user, $jobId = false)
    {
        $this->id = $feedId;
        $this->read();
        if (isset($this->data['Feed']['settings']) && !empty($this->data['Feed']['settings'])) {
            $this->data['Feed']['settings'] = json_decode($this->data['Feed']['settings'], true);
        }

        $HttpSocket = $this->isFeedLocal($this->data) ? null : $this->__setupHttpSocket($this->data);
        if ($this->data['Feed']['source_format'] == 'misp') {
            $this->jobProgress($jobId, 'Fetching event manifest.');
            try {
                $actions = $this->getNewEventUuids($this->data, $HttpSocket);
            } catch (Exception $e) {
                $this->logException("Could not get new event uuids for feed $feedId.", $e);
                $this->jobProgress($jobId, 'Could not fetch event manifest. See log for more details.');
                return false;
            }

            if (empty($actions['add']) && empty($actions['edit'])) {
                return true;
            }

            $total = count($actions['add']) + count($actions['edit']);
            $this->jobProgress($jobId, "Fetching $total events.");
            $result = $this->downloadFromFeed($actions, $this->data, $HttpSocket, $user, $jobId);
            $this->__cleanupFile($this->data, '/manifest.json');
        } else {
            $this->jobProgress($jobId, 'Fetching data.');
            try {
                $temp = $this->getFreetextFeed($this->data, $HttpSocket, $this->data['Feed']['source_format'], 'all');
            } catch (Exception $e) {
                $this->logException("Could not get freetext feed $feedId", $e);
                $this->jobProgress($jobId, 'Could not fetch freetext feed. See error log for more details.');
                return false;
            }

            if (empty($temp)) {
                return true;
            }

            $data = array();
            foreach ($temp as $value) {
                $data[] = array(
                    'category' => $value['category'],
                    'type' => $value['default_type'],
                    'value' => $value['value'],
                    'to_ids' => $value['to_ids']
                );
            }

            $this->jobProgress($jobId, 'Saving data.', 50);

            try {
                $result = $this->saveFreetextFeedData($this->data, $data, $user, $jobId);
            } catch (Exception $e) {
                $this->logException("Could not save freetext feed data for feed $feedId.", $e);
                return false;
            }

            $this->__cleanupFile($this->data, '');
        }
        return $result;
    }

    private function __cleanupFile($feed, $file)
    {
        if ($this->isFeedLocal($feed)) {
            if (isset($feed['Feed']['delete_local_file']) && $feed['Feed']['delete_local_file']) {
                if (file_exists($feed['Feed']['url'] . $file)) {
                    unlink($feed['Feed']['url'] . $file);
                }
            }
        }
        return true;
    }

    /**
     * @param array $feed
     * @param array $data
     * @param array $user
     * @param int|bool $jobId
     * @return bool
     * @throws Exception
     */
    public function saveFreetextFeedData(array $feed, array $data, array $user, $jobId = false)
    {
        $this->Event = ClassRegistry::init('Event');

        if ($feed['Feed']['fixed_event'] && $feed['Feed']['event_id']) {
            $event = $this->Event->find('first', array('conditions' => array('Event.id' => $feed['Feed']['event_id']), 'recursive' => -1));
            if (empty($event)) {
                throw new Exception("The target event is no longer valid. Make sure that the target event {$feed['Feed']['event_id']} exists.");
            }
        } else {
            $this->Event->create();
            $orgc_id = $user['org_id'];
            if (!empty($feed['Feed']['orgc_id'])) {
                $orgc_id = $feed['Feed']['orgc_id'];
            }
            $event = array(
                    'info' => $feed['Feed']['name'] . ' feed',
                    'analysis' => 2,
                    'threat_level_id' => 4,
                    'orgc_id' => $orgc_id,
                    'org_id' => $user['org_id'],
                    'date' => date('Y-m-d'),
                    'distribution' => $feed['Feed']['distribution'],
                    'sharing_group_id' => $feed['Feed']['sharing_group_id'],
                    'user_id' => $user['id']
            );
            $result = $this->Event->save($event);
            if (!$result) {
                throw new Exception('Something went wrong while creating a new event.');
            }
            $event = $this->Event->find('first', array('conditions' => array('Event.id' => $this->Event->id), 'recursive' => -1));
            if (empty($event)) {
                throw new Exception("The newly created event is no longer valid. Make sure that the target event {$this->Event->id} exists.");
            }
            if ($feed['Feed']['fixed_event']) {
                $feed['Feed']['event_id'] = $event['Event']['id'];
                if (!empty($feed['Feed']['settings'])) {
                    $feed['Feed']['settings'] = json_encode($feed['Feed']['settings']);
                }
                $this->save($feed);
            }
        }
        if ($feed['Feed']['fixed_event']) {
            $existsAttributesValueToId = $this->Event->Attribute->find('list', array(
                'conditions' => array(
                    'Attribute.deleted' => 0,
                    'Attribute.event_id' => $event['Event']['id']
                ),
                'recursive' => -1,
                'fields' => array('value', 'id')
            ));

            // Create event diff. After this cycle, `$data` will contains just attributes that do not exists in current
            // event and in `$existsAttributesValueToId` will contains just attributes that do not exists in current feed.
            foreach ($data as $k => $dataPoint) {
                if (isset($existsAttributesValueToId[$dataPoint['value']])) {
                    unset($data[$k]);
                    unset($existsAttributesValueToId[$dataPoint['value']]);
                    continue;
                }

                // Because some types can be saved in modified version (for example, IPv6 address is convert to compressed
                // format, we should also check if current event contains modified value.
                $modifiedValue = $this->Event->Attribute->modifyBeforeValidation($dataPoint['type'], $dataPoint['value']);
                if (isset($existsAttributesValueToId[$modifiedValue])) {
                    unset($data[$k]);
                    unset($existsAttributesValueToId[$modifiedValue]);
                }
            }
            if ($feed['Feed']['delta_merge'] && !empty($existsAttributesValueToId)) {
                $attributesToDelete = $this->Event->Attribute->find('all', array(
                    'conditions' => array(
                        'Attribute.id' => array_values($existsAttributesValueToId)
                    ),
                    'recursive' => -1
                ));
                foreach ($attributesToDelete as $k => $attribute) {
                    $attributesToDelete[$k]['Attribute']['deleted'] = 1;
                    unset($attributesToDelete[$k]['Attribute']['timestamp']);
                }
                $this->Event->Attribute->saveMany($attributesToDelete); // We need to trigger callback methods
                if (!empty($attributesToDelete)) {
                    $this->Event->unpublishEvent($feed['Feed']['event_id']);
                }
            }
        }
        if (empty($data) && empty($attributesToDelete)) {
            return true;
        }

        $uniqueValues = array();
        foreach ($data as $key => $value) {
            if (isset($uniqueValues[$value['value']])) {
                unset($data[$key]);
                continue;
            }
            $data[$key]['event_id'] = $event['Event']['id'];
            $data[$key]['distribution'] = $feed['Feed']['distribution'];
            $data[$key]['sharing_group_id'] = $feed['Feed']['sharing_group_id'];
            $data[$key]['to_ids'] = $feed['Feed']['override_ids'] ? 0 : $value['to_ids'];
            $uniqueValues[$value['value']] = true;
        }
        $data = array_values($data);
        foreach ($data as $k => $chunk) {
            $this->Event->Attribute->create();
            $this->Event->Attribute->save($chunk);
            if ($k % 100 === 0) {
                $this->jobProgress($jobId, null, 50 + round(($k + 1) / count($data) * 50));
            }
        }
        if (!empty($data) || !empty($attributesToDelete)) {
            unset($event['Event']['timestamp']);
            unset($event['Event']['attribute_count']);
            $this->Event->save($event);
        }
        if ($feed['Feed']['publish']) {
            $this->Event->publishRouter($event['Event']['id'], null, $user);
        }
        if ($feed['Feed']['tag_id']) {
            $this->Event->EventTag->attachTagToEvent($event['Event']['id'], $feed['Feed']['tag_id']);
        }
        return true;
    }

    /**
     * @param $user - Not used
     * @param int|bool $jobId
     * @param string $scope
     * @return array
     * @throws Exception
     */
    public function cacheFeedInitiator($user, $jobId = false, $scope = 'freetext')
    {
        $params = array(
            'conditions' => array('caching_enabled' => 1),
            'recursive' => -1,
            'fields' => array('source_format', 'input_source', 'url', 'id', 'settings', 'headers')
        );
        $redis = $this->setupRedisWithException();
        if ($scope !== 'all') {
            if (is_numeric($scope)) {
                $params['conditions']['id'] = $scope;
            } elseif ($scope == 'freetext' || $scope == 'csv') {
                $params['conditions']['source_format'] = array('csv', 'freetext');
            } elseif ($scope == 'misp') {
                $params['conditions']['source_format'] = 'misp';
            } else {
                throw new InvalidArgumentException("Invalid value for scope, it must be integer or 'freetext', 'csv', 'misp' or 'all' string.");
            }
        }
        $feeds = $this->find('all', $params);

        $results = array('successes' => 0, 'fails' => 0);
        foreach ($feeds as $k => $feed) {
            if ($this->__cacheFeed($feed, $redis, $jobId)) {
                $message = 'Feed ' . $feed['Feed']['id'] . ' cached.';
                $results['successes']++;
            } else {
                $message = 'Failed to cache feed ' . $feed['Feed']['id'] . '. See logs for more details.';
                $results['fails']++;
            }

            $this->jobProgress($jobId, $message, 100 * $k / count($feeds));
        }
        return $results;
    }

    /**
     * @param array $feeds
     * @return array
     */
    public function attachFeedCacheTimestamps(array $feeds)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return $feeds;
        }

        $pipe = $redis->multi(Redis::PIPELINE);
        foreach ($feeds as $feed) {
            $pipe->get('misp:cache_timestamp:F' . $feed['Feed']['id']);
        }
        $result = $redis->exec();
        foreach ($feeds as $k => $feed) {
            $feeds[$k]['Feed']['cache_timestamp'] = $result[$k];
        }
        return $feeds;
    }

    private function __cacheFeed($feed, $redis, $jobId = false)
    {
        $HttpSocket = $this->isFeedLocal($feed) ? null : $this->__setupHttpSocket($feed);
        if ($feed['Feed']['source_format'] === 'misp') {
            return $this->__cacheMISPFeed($feed, $redis, $HttpSocket, $jobId);
        } else {
            return $this->__cacheFreetextFeed($feed, $redis, $HttpSocket, $jobId);
        }
    }

    /**
     * @param array $feed
     * @param Redis $redis
     * @param HttpSocket|null $HttpSocket
     * @param int|false $jobId
     * @return bool
     * @throws Exception
     */
    private function __cacheFreetextFeed(array $feed, $redis, HttpSocket $HttpSocket = null, $jobId = false)
    {
        $feedId = $feed['Feed']['id'];

        $this->jobProgress($jobId, __("Feed %s: Fetching.", $feedId));

        try {
            $values = $this->getFreetextFeed($feed, $HttpSocket, $feed['Feed']['source_format'], 'all');
        } catch (Exception $e) {
            $this->logException("Could not get freetext feed $feedId", $e);
            $this->jobProgress($jobId, __('Could not fetch freetext feed %s. See error log for more details.', $feedId));
            return false;
        }

        // Convert values to MD5 hashes
        $iterator = function () use ($values, $jobId, $feedId) {
            $count = count($values);
            foreach ($values as $i => $value) {
                yield md5($value['value'], true);
                if ($i % 5000 === 0) {
                    $progress = $i === 0 ? 0 : ($count / $i) * 100;
                    $this->jobProgress($jobId, __('Feed %s: %s/%s values cached.', $feedId, $i, $count), $progress);
                }
            }
        };

        $this->insertToRedisCache('feed', $feedId, $iterator());
        return true;
    }

    /**
     * @param $feed
     * @param $redis
     * @param HttpSocket|null $HttpSocket
     * @param false $jobId
     * @return false|Generator
     */
    private function __cacheMISPFeedTraditional($feed, $redis, HttpSocket $HttpSocket = null, $jobId = false)
    {
        $feedId = $feed['Feed']['id'];
        try {
            $manifest = $this->getManifest($feed, $HttpSocket);
        } catch (Exception $e) {
            $this->logException("Could not get manifest for feed $feedId.", $e);
            return false;
        }

        $this->Attribute = ClassRegistry::init('Attribute');
        $k = 0;
        foreach ($manifest as $uuid => $event) {
            try {
                $event = $this->downloadAndParseEventFromFeed($feed, $uuid, $HttpSocket);
            } catch (Exception $e) {
                $this->logException("Could not get and parse event '$uuid' for feed $feedId.", $e);
                continue;
            }

            if (!empty($event['Event']['Attribute'])) {
                foreach ($event['Event']['Attribute'] as $attribute) {
                    if (!in_array($attribute['type'], $this->Attribute->nonCorrelatingTypes)) {
                        if (in_array($attribute['type'], $this->Attribute->getCompositeTypes())) {
                            $value = explode('|', $attribute['value']);
                            if (in_array($attribute['type'], $this->Attribute->primaryOnlyCorrelatingTypes)) {
                                unset($value[1]);
                            }
                        } else {
                            $value = [$attribute['value']];
                        }

                        foreach ($value as $v) {
                            yield [md5($v, true), $event['Event']['uuid']];
                        }
                    }
                }
            }

            $k++;
            if ($k % 10 === 0) {
                $this->jobProgress($jobId, "Feed $feedId: $k/" . count($manifest) . " events cached.");
            }
        }
    }

    /**
     * @param array $feed
     * @param Redis $redis
     * @param HttpSocket|null $HttpSocket
     * @param int|false $jobId
     * @return bool
     * @throws Exception
     */
    private function __cacheMISPFeedCache(array $feed, $redis, HttpSocket $HttpSocket = null, $jobId = false)
    {
        $feedId = $feed['Feed']['id'];

        try {
            $cache = $this->getCache($feed, $HttpSocket);
        } catch (Exception $e) {
            $this->logException("Could not get cache file for $feedId.", $e, LOG_NOTICE);
            return false;
        }

        $iterator = function ($cache) {
            foreach ($cache as $c) {
                yield [hex2bin($c[0]), $c[1]]; // Convert hash to binary format
            }
        };

        $this->insertToRedisCache('feed', $feedId, $iterator($cache), true);

        $this->jobProgress($jobId, __("Feed %s: cached via quick cache.", $feedId));
        return true;
    }

    private function __cacheMISPFeed($feed, $redis, HttpSocket $HttpSocket = null, $jobId = false)
    {
        $result = true;
        if (!$this->__cacheMISPFeedCache($feed, $redis, $HttpSocket, $jobId)) {
            $result = $this->__cacheMISPFeedTraditional($feed, $redis, $HttpSocket, $jobId);
            if ($result) {
                $this->insertToRedisCache('feed', $feed['Feed']['id'], $result, true);
            }
        }
        return $result;
    }

    public function compareFeeds()
    {
        $redis = $this->setupRedis();
        if ($redis === false) {
            return array();
        }
        $fields = array('id', 'input_source', 'source_format', 'url', 'provider', 'name', 'default');
        $feeds = $this->find('all', array(
            'recursive' => -1,
            'fields' => $fields,
            'conditions' => array('Feed.caching_enabled' => 1)
        ));
        // we'll use this later for the intersect
        $fields[] = 'values';
        $fields = array_flip($fields);
        // Get all of the feed cache cardinalities for all feeds - if a feed is not cached remove it from the list
        foreach ($feeds as $k => $feed) {
            if (!$redis->exists(self::REDIS_CACHE_PREFIX . 'F' . $feed['Feed']['id'])) {
                unset($feeds[$k]);
                continue;
            }
            $feeds[$k]['Feed']['values'] = $redis->sCard(self::REDIS_CACHE_PREFIX . 'F' . $feed['Feed']['id']);
        }
        $feeds = array_values($feeds);
        $this->Server = ClassRegistry::init('Server');
        $servers = $this->Server->find('all', array(
            'recursive' => -1,
            'fields' => array('id', 'url', 'name'),
            'contain' => array('RemoteOrg' => array('fields' => array('RemoteOrg.id', 'RemoteOrg.name'))),
            'conditions' => array('Server.caching_enabled' => 1)
        ));
        foreach ($servers as $k => $server) {
            if (!$redis->exists(self::REDIS_CACHE_PREFIX . 'S' . $server['Server']['id'])) {
                unset($servers[$k]);
                continue;
            }
            $servers[$k]['Server']['input_source'] = 'network';
            $servers[$k]['Server']['source_format'] = 'misp';
            $servers[$k]['Server']['provider'] = $servers[$k]['RemoteOrg']['name'];
            $servers[$k]['Server']['default'] = false;
            $servers[$k]['Server']['is_misp_server'] = true;
            $servers[$k]['Server']['values'] = $redis->sCard(self::REDIS_CACHE_PREFIX . 'S' . $server['Server']['id']);
        }
        foreach ($feeds as $k => $feed) {
            foreach ($feeds as $k2 => $feed2) {
                if ($k == $k2) {
                    continue;
                }
                $intersect = $redis->sInter('misp:cache:F' . $feed['Feed']['id'], 'misp:cache:F' . $feed2['Feed']['id']);
                $feeds[$k]['Feed']['ComparedFeed'][] = array_merge(array_intersect_key($feed2['Feed'], $fields), array(
                    'overlap_count' => count($intersect),
                    'overlap_percentage' => round(100 * count($intersect) / $feeds[$k]['Feed']['values']),
                ));
            }
            foreach ($servers as $k2 => $server) {
                $intersect = $redis->sInter('misp:cache:F' . $feed['Feed']['id'], 'misp:cache:S' . $server['Server']['id']);
                $feeds[$k]['Feed']['ComparedFeed'][] = array_merge(array_intersect_key($server['Server'], $fields), array(
                    'overlap_count' => count($intersect),
                    'overlap_percentage' => round(100 * count($intersect) / $feeds[$k]['Feed']['values']),
                ));
            }
        }
        foreach ($servers as $k => $server) {
            foreach ($feeds as $k2 => $feed2) {
                $intersect = $redis->sInter('misp:cache:S' . $server['Server']['id'], 'misp:cache:F' . $feed2['Feed']['id']);
                $servers[$k]['Server']['ComparedFeed'][] = array_merge(array_intersect_key($feed2['Feed'], $fields), array(
                    'overlap_count' => count($intersect),
                    'overlap_percentage' => round(100 * count($intersect) / $servers[$k]['Server']['values']),
                ));
            }
            foreach ($servers as $k2 => $server2) {
                if ($k == $k2) {
                    continue;
                }
                $intersect = $redis->sInter('misp:cache:S' . $server['Server']['id'], 'misp:cache:S' . $server2['Server']['id']);
                $servers[$k]['Server']['ComparedFeed'][] = array_merge(array_intersect_key($server2['Server'], $fields), array(
                    'overlap_count' => count($intersect),
                    'overlap_percentage' => round(100 * count($intersect) / $servers[$k]['Server']['values']),
                ));
            }
        }
        foreach ($servers as $k => $server) {
            $server['Feed'] = $server['Server'];
            unset($server['Server']);
            $feeds[] = $server;
        }
        return $feeds;
    }

    public function importFeeds($feeds, $user, $default = false)
    {
        if (is_string($feeds)) {
            $feeds = json_decode($feeds, true);
        }
        if ($feeds && !isset($feeds[0])) {
            $feeds = array($feeds);
        }
        $results = array('successes' => 0, 'fails' => 0);
        if (empty($feeds)) {
            return $results;
        }
        $existingFeeds = $this->find('all', array());
        foreach ($feeds as $feed) {
            if ($default) {
                $feed['Feed']['default'] = 1;
            } else {
                $feed['Feed']['default'] = 0;
            }
            if (isset($feed['Feed']['id'])) {
                unset($feed['Feed']['id']);
            }
            $found = false;
            foreach ($existingFeeds as $existingFeed) {
                if ($existingFeed['Feed']['url'] == $feed['Feed']['url']) {
                    $found = true;
                }
            }
            if (!$found) {
                $feed['Feed']['tag_id'] = 0;
                if (isset($feed['Tag'])) {
                    $tag_id = $this->Tag->captureTag($feed['Tag'], $user);
                    if ($tag_id) {
                        $feed['Feed']['tag_id'] = $tag_id;
                    }
                }
                $this->create();
                if (!$this->save($feed, true, array('name', 'provider', 'url', 'rules', 'source_format', 'fixed_event', 'delta_merge', 'override_ids', 'publish', 'settings', 'tag_id', 'default', 'lookup_visible', 'headers'))) {
                    $results['fails']++;
                } else {
                    $results['successes']++;
                }
            }
        }
        return $results;
    }

    public function load_default_feeds()
    {
        $user = array('Role' => array('perm_tag_editor' => 1, 'perm_site_admin' => 1));
        $json = file_get_contents(APP . 'files/feed-metadata/defaults.json');
        $this->importFeeds($json, $user, true);
        return true;
    }

    public function setEnableFeedCachingDefaults()
    {
        $feeds = $this->find('all', array(
            'conditions' => array(
                'Feed.enabled' => 1
            ),
            'recursive' => -1
        ));
        if (empty($feeds)) {
            return true;
        }
        foreach ($feeds as $feed) {
            $feed['Feed']['caching_enabled'] = 1;
            $this->save($feed);
        }
        return true;
    }

    public function getFeedCoverage($id, $source_scope = 'feed', $dataset = 'all')
    {
        $redis = $this->setupRedis();
        if ($redis === false) {
            return 'Could not reach Redis.';
        }
        $this->Server = ClassRegistry::init('Server');
        $feed_conditions = array('Feed.caching_enabled' => 1);
        $server_conditions = array('Server.caching_enabled' => 1);
        if ($source_scope === 'feed') {
            $feed_conditions['NOT'] = array('Feed.id' => $id);
        } else {
            $server_conditions['NOT'] = array('Server.id' => $id);
        }
        if ($dataset !== 'all') {
            if (empty($dataset['Feed'])) {
                $feed_conditions['OR'] = array('Feed.id' => -1);
            } else {
                $feed_conditions['OR'] = array('Feed.id' => $dataset['Feed']);
            }
            if (empty($dataset['Server'])) {
                $server_conditions['OR'] = array('Server.id' => -1);
            } else {
                $server_conditions['OR'] = array('Server.id' => $dataset['Server']);
            }
        }
        $other_feeds = $this->find('column', array(
            'conditions' => $feed_conditions,
            'fields' => array('Feed.id')
        ));
        $other_servers = $this->Server->find('column', array(
            'conditions' => $server_conditions,
            'fields' => array('Server.id')
        ));
        $feed_element_count = $redis->scard('misp:cache:F' . $id);
        $temp_store = (new RandomTool())->random_str(false, 12);
        $params = array('misp:feed_temp:' . $temp_store);
        foreach ($other_feeds as $other_feed) {
            $params[] = 'misp:cache:F' . $other_feed;
        }
        foreach ($other_servers as $other_server) {
            $params[] = 'misp:cache:S' . $other_server;
        }
        if (count($params) != 1 && $feed_element_count > 0) {
            call_user_func_array(array($redis, 'sunionstore'), $params);
            call_user_func_array(array($redis, 'sinterstore'), array('misp:feed_temp:' . $temp_store . '_intersect', 'misp:cache:F' . $id, 'misp:feed_temp:' . $temp_store));
            $cardinality_intersect = $redis->scard('misp:feed_temp:' . $temp_store . '_intersect');
            $coverage = round(100 * $cardinality_intersect / $feed_element_count, 2);
            $redis->del('misp:feed_temp:' . $temp_store);
            $redis->del('misp:feed_temp:' . $temp_store . '_intersect');
        } else {
            $coverage = 0;
        }
        return $coverage;
    }

    public function getCachedElements($feedId)
    {
        $redis = $this->setupRedis();
        $cardinality = $redis->sCard('misp:cache:F' . $feedId);
        return $cardinality;
    }

    public function getAllCachingEnabledFeeds($feedId, $intersectingOnly = false)
    {
        if ($intersectingOnly) {
            $redis = $this->setupRedis();
        }
        $result['Feed'] = $this->find('all', array(
            'conditions' => array(
                'Feed.id !=' => $feedId,
                'caching_enabled' => 1
            ),
            'recursive' => -1,
            'fields' => array('Feed.id', 'Feed.name', 'Feed.url')
        ));
        $this->Server = ClassRegistry::init('Server');
        $result['Server'] = $this->Server->find('all', array(
            'conditions' => array(
                'caching_enabled' => 1
            ),
            'recursive' => -1,
            'fields' => array('Server.id', 'Server.name', 'Server.url')
        ));
        $scopes = array('Feed', 'Server');
        foreach ($scopes as $scope) {
            foreach ($result[$scope] as $k => $v) {
                $result[$scope][$k] = $v[$scope];
            }
        }
        if ($intersectingOnly) {
            foreach ($scopes as $scope) {
                if (!empty($result[$scope])) {
                    foreach ($result[$scope] as $k => $feed) {
                        $otherKey = 'misp:cache:' . ($scope === 'Server' ? 'S' : 'F') . $feed['id'];
                        $intersect = $redis->sInter('misp:cache:F' . $feedId, $otherKey);
                        if (empty($intersect)) {
                            unset($result[$scope][$k]);
                        } else {
                            $result[$scope][$k]['matching_values'] = count($intersect);
                        }
                    }
                }
            }
        }
        return $result;
    }

    public function searchCaches($value)
    {
        $value = strtolower(trim($value));

        $sources = [];
        $feeds = $this->find('all', array(
            'conditions' => array('caching_enabled' => 1),
            'recursive' => -1,
            'fields' => array('Feed.id', 'Feed.name', 'Feed.url', 'Feed.source_format')
        ));
        foreach ($feeds as $feed) {
            $sources['F' . $feed['Feed']['id']] = $feed['Feed'];
        }

        $this->Server = ClassRegistry::init('Server');
        $servers = $this->Server->find('all', array(
            'conditions' => array('caching_enabled' => 1),
            'recursive' => -1,
            'fields' => array('Server.id', 'Server.name', 'Server.url')
        ));
        foreach ($servers as $server) {
            $sources['S' . $server['Server']['id']] = $server['Server'];
        }

        $redis = $this->setupRedisWithException();
        $results = $redis->hGetAll('misp:cache:' . md5($value, true));

        $hits = [];
        foreach ($results as $sourceId => $uuids) {
            if (!isset($sources[$sourceId])) {
                continue;
            }
            $hit = $sources[$sourceId];
            if ($uuids) {
                $isServer = $sourceId[0] === 'S';
                $hit['type'] = $isServer ? 'MISP Server' : 'MISP Feed';
                $hit['uuid'] = explode(',', $uuids);
                if ($isServer) {
                    foreach ($hit['uuid'] as $uuid) {
                        $hit['direct_urls'][] = array(
                            'url' => sprintf(
                                '%s/servers/previewEvent/%s/%s',
                                Configure::read('MISP.baseurl'),
                                h($hit['id']),
                                h($uuid)
                            ),
                            'name' => __('Event %s', h($uuid))
                        );
                    }
                } else {
                    foreach ($hit['uuid'] as $uuid) {
                        $hit['direct_urls'][] = array(
                            'url' => sprintf(
                                '%s/feeds/previewEvent/%s/%s',
                                Configure::read('MISP.baseurl'),
                                h($hit['id']),
                                h($uuid)
                            ),
                            'name' => __('Event %s', $uuid)
                        );
                    }
                }
            } else {
                $hit['type'] = 'Feed';
                $hit['direct_urls'][] = array(
                    'url' => sprintf(
                        '%s/feeds/previewIndex/%s',
                        Configure::read('MISP.baseurl'),
                        h($hit['id'])
                    ),
                    'name' => __('Feed %s', h($hit['id']))
                );
            }
            $hits[] = ['Feed' => $hit];
        }

        return $hits;
    }

    /**
     * Download and parse event from feed.
     *
     * @param array $feed
     * @param string $eventUuid
     * @param HttpSocket|null $HttpSocket Null can be for local feed
     * @return array
     * @throws Exception
     */
    private function downloadAndParseEventFromFeed($feed, $eventUuid, HttpSocket $HttpSocket = null)
    {
        if (!Validation::uuid($eventUuid)) {
            throw new InvalidArgumentException("Given event UUID '$eventUuid' is invalid.");
        }

        $path = $feed['Feed']['url'] . '/' . $eventUuid . '.json';
        $data = $this->feedGetUri($feed, $path, $HttpSocket);

        try {
            return $this->jsonDecode($data);
        } catch (Exception $e) {
            throw new Exception("Could not parse event JSON with UUID '$eventUuid' from feed", 0, $e);
        }
    }

    /**
     * @param array $feed
     * @param string $uri
     * @param HttpSocket|null $HttpSocket Null can be for local feed
     * @param bool $followRedirect
     * @return string
     * @throws Exception
     */
    private function feedGetUri($feed, $uri, HttpSocket $HttpSocket = null, $followRedirect = false)
    {
        if ($this->isFeedLocal($feed)) {
            if (file_exists($uri)) {
                $data = file_get_contents($uri);
                if ($data === false) {
                    throw new Exception("Could not read local file '$uri'.");
                }
                return $data;
            } else {
                throw new Exception("Local file '$uri' doesn't exists.");
            }
        }

        if ($HttpSocket === null) {
            throw new Exception("Feed {$feed['Feed']['name']} is not local, but HttpSocket is not initialized.");
        }

        $request = $this->__createFeedRequest($feed['Feed']['headers']);

        try {
            if ($followRedirect) {
                $response = $this->getFollowRedirect($HttpSocket, $uri, $request);
            } else {
                $response = $HttpSocket->get($uri, array(), $request);
            }
        } catch (Exception $e) {
            throw new Exception("Fetching the '$uri' failed with exception: {$e->getMessage()}", 0, $e);
        }

        if ($response->code != 200) { // intentionally !=
            throw new Exception("Fetching the '$uri' failed with HTTP error {$response->code}: {$response->reasonPhrase}");
        }

        $data = $response->body;

        $contentType = $response->getHeader('Content-Type');
        if ($contentType === 'application/zip') {
            $zipFile = new File($this->tempFileName());
            $zipFile->write($data);
            $zipFile->close();

            try {
                $data = $this->unzipFirstFile($zipFile);
            } catch (Exception $e) {
                throw new Exception("Fetching the '$uri' failed: {$e->getMessage()}");
            } finally {
                $zipFile->delete();
            }
        }

        return $data;
    }

    /**
     * It should be possible to use 'redirect' $request attribute, but because HttpSocket contains bug that require
     * certificate for first domain even when redirect to another domain, we need to use own solution.
     *
     * @param HttpSocket $HttpSocket
     * @param string $url
     * @param array $request
     * @param int $iterations
     * @return false|HttpSocketResponse
     * @throws Exception
     */
    private function getFollowRedirect(HttpSocket $HttpSocket, $url, $request, $iterations = 5)
    {
        for ($i = 0; $i < $iterations; $i++) {
            $response = $HttpSocket->get($url, array(), $request);
            if ($response->isRedirect()) {
                $HttpSocket = $this->__setupHttpSocket(null); // Replace $HttpSocket with fresh instance
                $url = trim($response->getHeader('Location'), '=');
            } else {
                return $response;
            }
        }

        throw new Exception("Maximum number of iteration reached.");
    }

    /**
     * @param array $feed
     * @return bool
     */
    private function isFeedLocal($feed)
    {
        return isset($feed['Feed']['input_source']) && $feed['Feed']['input_source'] === 'local';
    }

    /**
     * @param int|null $jobId
     * @param string|null $message
     * @param int|null $progress
     */
    private function jobProgress($jobId = null, $message = null, $progress = null)
    {
        if ($jobId) {
            if (!isset($this->Job)) {
                $this->Job = ClassRegistry::init('Job');
            }
            $this->Job->saveProgress($jobId, $message, $progress);
        }
    }

    /**
     * remove all events tied to a feed. Returns int on success, error message
     * as string on failure
     */
    public function cleanupFeedEvents($user_id, $id)
    {
        $feed = $this->find('first', array(
            'conditions' => array('Feed.id' => $id),
            'recursive' => -1
        ));
        if (empty($feed)) {
            return __('Invalid feed id.');
        }
        if (!in_array($feed['Feed']['source_format'], array('csv', 'freetext'))) {
            return __('Feed has to be either a CSV or a freetext feed for the purging to work.');
        }
        $this->User = ClassRegistry::init('User');
        $user = $this->User->getAuthUser($user_id);
        if (empty($user)) {
            return __('Invalid user id.');
        }
        $conditions = array('Event.info' => $feed['Feed']['name'] . ' feed');
        $this->Event = ClassRegistry::init('Event');
        $events = $this->Event->find('list', array(
            'conditions' => $conditions,
            'fields' => array('Event.id', 'Event.id')
        ));
        $count = count($events);
        foreach ($events as $event_id) {
            $this->Event->delete($event_id);
        }
        $this->Log = ClassRegistry::init('Log');
        $this->Log->create();
        $this->Log->save(array(
                'org' => 'SYSTEM',
                'model' => 'Feed',
                'model_id' => $id,
                'email' => $user['email'],
                'action' => 'purge_events',
                'title' => __('Events related to feed %s purged.', $id),
                'change' => null,
        ));
        $feed['Feed']['fixed_event'] = 1;
        $feed['Feed']['event_id'] = 0;
        $this->save($feed);
        return $count;
    }

    /**
     * @param File $zipFile
     * @return string Uncompressed data
     * @throws Exception
     */
    private function unzipFirstFile(File $zipFile)
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZIP archive decompressing is not supported. ZIP extension is missing in PHP.');
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipFile->pwd());
        if ($result !== true) {
            $errorCodes = [
                ZipArchive::ER_EXISTS => 'file already exists',
                ZipArchive::ER_INCONS => 'zip archive inconsistent',
                ZipArchive::ER_INVAL => 'invalid argument',
                ZipArchive::ER_MEMORY => 'malloc failure',
                ZipArchive::ER_NOENT => 'no such file',
                ZipArchive::ER_NOZIP => 'not a zip archive',
                ZipArchive::ER_OPEN => 'can\'t open file',
                ZipArchive::ER_READ => 'read error',
                ZipArchive::ER_SEEK => 'seek error',
            ];
            $message = isset($errorCodes[$result]) ? $errorCodes[$result] : 'error ' . $result;
            throw new Exception("Remote server returns ZIP file, that cannot be open ($message)");
        }

        if ($zip->numFiles !== 1) {
            throw new Exception("Remote server returns ZIP file, that contains multiple files.");
        }

        $filename = $zip->getNameIndex(0);
        if ($filename === false) {
            throw new Exception("Remote server returns ZIP file, but there is a problem with reading filename.");
        }

        $zip->close();

        $destinationFile = $this->tempFileName();
        $result = copy("zip://{$zipFile->pwd()}#$filename", $destinationFile);
        if ($result === false) {
            throw new Exception("Remote server returns ZIP file, that contains '$filename' file, but this file cannot be extracted.");
        }

        $unzipped = new File($destinationFile);
        $data = $unzipped->read();
        if ($data === false) {
            throw new Exception("Couldn't read extracted file content.");
        }
        $unzipped->delete();
        return $data;
    }

    /**
     * Insert feed to Redis cache.
     *
     * @param string $type Can be 'feed' or 'server'
     * @param int $sourceId Server or Feed ID
     * @param Iterator|array $values Values can be array of binary MD5 hash when $withEventUuid is false or
     * array of arrays [binary MD5, eventUuid] when $withEventUuid is true.
     * @param bool $withEventUuid
     * @throws Exception
     */
    public function insertToRedisCache($type, $sourceId, Iterator $values, $withEventUuid = false)
    {
        if (!in_array($type, ['server', 'feed'], true)) {
            throw new InvalidArgumentException();
        }
        $source = ($type === 'server' ? 'S' : 'F') . $sourceId;

        $redis = $this->setupRedisWithException();

        // Delete existing values from current feed
        $existingMembers = $redis->sMembers(self::REDIS_CACHE_PREFIX . $source);
        if (!empty($existingMembers)) {
            $pipe = $redis->pipeline();
            foreach ($existingMembers as $hash) {
                $pipe->hDel(self::REDIS_CACHE_PREFIX . $hash, $source);
            }
            $pipe->del(self::REDIS_CACHE_PREFIX . $source);
            $pipe->exec();
        }

        if ($withEventUuid) {
            // TODO: Maybe to split
            $toInsert = [];
            foreach ($values as $value) {
                list($hash, $eventUuid) = $value;
                if (isset($toInsert[$hash])) {
                    if (strpos($toInsert[$hash], $eventUuid) === false) { // do not insert duplicates
                        $toInsert[$hash] .= ',' . $eventUuid;
                    }
                } else {
                    $toInsert[$hash] = $eventUuid;
                }
            }
            $pipe = $redis->pipeline();
            foreach ($toInsert as $hash => $value) {
                $pipe->sAdd(self::REDIS_CACHE_PREFIX . $source, $hash);
                $pipe->hSet(self::REDIS_CACHE_PREFIX . $hash, $source, $value);
            }
            $pipe->exec();
        } else {
            $pipe = $redis->pipeline();
            foreach ($values as $hash) {
                $pipe->sAdd(self::REDIS_CACHE_PREFIX . $source, $hash);
                $pipe->hSet(self::REDIS_CACHE_PREFIX . $hash, $source, 0);
            }
            $pipe->exec();
        }
        $redis->set('misp:cache_timestamp:' . $source, time());
    }

    /**
     * Convert to new format, old format was used before 2.4.140
     * @throws Exception
     */
    private function convertToNewRedisCacheFormat()
    {
        $redis = $this->setupRedisWithException();

        $this->Server = ClassRegistry::init('Server');
        $serverIds = $this->Server->find('column', ['fields' => ['id']]);

        foreach ($serverIds as $serverId) {
            $sourceHashes = $redis->sMembers('misp:server_cache:' . $serverId);
            $hashToInsert = [];
            foreach ($sourceHashes as $sourceHash) {
                $hashToInsert[] = hex2bin($sourceHash);
                $uuids = $redis->sMembers('misp:server_cache:' . $sourceHash);
                if ($uuids) {
                    $uuidToInsert = [];
                    foreach ($uuids as $uuid) {
                        $parts = explode('/', $uuid);
                        if ($parts[0] == $serverId) {
                            $uuidToInsert[] = $parts[1];
                        }
                    }
                    $uuidToInsert = empty($uuidToInsert) ? 0 : implode(',', $uuidToInsert);
                } else {
                    $uuidToInsert = 0;
                }
                $redis->hSet(self::REDIS_CACHE_PREFIX . hex2bin($sourceHash), 'S' . $serverId, $uuidToInsert);
            }
            $redis->sAddArray(self::REDIS_CACHE_PREFIX . 'S' . $serverId, $hashToInsert);
            $redis->set('misp:cache_timestamp:S' . $serverId, $redis->get('misp:server_cache_timestamp:' . $serverId));
            $redis->del('misp:server_cache:' . $serverId);
        }

        $feedIds = $this->find('column', ['fields' => ['id']]);

        foreach ($feedIds as $feedId) {
            $sourceHashes = $redis->sMembers('misp:feed_cache:' . $feedId);
            $hashToInsert = [];
            foreach ($sourceHashes as $sourceHash) {
                $hashToInsert[] = hex2bin($sourceHash);
                $uuids = $redis->sMembers('misp:feed_cache:' . $sourceHash);
                if ($uuids) {
                    $uuidToInsert = [];
                    foreach ($uuids as $uuid) {
                        $parts = explode('/', $uuid);
                        if ($parts[0] == $feedId) {
                            $uuidToInsert[] = $parts[1];
                        }
                    }
                    $uuidToInsert = empty($uuidToInsert) ? 0 : implode(',', $uuidToInsert);
                } else {
                    $uuidToInsert = 0;
                }
                $redis->hSet(self::REDIS_CACHE_PREFIX . hex2bin($sourceHash), 'F' . $feedId, $uuidToInsert);
            }

            $redis->sAddArray(self::REDIS_CACHE_PREFIX . 'F' . $feedId, $hashToInsert);
            $redis->set('misp:cache_timestamp:F' . $feedId, $redis->get('misp:feed_cache_timestamp:' . $feedId));
            $redis->del('misp:feed_cache:' . $feedId);
        }

        // Delete old keys
        $redis->del('misp:feed_cache:combined', 'misp:server_cache:combined');
        $redis->del($redis->keys('misp:feed_cache_timestamp:*'));
        $redis->del($redis->keys('misp:server_cache_timestamp:*'));
    }
}
