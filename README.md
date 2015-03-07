# Aoe_ModelCache

## Instructions

### Load models from cache

Replace...

	$product = Mage::getModel('catalog/product')->load($id);

... with

	$product = Mage::helper('aoe_modelcache')->get('catalog/product', $id);

If the model was create before the previous instance will be reused. If it wasn't created before it will be created now.

### Forcing clean model

	$product = Mage::helper('aoe_modelcache')->get('catalog/product', $id, true);
The difference to Mage::getModel('catalog/product')->load($id) is, that the created model will be cached and can be reused
for future calls without the clean parameter

### Check if model exists in cache

	$modelExists = Mage::helper('aoe_modelcache')->exists('catalog/product', $id);

### Remove model from cache

	Mage::helper('aoe_modelcache')->removeFromCache('catalog/product', $id);

## Finding candidates for optimization

Enable the log from System > Configuration > ADVANCED > Developer > AOE Model Cache Log Settings and check var/log/aoemodelcache.log after hitting a page.
You'll find all occurrences of models that have been loaded more than once incl. file and line where the call happened.
These items are candidates for replacement by model cache calls.

## Caution!

Please test the shop properly after having any changes in place.
Side-effects where fresh objects are expected and a "used" instances is returned are possible.
