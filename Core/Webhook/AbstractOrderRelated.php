<?php
/**
 * Wallee OXID
 *
 * This OXID module enables to process payments with Wallee (https://www.wallee.com/).
 *
 * @package Whitelabelshortcut\Wallee
 * @author customweb GmbH (http://www.customweb.com/)
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache Software License (ASL 2.0)
 */
namespace Wle\Wallee\Core\Webhook;

use Monolog\Logger;
use Wle\Wallee\Core\WalleeModule;
use Wle\Wallee\Core\Exception\OptimisticLockingException;

/**
 * Abstract webhook processor for order related entities.
 */
abstract class AbstractOrderRelated extends AbstractWebhook
{
	const NO_ORDER = 1;
	const OPTIMISTIC_RETRIES = 6;
	const SECONDS_TO_WAIT = 1;

    /**
     * Processes the received order related webhook request.
     * @param Request $request
     * @throws \Exception
     */
    public function process(Request $request)
    {
        if ($request->getSpaceId() != WalleeModule::settings()->getSpaceId()) {
            throw new \Exception("Received webhook with space id {$request->getSpaceId()} in store which is configured for space id " . WalleeModule::settings()->getSpaceId());
        }
        for($i = 0; $i <= self::OPTIMISTIC_RETRIES; $i++) {
	        try {
	            $entity = $this->loadEntity($request);
	            $orderId = $this->getOrderId($entity);
	            $order = $this->loadOrder($orderId);
	            \OxidEsales\Eshop\Core\Registry::getLang()->setBaseLanguage($order->getOrderLanguage());
	            
	            if(!$order->getWalleeTransaction() || !$order->getWalleeTransaction()->getId()){
	            	throw new \Exception("Transaction could not be loaded on order.");
	            }
	            
	            if($this->processOrderRelatedInner($order, $entity)) {
		            if(!$order->getWalleeTransaction()->save() || !$order->save()) {
		            	throw new \Exception('Unable to save order');
		            }
	            }
	            
	        }catch(OptimisticLockingException $e){
	        	WalleeModule::log(Logger::WARNING, "Optimistic locking query: " . $e->getQueryString());
	        	WalleeModule::rollback();
	        	if($i === self::OPTIMISTIC_RETRIES) {
	        		throw $e;
	        	}
	        	sleep(self::SECONDS_TO_WAIT);
	        }
	        catch (\Exception $e) {
	            WalleeModule::log(Logger::ERROR, $e->getMessage() . ' - ' . $e->getTraceAsString());
	            WalleeModule::rollback();
	            if($e->getCode() !== self::NO_ORDER) {
	            	throw $e;
	            }
        	}
        }
    }


    /**
     * @param $orderId
     * @return \OxidEsales\Eshop\Application\Model\Order
     * @throws \Exception
     */
    protected function loadOrder($orderId)
    {
    	WalleeModule::getUtilsObject()->resetInstanceCache(\OxidEsales\Eshop\Application\Model\Order::class);
        $order = oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
        /* @var $order \Wle\Wallee\Extend\Application\Model\Order */
        if ($order->load($orderId) && $order->isWleOrder()) {
            return $order;
        }
        throw new \Exception("Could not load order by id $orderId.", self::NO_ORDER);
    }

    /**
     * Loads and returns the entity for the webhook request.
     *
     * @param Request $request
     * @return object
     */
    abstract protected function loadEntity(Request $request);

    /**
     * Returns the order id linked to the entity.
     *
     * @param object $entity
     * @return string
     */
    abstract protected function getOrderId($entity);

    /**
     * Returns the transaction id linked to the entity
     *
     *
     * @param object $entity
     * @return int
     */
    abstract protected function getTransactionId($entity);

    /**
     * Actually processes the order related webhook request.
     *
     * This must be implemented
     *
     * @param \OxidEsales\Eshop\Application\Model\Order $order
     * @param object $entity
     * @return bool If a change was applied to the database, e.g. if the order should be saved.
     */
    abstract protected function processOrderRelatedInner(\OxidEsales\Eshop\Application\Model\Order $order, $entity);
}