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

use Wallee\Sdk\Model\TransactionCompletionState;
use Wallee\Sdk\Service\TransactionCompletionService;
use Wle\Wallee\Application\Model\CompletionJob;
use Wle\Wallee\Core\WalleeModule;
use Wle\Wallee\Extend\Application\Model\Order;

/**
 * Webhook processor to handle transaction completion state transitions.
 */
class TransactionCompletion extends AbstractOrderRelated
{

    /**
     * @param Request $request
     * @return \Wallee\Sdk\Model\TransactionCompletion
     * @throws \Wallee\Sdk\ApiException
     */
    protected function loadEntity(Request $request)
    {
        $service = new TransactionCompletionService(WalleeModule::instance()->getApiClient());
        return $service->read($request->getSpaceId(), $request->getEntityId());
    }

    /**
     * @param object $completion
     * @return string
     * @throws \Exception
     */
    protected function getOrderId($completion)
    {
        /* @var \Wallee\Sdk\Model\TransactionCompletion $completion */
        $transaction = oxNew(\Wle\Wallee\Application\Model\Transaction::class);
        /* @var $transaction \Wle\Wallee\Application\Model\Transaction */
        if ($transaction->loadByTransactionAndSpace($completion->getLinkedTransaction(), $completion->getLinkedSpaceId())) {
            return $transaction->getOrderId();
        }
        throw new \Exception("Unable to load transaction {$completion->getLinkedTransaction()} in space {$completion->getLinkedSpaceId()} from database.");
    }

    protected function getTransactionId($entity)
    {
        /* @var $entity \Wallee\Sdk\Model\TransactionCompletion */
        return $entity->getLinkedTransaction();
    }

    /**
     * @param Order $order
     * @param object $completion
     * @throws \Exception
     * @throws \Wallee\Sdk\ApiException
     */
    protected function processOrderRelatedInner(\OxidEsales\Eshop\Application\Model\Order $order, $completion)
    {
        /* @var \Wallee\Sdk\Model\TransactionCompletion $completion */
        switch ($completion->getState()) {
            case TransactionCompletionState::FAILED:
                $this->failed($completion, $order);
                return true;
            case TransactionCompletionState::SUCCESSFUL:
                $this->success($completion, $order);
                return true;
            default:
                // Ignore PENDING & CREATE
                // Nothing to do.
                return false;
        }
    }

    /**
     * @param \Wallee\Sdk\Model\TransactionCompletion $completion
     * @param Order $order
     * @throws \Exception
     * @throws \Wallee\Sdk\ApiException
     */
    protected function success(\Wallee\Sdk\Model\TransactionCompletion $completion, \OxidEsales\Eshop\Application\Model\Order $order)
    {
    	$job = oxNew(\Wle\Wallee\Application\Model\CompletionJob::class);
        /* @var $job CompletionJob */
        if ($job->loadByOrder($order->getId()) || $job->loadByJob($completion->getId(), $completion->getLinkedSpaceId())) {
            $job->apply($completion);
        }
        $order->getWalleeTransaction()->pull();
        $order->setWalleeState($order->getWalleeTransaction()->getState());
    }

    /**
     * Fails the given order.
     *
     * @param \Wallee\Sdk\Model\TransactionCompletion $completion
     * @param Order $order
     * @throws \Exception
     * @throws \Wallee\Sdk\ApiException
     */
    protected function failed(\Wallee\Sdk\Model\TransactionCompletion $completion, \OxidEsales\Eshop\Application\Model\Order $order)
    {
        /** @noinspection PhpParamsInspection */
        $message = WalleeModule::instance()->WalleeTranslate($completion->getFailureReason()->getName());
        /** @noinspection PhpParamsInspection */
        $message .= WalleeModule::instance()->WalleeTranslate($completion->getFailureReason()->getDescription());
        $order->getWalleeTransaction()->pull();
        $order->WalleeFail($message, $order->getWalleeTransaction()->getState(), true, true);

        $job = oxNew(\Wle\Wallee\Application\Model\CompletionJob::class);
        /* @var $job \Wle\Wallee\Application\Model\CompletionJob */
        if ($job->loadByJob($completion->getId(), $completion->getLinkedSpaceId()) || $job->loadByOrder($order->getId())) {
            $job->apply($completion);
        }
    }
}