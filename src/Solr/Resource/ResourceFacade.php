<?php
/**
 * integer_net Magento Module
 *
 * @category   IntegerNet
 * @package    IntegerNet_Solr
 * @copyright  Copyright (c) 2014 integer_net GmbH (http://www.integer-net.de/)
 * @author     Andreas von Studnitz <avs@integer-net.de>
 */
namespace IntegerNet\Solr\Resource;

use Apache_Solr_Compatibility_Solr4CompatibilityLayer;
use Apache_Solr_Document;
use Apache_Solr_HttpTransport_Abstract;
use Apache_Solr_HttpTransport_Curl;
use Apache_Solr_HttpTransport_FileGetContents;
use Apache_Solr_Response;
use IntegerNet\Solr\Exception;
use IntegerNet\Solr\Implementor\Config;
use IntegerNet\Solr\Indexer\IndexDocument;

/**
 * Solr resource, facade for Apache_Solr library
 */
class ResourceFacade
{
    /**
     * Configuration reader, by store id
     *
     * @var  Config[]
     */
    protected $_config;

    /**
     * @var ResourceBuilder
     */
    private $resourceBuilder;

    /**
     * Solr service, by store id and swap (yes/no)
     *
     * @var ServiceBase[][]
     */
    protected $_solr;

    /** @var bool */
    protected $_useSwapIndex = false;

    /**
     * @param Config[] $storeConfig
     */
    public function __construct(array $storeConfig = array())
    {
        $this->_config = $storeConfig;
        $this->resourceBuilder = ResourceBuilder::defaultResource();
    }

    /**
     * @param $storeId
     * @return Config
     * @throws Exception
     */
    public function getStoreConfig($storeId)
    {
        $storeId = (int)$storeId;
        if (!isset($this->_config[$storeId])) {
            throw new Exception("Store with ID {$storeId} not found.");
        }
        return $this->_config[$storeId];
    }

    public function setUseSwapIndex($useSwapIndex = true)
    {
        $this->_useSwapIndex = $useSwapIndex;
        return $this;
    }

    /**
     * @deprecated should be completely abstracted
     * @param int $storeId
     * @return ServiceBase
     */
    public function getSolrService($storeId)
    {
        if (!isset($this->_solr[$storeId][(int)$this->_useSwapIndex])) {

            if (intval(ini_get('default_socket_timeout')) < 300) {
                ini_set('default_socket_timeout', 300);
            }

            $serverConfig = $this->getStoreConfig($storeId)->getServerConfig();
            $this->_solr[$storeId][(int)$this->_useSwapIndex] = $this->resourceBuilder->withConfig($serverConfig, $this->_useSwapIndex)->build();
        }
        return $this->_solr[$storeId][(int)$this->_useSwapIndex];
    }

    /**
     * @param $storeId
     * @internal used for testing
     */
    public function setSolrService($storeId, $service)
    {
        $this->_solr[$storeId][0] = $service;
        $this->_solr[$storeId][1] = $service;
    }

    /**
     * @param int $storeId
     * @param string $query The raw query string
     * @param int $offset The starting offset for result documents
     * @param int $limit The maximum number of result documents to return
     * @param array $params key / value pairs for other query parameters (see Solr documentation), use arrays for parameter keys used more than once (e.g. facet.field)
     * @return SolrResponse
     */
    public function search($storeId, $query, $offset = 0, $limit = 10, $params = array())
    {
        $response = $this->getSolrService($storeId)->search($query, $offset, $limit, $params, \Apache_Solr_Service::METHOD_POST);
        return new ResponseDecorator($response);
    }

    /**
     * @param null|int[] $restrictToStoreIds
     * @throws Exception
     */
    public function checkSwapCoresConfiguration($restrictToStoreIds = null)
    {
        (new SwapConfigCheck())->checkSwapCoresConfiguration($this->_config, $restrictToStoreIds);
    }

    /**
     * @param null|int[] $restrictToStoreIds
     */
    public function swapCores($restrictToStoreIds = null)
    {
        $storeIdsToSwap = array();

        foreach ($this->_config as $storeId => $storeConfig) {
            /** @var Config $storeConfig */
            $solrServerInfo = $storeConfig->getServerConfig()->getServerInfo();

            if (!is_null($restrictToStoreIds) && !in_array($storeId, $restrictToStoreIds)) {
                continue;
            }

            if (!$storeConfig->getGeneralConfig()->isActive()) {
                continue;
            }

            if ($storeConfig->getIndexingConfig()->isSwapCores()) {
                $storeIdsToSwap[$solrServerInfo] = $storeId;
            }
        }

        foreach ($storeIdsToSwap as $storeIdToSwap) {
            $this->getSolrService($storeIdToSwap)
                ->setBasePath($this->getStoreConfig($storeIdToSwap)->getServerConfig()->getPath())
                ->swapCores(
                    $this->getStoreConfig($storeIdToSwap)->getServerConfig()->getCore(),
                    $this->getStoreConfig($storeIdToSwap)->getServerConfig()->getSwapCore()
                );
        }
    }

    /**
     * @param int $storeId
     * @return null|Apache_Solr_Response
     */
    public function getInfo($storeId)
    {
        if (!$this->getStoreConfig($storeId)->getServerConfig()->getCore()) {
            return null;
        }
        try {
            return $this->getSolrService($storeId)
                ->setBasePath($this->getStoreConfig($storeId)->getServerConfig()->getPath())
                ->info();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param int $storeId
     * @param array $data
     * @return Apache_Solr_Response
     */
    public function addDocument($storeId, $data)
    {
        $document = new Apache_Solr_Document();
        foreach ($data as $key => $value) {
            if ($key == '_boost') {
                $document->setBoost($value);
                continue;
            }
            if (substr($key, -6) == '_boost') {
                $document->setFieldBoost(substr($key, 0, -6), $value);
                continue;
            }
            $document->addField($key, $value);
        }
        $response = $this->getSolrService($storeId)->addDocument($document);
        $this->getSolrService($storeId)->commit();
        return $response;
    }

    /**
     * @param int $storeId
     * @param array[]|IndexDocument[] $combinedData
     * @return Apache_Solr_Response
     */
    public function addDocuments($storeId, $combinedData)
    {
        $documents = array();
        foreach ($combinedData as $data) {

            $document = new Apache_Solr_Document();
            foreach ($data as $key => $value) {
                if ($key == '_boost') {
                    $document->setBoost($value);
                    continue;
                }
                if (substr($key, -6) == '_boost') {
                    $document->setFieldBoost(substr($key, 0, -6), $value);
                    continue;
                }
                if (is_array($value)) {
                    foreach ($value as $subValue) {
                        $document->addField($key, $subValue);
                    }
                } else {
                    $document->addField($key, $value);
                }
            }
            $documents[] = $document;
        }

        $response = $this->getSolrService($storeId)->addDocuments($documents);
        $this->getSolrService($storeId)->commit();
        return $response;
    }

    /**
     * @param int $storeId
     * @param string $contentType
     * @return Apache_Solr_Response
     */
    public function deleteAllDocuments($storeId, $contentType = '')
    {
        $query = 'store_id:' . $storeId;
        if ($contentType) {
            $query .= ' AND content_type:' . $contentType;
        }
        $response = $this->getSolrService($storeId)->deleteByQuery($query);
        $this->getSolrService($storeId)->commit();
        return $response;
    }

    /**
     * @param int $storeId
     * @param string[] $ids
     * @return Apache_Solr_Response
     */
    public function deleteByMultipleIds($storeId, $ids)
    {
        $response = $this->getSolrService($storeId)->deleteByMultipleIds($ids);
        $this->getSolrService($storeId)->commit();
        return $response;
    }

}