<?php

/**
 * ModelCache observer
 * This log should not be active on any production environment but can be enabled temporary to find out what
 * model are loaded multiple times and where this is happening.
 * The results will be written to var/log/aoemodelcache.log
 * These items are candidates to be stored in the model cache
 *
 * @author Fabrizio Branca
 * @since  2013-05-08
 */
class Aoe_ModelCache_Model_Observer
{

    protected $data = array();
    protected $loadedModels = 0;

    const XML_PATH_MODEL_CACHE_ENABLED   = 'dev/aoe_modelcache/log_active';
    const XML_PATH_MODEL_CACHE_LOG_FILE   = 'dev/aoe_modelcache/log_file';

    /**
     * Log data
     *
     * @param Varien_Event_Observer $event
     */
    public function log(Varien_Event_Observer $event)
    {
        $logActive = Mage::getStoreConfig(self::XML_PATH_MODEL_CACHE_ENABLED);
        if (!$logActive) {
            return;
        }

        $object = $event->getObject();
        /* @var $object Mage_Core_Model_Abstract */
        $class = get_class($object);
        $id = $event->getValue();

        if (!isset($this->data[$class])) {
            $this->data[$class] = array();
        }
        if (!isset($this->data[$class][$id])) {
            $this->data[$class][$id] = array();
        }
        $trace = debug_backtrace();
        $this->data[$class][$id][] = $trace[5]['file'] . ':' . $trace[5]['line'];

        $this->loadedModels++;
    }

    /**
     * Process data and write to log
     */
    public function __destruct()
    {
        $logActive = Mage::getStoreConfig(self::XML_PATH_MODEL_CACHE_ENABLED);
        if (!$logActive) {
            return;
        }
        $logFile = Mage::getStoreConfig(self::XML_PATH_MODEL_CACHE_LOG_FILE);

        // remove every id that was called only once
        foreach ($this->data as $className => $classes) {
            foreach ($classes as $id => $lineAndFiles) {
                if (count($lineAndFiles) <= 1) {
                    unset($this->data[$className][$id]);
                    if (count($this->data[$className]) == 0) {
                        unset($this->data[$className]);
                    }
                }
            }
        }

        Mage::log(var_export($this->data, true), null, $logFile);
        Mage::log('Total number of loaded models: ' . $this->loadedModels, null, $logFile);
    }

}
