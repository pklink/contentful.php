<?php

/**
 * This file is part of the contentful.php package.
 *
 * @copyright 2015-2018 Contentful GmbH
 * @license   MIT
 */

namespace Contentful\Delivery;

use Cache\Adapter\Void\VoidCachePool;
use Contentful\Core\Api\BaseClient;
use Contentful\Core\Api\Link;
use Contentful\Core\Resource\ResourceArray;
use Contentful\Core\Resource\ResourceInterface;
use Contentful\Delivery\Resource\Asset;
use Contentful\Delivery\Resource\ContentType;
use Contentful\Delivery\Resource\Entry;
use Contentful\Delivery\Resource\Space;
use Contentful\Delivery\Synchronization\Manager;
use Psr\Cache\CacheItemPoolInterface;

/**
 * A Client is used to communicate the Contentful Delivery API.
 *
 * A Client is only responsible for one Space. When access to multiple spaces is required, create multiple Clients.
 *
 * This class can be configured to use the Preview API instead of the Delivery API. This grants access to not yet published content.
 */
class Client extends BaseClient
{
    /**
     * @var string
     */
    const VERSION = '3.0.0-dev';

    /**
     * @var string
     */
    const API_DELIVERY = 'DELIVERY';

    /**
     * @var string
     */
    const API_PREVIEW = 'PREVIEW';

    /**
     * @var string
     */
    const URI_DELIVERY = 'https://cdn.contentful.com';

    /**
     * @var string
     */
    const URI_PREVIEW = 'http://preview.contentful.com';

    /**
     * @var ResourceBuilder
     */
    private $builder;

    /**
     * @var InstanceRepository
     */
    private $instanceRepository;

    /**
     * @var bool
     */
    private $preview;

    /**
     * @var string|null
     */
    private $defaultLocale;

    /**
     * @var string
     */
    private $spaceId;

    /**
     * Client constructor.
     *
     * @param string      $token         Delivery API Access Token for the space used with this Client
     * @param string      $spaceId       ID of the space used with this Client
     * @param bool        $preview       true to use the Preview API
     * @param string|null $defaultLocale The default is to fetch the Space's default locale. Set to a locale
     *                                   string, e.g. "en-US" to fetch content in that locale. Set it to "*"
     *                                   to fetch content in all locales.
     * @param array       $options       An array of optional configuration options. The following keys are possible:
     *                                   * guzzle     Override the guzzle instance used by the Contentful client
     *                                   * logger     Inject a Contentful logger
     *                                   * baseUri    Override the uri that is used to connect to the Contentful API (e.g. 'https://cdn.contentful.com/'). The trailing slash is required.
     *                                   * cache      Null or a PSR-6 cache item pool. The client only writes to the cache if autoWarmup is true, otherwise, you are responsible for warming it up using \Contentful\Delivery\Cache\CacheWarmer.
     *                                   * autoWarmup Warm up the cache automatically
     */
    public function __construct($token, $spaceId, $preview = false, $defaultLocale = null, array $options = [])
    {
        $options = \array_replace([
            'guzzle' => null,
            'logger' => null,
            'baseUri' => null,
            'cacheDir' => null,
            'cache' => null,
            'autoWarmup' => false,
        ], $options);

        $baseUri = $preview ? self::URI_PREVIEW : self::URI_DELIVERY;
        if (null !== $options['baseUri']) {
            $baseUri = $options['baseUri'];
            if ('/' === \mb_substr($baseUri, -1)) {
                $baseUri = \mb_substr($baseUri, 0, -1);
            }
        }

        parent::__construct($token, $baseUri, $options['logger'], $options['guzzle']);

        $this->preview = $preview;
        $this->defaultLocale = $defaultLocale;
        $this->spaceId = $spaceId;

        $cacheItemPool = $options['cache'] ?: new VoidCachePool();
        if (!$cacheItemPool instanceof CacheItemPoolInterface) {
            throw new \InvalidArgumentException('The cache parameter must be a PSR-6 cache item pool or null.');
        }

        $this->instanceRepository = new InstanceRepository($this, $cacheItemPool, (bool) $options['autoWarmup']);
        $this->builder = new ResourceBuilder($this, $this->instanceRepository);
    }

    /**
     * {@inheritdoc}
     */
    public function getApi()
    {
        return $this->isPreview() ? self::API_PREVIEW : self::API_DELIVERY;
    }

    /**
     * The name of the library to be used in the User-Agent header.
     *
     * @return string
     */
    protected function getSdkName()
    {
        return 'contentful.php';
    }

    /**
     * The version of the library to be used in the User-Agent header.
     *
     * @return string
     */
    protected function getSdkVersion()
    {
        return self::VERSION;
    }

    /**
     * Returns the Content-Type (MIME-Type) to be used when communication with the API.
     *
     * @return string
     */
    protected function getApiContentType()
    {
        return 'application/vnd.contentful.delivery.v1+json';
    }

    /**
     * @param string      $assetId
     * @param string|null $locale
     *
     * @return Asset
     */
    public function getAsset($assetId, $locale = null)
    {
        $locale = $locale ?: $this->defaultLocale;

        $instanceId = $assetId.'-'.($locale ?: '*');
        if ($this->instanceRepository->has('Asset', $instanceId)) {
            return $this->instanceRepository->get('Asset', $instanceId);
        }

        return $this->requestAndBuild('/spaces/'.$this->spaceId.'/assets/'.$assetId, [
            'query' => ['locale' => $locale],
        ]);
    }

    /**
     * @param Query|null $query
     *
     * @return ResourceArray
     */
    public function getAssets(Query $query = null)
    {
        $query = null !== $query ? $query : new Query();
        $queryData = $query->getQueryData();
        if (!isset($queryData['locale'])) {
            $queryData['locale'] = $this->defaultLocale;
        }

        return $this->requestAndBuild('/spaces/'.$this->spaceId.'/assets', [
            'query' => $queryData,
        ]);
    }

    /**
     * @param string $contentTypeId
     *
     * @return ContentType
     */
    public function getContentType($contentTypeId)
    {
        if ($this->instanceRepository->has('ContentType', $contentTypeId)) {
            return $this->instanceRepository->get('ContentType', $contentTypeId);
        }

        return $this->requestAndBuild('/spaces/'.$this->spaceId.'/content_types/'.$contentTypeId);
    }

    /**
     * @param Query|null $query
     *
     * @return ResourceArray
     */
    public function getContentTypes(Query $query = null)
    {
        $query = null !== $query ? $query : new Query();

        return $this->requestAndBuild('/spaces/'.$this->spaceId.'/content_types', [
            'query' => $query->getQueryData(),
        ]);
    }

    /**
     * @param string      $entryId
     * @param string|null $locale
     *
     * @return Entry
     */
    public function getEntry($entryId, $locale = null)
    {
        $locale = $locale ?: $this->defaultLocale;

        $instanceId = $entryId.'-'.($locale ?: '*');
        if ($this->instanceRepository->has('Entry', $instanceId)) {
            return $this->instanceRepository->get('Entry', $instanceId);
        }

        return $this->requestAndBuild('/spaces/'.$this->spaceId.'/entries/'.$entryId, [
            'query' => ['locale' => $locale],
        ]);
    }

    /**
     * @param Query|null $query
     *
     * @return ResourceArray
     */
    public function getEntries(Query $query = null)
    {
        $queryData = $query ? $query->getQueryData() : [];
        if (!isset($queryData['locale'])) {
            $queryData['locale'] = $this->defaultLocale;
        }

        return $this->requestAndBuild('/spaces/'.$this->spaceId.'/entries', [
            'query' => $queryData,
        ]);
    }

    /**
     * @return Space
     */
    public function getSpace()
    {
        if ($this->instanceRepository->has('Space', $this->spaceId)) {
            return $this->instanceRepository->get('Space', $this->spaceId);
        }

        return $this->requestAndBuild('/spaces/'.$this->spaceId);
    }

    /**
     * Resolve a link to it's resource.
     *
     * @param Link        $link
     * @param string|null $locale
     *
     * @throws \InvalidArgumentException when encountering an unexpected link type
     *
     * @return Asset|Entry
     */
    public function resolveLink(Link $link, $locale = null)
    {
        $id = $link->getId();
        $type = $link->getLinkType();

        switch ($link->getLinkType()) {
            case 'Entry':
                return $this->getEntry($id, $locale);
            case 'Asset':
                return $this->getAsset($id, $locale);
            default:
                throw new \InvalidArgumentException('Tyring to resolve link for unknown type "'.$type.'".');
        }
    }

    /**
     * Revive JSON previously cached.
     *
     * @param string $json
     *
     * @throws \InvalidArgumentException When attempting to revive JSON belonging to a different space
     *
     * @return ResourceInterface|ResourceArray
     */
    public function parseJson($json)
    {
        $data = \GuzzleHttp\json_decode($json, true);

        $spaceId = $this->extractSpaceId($data);
        if ($spaceId !== $this->spaceId) {
            throw new \InvalidArgumentException(\sprintf(
                'Trying to parse and build a JSON structure with a client configured for handling space "%s", but space "%s" was detected.',
                $this->spaceId,
                $spaceId
            ));
        }

        return $this->builder->build($data);
    }

    /**
     * Checks a data structure and extracts the space ID, if present.
     *
     * @param array $data
     *
     * @return string|null
     */
    private function extractSpaceId(array $data)
    {
        // Space resource
        if (isset($data['sys']['type']) && $data['sys']['type'] === 'Space') {
            return $data['sys']['id'];
        }

        // Resource linked to a space
        if (isset($data['sys']['space'])) {
            return $data['sys']['space']['sys']['id'];
        }

        // Array resource with at least an element
        if (isset($data['items'][0]['sys']['space'])) {
            return $data['items'][0]['sys']['space']['sys']['id'];
        }

        // Empty array resource
        if (isset($data['items']) && !$data['items']) {
            return $this->spaceId;
        }

        return '[blank]';
    }

    /**
     * Internal method for \Contentful\Delivery\Synchronization\Manager.
     *
     * @param array $queryData
     *
     * @return mixed
     */
    public function syncRequest(array $queryData)
    {
        return $this->request('GET', '/spaces/'.$this->spaceId.'/sync', [
            'query' => $queryData,
        ]);
    }

    /**
     * Returns true when using the Preview API.
     *
     * @return bool
     *
     * @see https://www.contentful.com/developers/docs/references/content-preview-api/#/reference Preview API Reference
     */
    public function isPreview()
    {
        return $this->preview;
    }

    /**
     * Get an instance of the synchronization manager. Note that with the Preview API only an inital sync
     * is giving valid results.
     *
     * @return Manager
     *
     * @see https://www.contentful.com/developers/docs/concepts/sync/ Sync API
     */
    public function getSynchronizationManager()
    {
        return new Manager($this, $this->builder, $this->preview);
    }

    /**
     * @param string $path
     * @param array  $options
     *
     * @return ResourceInterface|ResourceArray
     */
    private function requestAndBuild($path, array $options = [])
    {
        $response = $this->request('GET', $path, $options);
        $resource = $this->builder->build($response);

        if ($resource instanceof ResourceInterface) {
            $this->instanceRepository->set($resource);
        }

        return $resource;
    }
}
