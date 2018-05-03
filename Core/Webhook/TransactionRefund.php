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
use Wallee\Sdk\Model\LineItemType;
use Wallee\Sdk\Model\Refund;
use Wallee\Sdk\Model\RefundState;
use Wallee\Sdk\Service\RefundService;
use Wle\Wallee\Core\WalleeModule;
use Wle\Wallee\Extend\Application\Model\Order;

/**
 * Webhook processor to handle refund state transitions.
 */
class TransactionRefund extends AbstractOrderRelated
{

    /**
     * @param Request $request
     * @return \Wallee\Sdk\Model\Refund
     * @throws \Wallee\Sdk\ApiException
     */
    protected function loadEntity(Request $request)
    {
        $service = new RefundService(WalleeModule::instance()->getApiClient());
        return $service->read($request->getSpaceId(), $request->getEntityId());
    }

    protected function getOrderId($refund)
    {
        /* @var \Wallee\Sdk\Model\Refund $refund */
        $transaction = oxNew(\Wle\Wallee\Application\Model\Transaction::class);
        /* @var $dbTransaction \Wle\Wallee\Application\Model\Transaction */
        $transaction->loadByTransactionAndSpace($refund->getTransaction()->getId(), $refund->getLinkedSpaceId());
        return $transaction->getOrderId();
    }

    protected function getTransactionId($entity)
    {
        /* @var $entity \Wallee\Sdk\Model\Refund */
        return $entity->getTransaction()->getId();
    }

    protected function processOrderRelatedInner(\OxidEsales\Eshop\Application\Model\Order $order, $refund)
    {
        /* @var \Wallee\Sdk\Model\Refund $refund */
        $job = $this->apply($refund, $order);
        if($refund->getState() === RefundState::SUCCESSFUL && $job) {
            $this->restock($refund);
        }
        return $job != null;
    }

    private function apply(Refund $refund, \OxidEsales\Eshop\Application\Model\Order $order)
    {
    	$job = oxNew(\Wle\Wallee\Application\Model\RefundJob::class);
        /* @var $job \Wle\Wallee\Application\Model\RefundJob */
        if ($job->loadByJob($refund->getId(), $refund->getLinkedSpaceId()) || $job->loadByOrder($order->getId())) {
            if ($job->getState() !== $refund->getState()) {
                $job->apply($refund);
                return $job;
            }
        } else {
            WalleeModule::log(Logger::WARNING, "Unknown refund received, was not processed: $refund.");
        }
        return null;
    }

    protected function restock(Refund $refund)
    {
        foreach ($refund->getReductions() as $reduction) {
            foreach ($refund->getReducedLineItems() as $reduced) {
                if ($reduced->getUniqueId() === $reduction->getLineItemUniqueId() && $reduced->getType() !== LineItemType::PRODUCT) {
                    break 1;
                }
            }
            if ($reduction->getQuantityReduction()) {
            	$oxArticle = oxNew(\OxidEsales\Eshop\Application\Model\Article::class);
                /* @var $oxArticle \OxidEsales\Eshop\Application\Model\Article */
                if ($oxArticle->load($reduction->getLineItemUniqueId())) {
                    if (!$oxArticle->reduceStock(-$reduction->getQuantityReduction())) {
                        WalleeModule::log(Logger::ERROR, "Unable to increase stock for article {$reduction->getLineItemUniqueId()} by {$reduction->getQuantityReduction()}.");
                    }
                } else {
                    WalleeModule::log(Logger::ERROR, "Unable to load article {$reduction->getLineItemUniqueId()} to reduce stock by {$reduction->getQuantityReduction()}.");
                }
            }
        }
    }
}