<?php

namespace G4NReact\MsCatalogSolr\Client;

use Exception;
use G4NReact\MsCatalog\Client\ClientInterface;
use G4NReact\MsCatalog\Config;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalog\Document\Field;
use G4NReact\MsCatalog\PullerInterface;
use G4NReact\MsCatalog\PusherInterface;
use G4NReact\MsCatalog\QueryInterface as MsCatalogQueryInterface;
use G4NReact\MsCatalog\ResponseInterface;
use G4NReact\MsCatalogSolr\Config as SolrConfig;
use G4NReact\MsCatalogSolr\FieldHelper;
use G4NReact\MsCatalogSolr\Puller;
use G4NReact\MsCatalogSolr\Pusher;
use G4NReact\MsCatalogSolr\Query as MsCatalogSolrQuery;
use G4NReact\MsCatalogSolr\Response;
use Solarium\Client as SolariumClient;
use Solarium\Core\Query\QueryInterface;
use Solarium\Core\Query\QueryInterface as SolariumQueryInterface;
use Solarium\Exception\UnexpectedValueException;
use Solarium\QueryType\Select\Query\Query;

/**
 * Class Client
 * @package G4NReact\MsCatalogSolr\Client
 */
class Client implements ClientInterface
{
    /**
     * @var SolariumClient
     */
    protected $client;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var SolrConfig
     */
    protected $solrConfig;

    /**
     * Client constructor.
     *
     * @param $config
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->solrConfig = new SolrConfig($config);
        $this->client = new SolariumClient($this->solrConfig->getConfigArray());
    }

    /**
     * @param array $fields
     *
     * @return \G4NReact\MsCatalog\ResponseInterface
     */
    public function add(array $fields): ResponseInterface
    {
        $update = $this->client->createUpdate();
        $document = $update->createDocument($fields);
        $update
            ->addDocument($document)
            ->addCommit();

        $result = $this->client->update($update);
        $response = new Response();

        return $response
            ->setStatusMessage($result->getResponse()->getStatusMessage())
            ->setStatusCode($result->getResponse()->getStatusCode());
    }

    /**
     * @param int|string $id
     *
     * @return \G4NReact\MsCatalog\ResponseInterface
     */
    public function deleteById($id): ResponseInterface
    {
        $update = $this->client->createUpdate();
        $update
            ->addDeleteById($id)
            ->addCommit();

        $result = $this->client->update($update);
        $response = new Response();

        return $response
            ->setStatusCode($result->getResponse()->getStatusCode())
            ->setStatusMessage($result->getResponse()->getStatusMessage());
    }

    /**
     * @param array $ids
     *
     * @return \G4NReact\MsCatalog\ResponseInterface
     */
    public function deleteByIds(array $ids): ResponseInterface
    {
        $update = $this->client->createUpdate();

        $update
            ->addDeleteByIds($ids)
            ->addCommit();

        $result = $this->client->update($update);
        $response = new Response();

        return $response
            ->setStatusCode($result->getResponse()->getStatusCode())
            ->setStatusMessage($result->getResponse()->getStatusMessage());
    }

    /**
     * @param $field
     * @param $value
     *
     * @return ResponseInterface
     */
    public function deleteByField($field, $value): ResponseInterface
    {
        $update = $this->client->createUpdate();
        $update
            ->addDeleteQuery($field . ':' . $value)
            ->addCommit();

        $result = $this->client->update($update);
        $response = new Response();

        return $response
            ->setStatusCode($result->getResponse()->getStatusCode())
            ->setStatusMessage($result->getResponse()->getStatusMessage());
    }

    /**
     * @param array $options
     *
     * @return ResponseInterface
     */
    public function get($options): ResponseInterface
    {
        $query = $this->client->createSelect($options);

        $result = $this->client->execute($query);

        $response = new Response();

        return $response
            ->setDocumentsCollection($result->getData())
            ->setNumFound(count($result->getData()))
            ->setStatusCode($result->getResponse()->getStatusCode())
            ->setStatusMessage($result->getResponse()->getStatusMessage());
    }

    /**
     * @param $query
     *
     * @return ResponseInterface
     */
    public function query($query): ResponseInterface
    {
        if (!($query instanceof QueryInterface)) {
            throw new UnexpectedValueException(
                'query must implement Query Interface'
            );
        }
        try {
            $result = $this->client->execute($query);
        } catch (Exception $e) {
            /** @todo logger for error */
            var_dump($e->getMessage());
            die;
        }

        $response = new Response();

        $response
            ->setQuery($query)
            ->setDocumentsCollection($result->getData()['response']['docs'])
            ->setNumFound($result->getData()['response']['numFound'] ?? 0)
            ->setFacets($result->getData()['facet_counts']['facet_queries'] ?? null)
            ->setStats($result->getData()['stats']['stats_fields'] ?? null)
            ->setCurrentPage($result->getQuery()->getOption('start'))
            ->setStatusMessage($result->getResponse()->getStatusMessage())
            ->setStatusCode($result->getResponse()->getStatusCode());

        /** TODO move it to another function */
        $newDocumentColl = [];

        /**
         * @todo too much response data vs requested (fields like created_at, display mode etc)
         */
        foreach ($response->getDocumentsCollection() as $docKey => $document) {
            $newDocument = new Document();
            foreach ($document as $fieldName => $fieldValue) {
                $field = FieldHelper::createFieldByResponseField($fieldName, $fieldValue);
                $newDocument->setField($field);
            }
            $document = $newDocument;
            array_push($newDocumentColl, $document);
        }

        $response->setDocumentsCollection($newDocumentColl);

        return $response;
    }

    /**
     * @param string $type
     *
     * @return SolariumQueryInterface
     */
    public function prepareQuery(string $type)
    {
        return $this->client->createQuery($type);
    }

    /**
     * @return Query
     */
    public function getSelect()
    {
        return $this->client->createSelect();
    }

    /**
     * @return PullerInterface
     */
    public function getPuller(): PullerInterface
    {
        return new Puller($this->config, $this->client);
    }

    /**
     * @return PusherInterface
     */
    public function getPusher(): PusherInterface
    {
        return new Pusher($this->config, $this->client);
    }

    /**
     * @return MsCatalogQueryInterface
     * @throws Exception
     */
    public function getQuery(): MsCatalogQueryInterface
    {
        return new MsCatalogSolrQuery($this->config);
    }

    /**
     * @param string $name
     * @param null $value
     * @param string $type
     * @param bool $indexable
     * @param bool $multiValued
     * @param array $args
     *
     * @return Field|mixed
     */
    public function getField(string $name, $value = null, string $type = '', bool $indexable = false, bool $multiValued = false, array $args = [])
    {
        return new Field($name, $value, $type, $indexable, $multiValued, $args);
    }
}
