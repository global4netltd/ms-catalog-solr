<?php

namespace G4NReact\MsCatalogSolr;

use Exception;
use G4NReact\MsCatalog\ConfigInterface;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalog\PullerInterface;
use G4NReact\MsCatalog\PusherInterface;
use G4NReact\MsCatalog\ResponseInterface;
use G4NReact\MsCatalogSolr\Client\Client as MsCatalogSolrClient;
use G4NReact\MsCatalogSolr\Config as SolrConfig;
use Iterator;
use Solarium\Client as SolariumClient;

/**
 * Class Pusher
 * @package G4NReact\MsCatalogSolr
 */
class Pusher implements PusherInterface
{
    /**
     * @var SolrConfig
     */
    private $config;

    /**
     * @var SolariumClient
     */
    private $client;

    /**
     * Pusher constructor
     *
     * @param ConfigInterface $config
     * @param SolariumClient $client
     */
    public function __construct(ConfigInterface $config, SolariumClient $client)
    {
        $this->config = $config;
        $this->client = $client;
        $endpoint = $client->getEndpoint();
        $timeout = $config->getPusherTimeout() ?: MsCatalogSolrClient::DEFAULT_PUSHER_TIMEOUT;
        $endpoint->setTimeout($timeout);
    }

    /**
     * @param Iterator|PullerInterface $documents
     *
     * @return ResponseInterface
     */
    public function push(PullerInterface $documents): ResponseInterface
    {
        $pageSize = $this->config->getPusherPageSize();
        $response = new Response();
        if ($documents) {
            try {
                $update = $this->client->createUpdate();

                $i = 0;
                $counter = 0;
                /** @var Document $document */
                foreach ($documents as $document) {
                    if (($counter === 0) || ($counter % 100 === 0)) {
                        $start = microtime(true);
                    }

                    echo $i . ' - ' . $counter . PHP_EOL;

                    if (!$document->getUniqueId()) {
                        continue;
                    }

                    $doc = $update->createDocument();

                    $doc->solr_id = (string)$document->getUniqueId();
                    $doc->id = (int)$document->getObjectId();
                    $doc->object_type = (string)$document->getObjectType();

                    /** @var Document\Field $field */
                    foreach ($document->getData() as $field) {
                        $solrFieldName = FieldHelper::getFieldName($field);
                        $solrFieldValue = $field->getValue();
                        if (isset(FieldHelper::$mapFieldType[$field->getType()]) && FieldHelper::$mapFieldType[$field->getType()] === FieldHelper::SOLR_FIELD_TYPE_DATETIME) {
                            $solrFieldValue = date(FieldHelper::SOLR_DATETIME_FORMAT, strtotime($field->getValue()));
                        }

                        $doc->{$solrFieldName} = $solrFieldValue;
                    }

                    if ($doc->id) {
                        $i++;
                        $update->addDocument($doc);
                    }

                    if ($i >= $pageSize) {
                        $update->addCommit();
                        $result = $this->client->update($update);
                        $i = 0;
                        $update = $this->client->createUpdate();
                    }

                    if (++$counter % 100 === 0) {
                        echo (round(microtime(true) - $start, 4)) . 's | ' . $counter . PHP_EOL;
                    }
                }
                if ($i > 0) {
                    $update->addCommit();
                }
                $result = $this->client->update($update);

                $response->setStatusCode($result->getResponse()->getStatusCode())
                    ->setStatusMessage($result->getResponse()->getStatusMessage());
            } catch (Exception $e) {
                echo $e->getMessage();
            }
        }

        return $response;
    }

    /**
     * @return array
     */
    public function getConfigArray()
    {
        return [
            'endpoint' => [
                'localhost' => $this->config->getConnectionConfigArray()
            ]
        ];
    }

    /**
     * @param string|null $deleteQuery Eg. '*:*' or 'object_type:"product"'
     */
    public function clearIndex($deleteQuery = null)
    {
        $update = $this->client->createUpdate();

        $query = $deleteQuery ?: '*:*';

        $update->addDeleteQuery($query);
        $update->addCommit();

        $result = $this->client->update($update);
    }
}
