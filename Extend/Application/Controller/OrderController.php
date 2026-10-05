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


namespace Wle\Wallee\Extend\Application\Controller;

use Monolog\Logger;
use Wallee\Sdk\Model\TransactionState;
use Wle\Wallee\Application\Model\Transaction;
use Wle\Wallee\Core\Service\TransactionService;
use Wle\Wallee\Core\WalleeModule;
use Wle\Wallee\Core\Exception\OptimisticLockingException;
use Wle\Wallee\Core\Settings\IntegrationMode;

/**
 * Class BasketItem.
 * Extends \OxidEsales\Eshop\Application\Controller\OrderController.
 *
 * @mixin \OxidEsales\Eshop\Application\Controller\OrderController
 */
class OrderController extends OrderController_parent
{
    public function init()
    {
        $this->_OrderController_init_parent();
        // Without a user there is nothing to push, this happens when the customer logs out while
        // standing on the checkout page.
        if ($this->getIsOrderStep() && $this->getUser()) {
            try {
                // The checkout marks the basket for recalculation (currency switch, payment costs) but
                // only recalculates it while rendering, which happens after init(). Without this the
                // prices of the previous request would be pushed, so a currency change would reach
                // Wallee with the old amounts.
                $this->getSession()->getBasket()->calculateBasket(false);
                // loadPendingFromSession() already pushes the current session state to Wallee,
                // calling updateFromSession() again here only doubles the API calls.
                Transaction::loadPendingFromSession($this->getSession());
            } catch (OptimisticLockingException $e) {
                WalleeModule::log(Logger::DEBUG,
                    "OrderController: Optimistic locking conflict while updating transaction: {$e->getMessage()}.");
            } catch (\Exception $e) {
                WalleeModule::log(Logger::ERROR, "Could not update transaction: {$e}.");
            }
        }
    }

    protected function _OrderController_init_parent()
    {
        parent::init();
    }

    public function wleConfirm()
    {
    	\OxidEsales\Eshop\Core\DatabaseProvider::getDb()->startTransaction();
    	$order = oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
        $response = array(
            'status' => false,
            'message' => 'unkown'
        );

        if ($this->isWalleeTransaction()) {
        	if($this->_validateTermsAndConditions()) {
	            try {
	                $transaction = Transaction::loadPendingFromSession($this->getSession());
	                /* @var $order \Wle\Wallee\Extend\Application\Model\Order */
	                /** @noinspection PhpParamsInspection */
	                $order->setConfirming(true);
	                $state = $order->finalizeOrder($this->getBasket(), $this->getUser());
	                $order->setConfirming(false);
	                if ($state === 'WALLEE_' . TransactionState::PENDING) {
	                    $transaction->setTempBasket($this->getBasket());
	                    $transaction->setOrderId($order->getId());
	                    // Store the link to the order before confirming. A confirm which fails otherwise
	                    // leaves a transaction which cannot be found by order id, so the failure message
	                    // has nowhere to go and the order is never cancelled.
	                    $transaction->save();
	                    \OxidEsales\Eshop\Core\DatabaseProvider::getDb()->commitTransaction();
	                    \OxidEsales\Eshop\Core\DatabaseProvider::getDb()->startTransaction();
	                    $transaction->updateFromSession(true);
	                    \OxidEsales\Eshop\Core\DatabaseProvider::getDb()->commitTransaction();
	                    $response['status'] = true;
	                } else if ($state == \OxidEsales\Eshop\Application\Model\Order::ORDER_STATE_ORDEREXISTS) {
	                    // ensure new order can be created
	                    $this->getSession()->deleteVariable('sess_challenge');
	                    throw new \Exception(WalleeModule::instance()->translate("Order already exists. Please check if you have already received a confirmation, then try again."));
	                } else {
	                    throw new \Exception(WalleeModule::instance()->translate("Unable to confirm order in state !state.", true, array('!state' => $state)));
	                }
	            } catch (\Exception $e) {
	                // Whatever is still open has to go, the failure handling below must be committed
	                // on its own, otherwise the cancelled order is lost when the request ends.
	                $this->wleRollback();
	                if (isset($transaction)) {
	                    $state = $transaction->getState();
	                } else if (!isset($state)) {
	                    $state = 'confirmation_error_unkown';
	                }
	                $order->WalleeFail($e->getMessage(), $state, true);
	                WalleeModule::log(Logger::ERROR, "Unable to confirm transaction: {$e->getMessage()}.");
	                $response['message'] = $e->getMessage();
	            }
        	}
        	else {
        		$response['message'] = WalleeModule::instance()->translate("You must agree to the terms and conditions.");
        	}
        } else {
            $response['message'] = WalleeModule::instance()->translate("Not a wallee order.");
        }

        WalleeModule::renderJson($response);
    }

    public function wleError()
    {
        try {
            $orderId = WalleeModule::instance()->getRequestParameter('oxid');
            if ($orderId) {
            	$order = oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
                /* @var $order Order */
            	$transaction = oxNew(\Wle\Wallee\Application\Model\Transaction::class);
                /* @var $transaction Transaction */
                if ($order->load($orderId) && $transaction->loadByOrder($orderId)) {
                    // The pull competes with the webhook of the same state change. Should it lose that
                    // race, the order still has to be failed, otherwise the reload blocker stays in the
                    // session and the next attempt lands on the thank you page without a payment.
                    try {
                        $transaction->pull();
                    } catch (\Exception $pullException) {
                        WalleeModule::log(Logger::ERROR,
                            "Unable to refresh transaction for order {$orderId}: {$pullException->getMessage()}.");
                    }
                    $message = $transaction->getSdkTransaction()
                        ? $transaction->getSdkTransaction()->getUserFailureMessage()
                        : WalleeModule::instance()->translate("The payment could not be completed. Please try again or choose another payment method.");
                    $order->WalleeFail($message, $transaction->getState());
                    WalleeModule::getUtilsView()->addErrorToDisplay($message);
                } else {
                	WalleeModule::getUtilsView()->addErrorToDisplay(WalleeModule::instance()->translate("An unknown error occurred, and the order could not be loaded."));
                }
            } else {
                $transaction = Transaction::loadFailedFromSession($this->getSession());
                if ($transaction) {
                    if ($transaction->getOrderId()) {
                    	$order = oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
                        /* @var $order \OxidEsales\Eshop\Application\Model\Order */
                        if ($order->load($transaction->getOrderId())) {
                            $order->WalleeFail($transaction->getSdkTransaction()->getUserFailureMessage(), $transaction->getState());
                        }
                    }
                    WalleeModule::getUtilsView()->addErrorToDisplay($transaction->getSdkTransaction()->getUserFailureMessage());
                } else {
                	WalleeModule::getUtilsView()->addErrorToDisplay(WalleeModule::instance()->translate("An unknown error occurred, and the order could not be loaded."));
                }
            }
        } catch (\Exception $e) {
        	WalleeModule::getUtilsView()->addErrorToDisplay($e->getMessage());
        }
    }
    
    /**
     * Rolls back the database transaction which is still open after a failed confirmation, so the
     * failure handling which follows is written with its own commit.
     */
    private function wleRollback()
    {
        try {
            \OxidEsales\Eshop\Core\DatabaseProvider::getDb()->rollbackTransaction();
        } catch (\Exception $e) {
            WalleeModule::log(Logger::DEBUG,
                "No open database transaction to roll back: {$e->getMessage()}.");
        }
    }

    public function isWalleeTransaction()
    {
        return WalleeModule::isWalleePayment($this->getBasket()->getPaymentId());
    }

    public function getWalleePaymentId()
    {
        return WalleeModule::extractWalleeId($this->getBasket()->getPaymentId());
    }

    public function getWalleeJavascriptUrl()
    {
        try {
            $transaction = Transaction::loadPendingFromSession($this->getSession());
            return TransactionService::instance()->getJavascriptUrl($transaction->getTransactionId(), $transaction->getSpaceId());
        } catch (\Exception $e) {
            WalleeModule::log(Logger::ERROR, $e->getMessage(), array($this, $e));
        }
        return '';
    }

    public function isWalleeIFrameMode()
    {
        return WalleeModule::settings()->getIntegrationMode() === IntegrationMode::IFRAME;
    }
}