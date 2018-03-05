<?php

/**
 * This file is part of the contentful.php package.
 *
 * @copyright 2015-2018 Contentful GmbH
 * @license   MIT
 */

namespace Contentful\Delivery\Mapper;

use Contentful\Core\Api\DateTimeImmutable;
use Contentful\Core\Resource\ResourceArray;
use Contentful\Core\Resource\ResourceInterface;
use Contentful\Core\ResourceBuilder\MapperInterface;
use Contentful\Delivery\Client;
use Contentful\Delivery\Resource\LocalizedResource;
use Contentful\Delivery\ResourceBuilder;
use Contentful\Delivery\SystemProperties;

/**
 * BaseMapper class.
 */
abstract class BaseMapper implements MapperInterface
{
    /**
     * @var \Closure[]
     */
    private static $hydrators = [];

    /**
     * @var ResourceBuilder
     */
    protected $builder;

    /**
     * @var Client
     */
    protected $client;

    /**
     * BaseMapper constructor.
     *
     * @param ResourceBuilder $builder
     * @param Client          $client
     */
    public function __construct(ResourceBuilder $builder, Client $client)
    {
        $this->builder = $builder;
        $this->client = $client;
    }

    /**
     * @param string|object $target either a FQCN, or an object whose class will be automatically inferred
     * @param array         $data
     *
     * @return ResourceInterface|ResourceArray
     */
    protected function hydrate($target, array $data)
    {
        $class = \is_object($target) ? \get_class($target) : $target;
        if (\is_string($target)) {
            $target = (new \ReflectionClass($class))
                ->newInstanceWithoutConstructor();
        }

        if ($this->injectClient()) {
            $data['client'] = $this->client;
        }

        $hydrator = $this->getHydrator($class);
        $hydrator($target, $data);

        if ($target instanceof LocalizedResource) {
            $locales = $this->client->getSpace()->getLocales();
            $target->setLocales($locales);

            /** @var SystemProperties $sys */
            $sys = $target->getSystemProperties();
            if ($locale = $sys->getLocale()) {
                $target->setLocale($locale);
            }
        }

        return $target;
    }

    /**
     * @param string $class
     *
     * @return \Closure
     */
    private function getHydrator($class)
    {
        if (isset(self::$hydrators[$class])) {
            return self::$hydrators[$class];
        }

        return self::$hydrators[$class] = \Closure::bind(function ($object, $properties) {
            foreach ($properties as $property => $value) {
                $object->$property = $value;
            }
        }, null, $class);
    }

    /**
     * @param array $sys
     *
     * @return SystemProperties
     */
    protected function buildSystemProperties(array $sys)
    {
        return new SystemProperties(
            isset($sys['id']) ? $sys['id'] : null,
            isset($sys['type']) ? $sys['type'] : null,
            isset($sys['space']) ? $this->client->getSpace() : null,
            isset($sys['contentType']) ? $this->client->getContentType($sys['contentType']['sys']['id']) : null,
            isset($sys['revision']) ? $sys['revision'] : null,
            isset($sys['createdAt']) ? new DateTimeImmutable($sys['createdAt']) : null,
            isset($sys['updatedAt']) ? new DateTimeImmutable($sys['updatedAt']) : null,
            isset($sys['deletedAt']) ? new DateTimeImmutable($sys['deletedAt']) : null,
            isset($sys['locale']) ? $sys['locale'] : null
        );
    }

    /**
     * @param mixed       $fieldData
     * @param string|null $locale
     *
     * @return array
     */
    protected function normalizeFieldData($fieldData, $locale)
    {
        if (!$locale) {
            return $fieldData;
        }

        return [$locale => $fieldData];
    }

    /**
     * Override this method for blocking the mapper from injecting the client property.
     *
     * @return bool
     */
    protected function injectClient()
    {
        return true;
    }
}
