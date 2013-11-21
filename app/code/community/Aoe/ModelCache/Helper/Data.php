<?php
/**
 * Class Aoe_ModelCache_Helper_Data
 *
 * @author Fabrizio Branca
 * @author Lee Saferite
 * @since  2013-05-08
 */
class Aoe_ModelCache_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * @var array
     */
    protected $_cache = array();

    /**
     * Get model
     *
     * @param string $model
     * @param int    $id
     * @param string $field
     * @param bool   $clean
     * @param bool   $logErrors
     *
     * @return Mage_Core_Model_Abstract|bool
     */
    public function get($model, $id, $field = null, $clean = false, $logErrors = true)
    {
        if ($clean) {
            $this->removeFromCache($model, $id);
        }

        $object = $this->load($model, $id, $field);
        if (!$object->getId() && $logErrors) {
            Mage::log(sprintf('Model "%s" with id "%s" not found', htmlspecialchars($model), htmlspecialchars($id)));
        }

        return $object;
    }

    /**
     * Check if this object exists
     *
     * @param string $model
     * @param int    $id
     *
     * @return bool
     */
    public function exists($model, $id, $field = null)
    {
        return !!$this->search($model, $id, $field);
    }

    /**
     * Remove from cache
     *
     * @param string $model
     * @param int    $id
     */
    public function removeFromCache($model, $id)
    {
        return $this->remove($model, $id);
    }

    /**
     * Fetch a stored object by id or field value
     *
     * NB: Field value lookup only works for fields that an object has been stored under
     *
     * @param string $model
     * @param string $id
     * @param string $field
     *
     * @return bool|Mage_Core_Model_Abstract
     */
    public function fetch($model, $id, $field = null)
    {
        $modelMetadata = $this->_resolveModel($model);

        // If we have a non-id field then perform a lookup of the field value to convert to the real id
        if (!empty($field) && $field !== $modelMetadata['id_field']) {
            if (isset($this->_cache[$modelMetadata['class']]['aliases'][$field][$id])) {
                $id = $this->_cache[$modelMetadata['class']]['aliases'][$field][$id];
            } else {
                $id = null;
            }
        }

        if ($id && isset($this->_cache[$modelMetadata['class']]['objects'][$id])) {
            return $this->_cache[$modelMetadata['class']]['objects'][$id];
        }

        return false;
    }

    /**
     * Search for a stored object first by fetching then by a scan
     *
     * @param string $model
     * @param string $id
     * @param string $field
     * @param bool   $store If we resort to a scan, store an explicit alias reference to the found object
     *
     * @return bool|Mage_Core_Model_Abstract
     */
    public function search($model, $id, $field = null, $store = true)
    {
        // First try using standard fetch as a shortcut
        $object = $this->fetch($model, $id, $field);
        if ($object) {
            return $object;
        }

        $modelMetadata = $this->_resolveModel($model);

        // If the field is empty or set but the same as the id field then fail fast (fetch would have found the object first)
        if (empty($field) || $field === $modelMetadata['id_field']) {
            return false;
        }

        // perform a scan of all the stored objects for this model looking for the first matching
        foreach ($this->_cache[$modelMetadata['class']]['objects'] as $objectId => $object) {
            /** @var $object Mage_Core_Model_Abstract */
            if ($object->getDataUsingMethod($field) === $id) {
                if ((bool)$store) {
                    $this->_cache[$modelMetadata['class']]['aliases'][$field][$id] = $objectId;
                }
                return $object;
            }
        }

        return false;
    }

    /**
     * Load an object
     *
     * Use a fetch or search to find already loaded instances of the object
     * Resort to a DB load if the object is not already loaded
     * If we are using a non-id field then first perform a lookup of the id.
     * This lets us use the load events for the model.
     *
     * @param string $model
     * @param string $id
     * @param string $field
     * @param bool   $useSearch
     *
     * @return Mage_Core_Model_Abstract
     */
    public function load($model, $id, $field = null, $useSearch = true)
    {
        if ((bool)$useSearch) {
            $object = $this->search($model, $id, $field);
        } else {
            $object = $this->fetch($model, $id, $field);
        }

        if (!$object) {
            $modelMetadata = $this->_resolveModel($model);
            $object = Mage::getModel($model);
            if (!$object) {
                // This should NEVER happen
                Mage::throwException('Could not find class for model');
            }

            $originalId = $id;
            if (!empty($field) && $field !== $modelMetadata['id_field']) {
                // If we have a non-id field then perform a lookup of the field value to convert to the real id
                $data = $object->getCollection()
                    ->addFieldToFilter($field, $id)
                    ->setPage(1, 1)
                    ->getData();

                $id = null;
                if (count($data)) {
                    $data = reset($data);
                    if (isset($data[$modelMetadata['id_field']])) {
                        $id = $data[$modelMetadata['id_field']];
                    }
                }
            }

            $object->load($id);
            if ($object->getId()) {
                $aliasFields = array();
                if (!empty($field) && $field !== $modelMetadata['id_field']) {
                    $aliasFields[] = $field;
                }
                $this->store($model, $object, $aliasFields, !$useSearch);
            }
        }

        return $object;
    }

    /**
     * Store an object instance by ID and zero or more aliases
     *
     * If the object is already stored and we don't force, this will cause an exception
     *
     * @param string                   $model
     * @param Mage_Core_Model_Abstract $object
     * @param array                    $aliasFields
     * @param bool                     $force
     *
     * @return $this
     */
    public function store($model, Mage_Core_Model_Abstract $object, array $aliasFields = array(), $force = false)
    {
        $existingObject = $this->fetch($model, $object->getId());
        if ($existingObject && $existingObject !== $object) {
            if ($force) {
                $this->remove($model, $existingObject->getId());
            } else {
                Mage::throwException('Cannot replace existing object');
            }
        }

        $modelMetadata = $this->_resolveModel($model);

        // Store object reference
        $this->_cache[$modelMetadata['class']]['objects'][$object->getId()] = $object;

        // Clear existing alias entries for this object (only for aliases be saved right now)
        if (count($aliasFields)) {
            $this->removeAliases($model, $object->getId(), $aliasFields);
        }

        // Store a reference to this object for each of the requested alias fields
        foreach ($aliasFields as $aliasField) {
            $this->_cache[$modelMetadata['class']]['aliases'][$aliasField][$object->getDataUsingMethod($aliasField)] = $object->getId();
        }

        return $this;
    }

    /**
     * Remove a stored instance of an object and all of its aliases
     *
     * @param string $model
     * @param string $id
     * @param string $field
     *
     * @return $this
     */
    public function remove($model, $id, $field = null)
    {
        $object = $this->search($model, $id, $field);
        if ($object) {
            $modelMetadata = $this->_resolveModel($model);
            unset($this->_cache[$modelMetadata['class']]['objects'][$object->getId()]);
            $this->removeAliases($model, $object->getId());
        }
        return $this;
    }

    /**
     * Remove the aliases for an object instance by ID
     *
     * @param string $model
     * @param string $id
     * @param array  $aliasFields
     *
     * @return $this
     */
    public function removeAliases($model, $id, array $aliasFields = array())
    {
        $modelMetadata = $this->_resolveModel($model);
        if (empty($aliasFields)) {
            $aliasFields = array_keys($this->_cache[$modelMetadata['class']]['aliases']);
        }
        foreach ($aliasFields as $aliasField) {
            if (isset($this->_cache[$modelMetadata['class']]) && is_array($this->_cache[$modelMetadata['class']])
                && isset($this->_cache[$modelMetadata['class']]['aliases'][$aliasField]) && is_array($this->_cache[$modelMetadata['class']]['aliases'][$aliasField])) {
                foreach ($this->_cache[$modelMetadata['class']]['aliases'][$aliasField] as $value => $objectId) {
                    if ($objectId == $id) {
                        unset($this->_cache[$modelMetadata['class']]['aliases'][$aliasField][$value]);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Reset the model cache
     *
     * @return $this
     */
    public function clear()
    {
        $this->_cache = array();

        return $this;
    }

    /**
     * Perform an initial lookup of the model into a class, initialize storage for the class, and find the ID field for the model
     *
     * @param string $model
     *
     * @return array
     */
    protected function _resolveModel($model)
    {
        /** @var $object Mage_Core_Model_Abstract */
        $object = Mage::getSingleton($model);
        if (!$object) {
            Mage::throwException(sprintf("Could not resolve '$1%s' to a class", $model));
        } elseif (!$object instanceof Mage_Core_Model_Abstract) {
            Mage::throwException('Invalid model type.  Models MUST extend Mage_Core_Model_Abstract');
        }
        $class = get_class($object);
        if (!isset($this->_cache[$class]['metadata'])) {
            $this->_cache[$class] = array(
                'metadata' => array(
                    'class'    => $class,
                    'id_field' => $object->getIdFieldName()
                ),
                'objects'  => array(),
                'aliases'  => array(),
            );
        }

        return $this->_cache[$class]['metadata'];
    }
}
