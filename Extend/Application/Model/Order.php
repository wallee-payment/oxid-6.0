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
namespace Wle\Wallee\Extend\Application\Model;

use Wallee\Sdk\Model\TransactionState;
use Wle\Wallee\Application\Model\Transaction;
use Wle\Wallee\Core\WalleeModule;
use Monolog\Logger;

/**
 * Class Order.
 * Extends \OxidEsales\Eshop\Application\Model\Order.
 *
 * @mixin \OxidEsales\Eshop\Application\Model\Order
 */
class Order extends Order_parent {
	private $confirming = false;
	private static $wleStateOrder = [
		TransactionState::CREATE => 0,
		TransactionState::PENDING => 1,
		TransactionState::CONFIRMED => 2,
		TransactionState::PROCESSING => 3,
		TransactionState::AUTHORIZED => 4,
		TransactionState::COMPLETED => 5,
		TransactionState::FULFILL => 6,
		TransactionState::DECLINE => 6,
		TransactionState::VOIDED => 6,
		TransactionState::FAILED => 6
	];

	public function setConfirming($confirming = true){
		$this->confirming = $confirming;
	}

	public function getWalleeBasket(){
		$conf = \OxidEsales\Eshop\Core\Registry::getConfig();
		$origSetting = $conf->getConfigParam('blUseStock');
		// Disable stock rules in case stock is now below 1
		$conf->setConfigParam('blUseStock', false);

		// copied from recalculateOrder, minus call of finalizeOrder, and adding new articles.
		$oBasket = $this->_getOrderBasket();

		/* @noinspection PhpParamsInspection */
		$orderArticles = $this->getOrderArticles(true);
		// Here we populate the basket with Articles, not OrderArticles as the email templates expect the Article
		// Article = a product as it is in the catalog. This can change (price, image etc)
		// OrderArticle = a snapshot of the product at the time of ordering. This is fixed
		foreach ($orderArticles as $oOrderArticle) {
			$oArticle = new \OxidEsales\Eshop\Application\Model\Article();
			if ($oOrderArticle->oxorderarticles__oxbundleid->value) {
				// This is a bundle sub-item, skip it
				continue;
			}
			if ($oArticle->load($oOrderArticle->oxorderarticles__oxartid->value)) {
				$amount = (float) $oOrderArticle->oxorderarticles__oxamount->value;
				if ($amount > 0) {
					$oBasketItem = $oBasket->addToBasket($oArticle->getId(), $amount, null, $oOrderArticle->getPersParams(), false, $oOrderArticle->isBundle());
				}
			}
		}
		$conf->setConfigParam('blUseStock', $origSetting);
		$oBasket->calculateBasket(true);
		return $oBasket;
	}

	/**
	 * Sets the oxtransstatus and oxfolder according to the given TransactionState
	 *
	 * @param string $state TransactionState enum
	 */
	public function setWalleeState($state){
		if (!$this->isWleOrder()) {
			WalleeModule::log(Logger::WARNING,
					"Attempted to call " . __METHOD__ . " on non-Wallee order {$this->getId()}, skipping.");
			return;
		}
		$oldState = substr($this->getFieldData('OXTRANSSTATUS'), strlen('WALLEE_'));
		if (self::$wleStateOrder[$oldState] > self::$wleStateOrder[$state]) {
			throw new \Exception("Cannot move order from state $oldState to $state.");
		}
		$this->_setFieldData('OXTRANSSTATUS', 'WALLEE_' . $state);
		$this->_setFieldData('OXFOLDER', WalleeModule::getMappedFolder($state));
	}

	/**
	 * Sends the confirmation email.
	 *
	 * @throws \Exception
	 */
	public function WalleeAuthorize(){
		if (!$this->isWleOrder()) {
			WalleeModule::log(Logger::WARNING,
					"Attempted to call " . __METHOD__ . " on non-Wallee order {$this->getId()}, skipping.");
			return;
		}
		$basket = $this->getWalleeBasket();
		$basket->onUpdate();
		$basket->calculateBasket();

		if ($this->checkWalleeEmailSent()) {
			$res = $this->_sendOrderByEmail($this->getOrderUser(), $basket, $this->getPaymentType());
			$this->markWalleeEmailAsSent();
			WalleeModule::log(Logger::DEBUG, "Confirmation email was sent {$this->getId()}.");
		}
	}

	/**
	 * Check if the email was sent when the transaction state is updated to authorized to avoid duplicate emails.
	 * Return true if the email hasn't been emailed yet
	 *
	 * @return bool
	 */
	public function checkWalleeEmailSent(){
		$currentState = substr($this->getFieldData('OXTRANSSTATUS'), strlen('WALLEE_'));
		$transaction = $this->getWalleeTransaction();

		if (!$transaction->getEmailSent()) {
			return true;
		}

		$sent = $transaction->getEmailSent() ? 'yes' : 'no';
		WalleeModule::log(Logger::DEBUG,
			"Attempted to send an email on order {$this->getId()} with current state {$currentState}, was sent? {$sent}.");

		return false;
	}

	/**
	 * Marks as sent when the transaction state is updated to authorized only the first time.
	 */
	public function markWalleeEmailAsSent(){
		$transaction = $this->getWalleeTransaction();
		$transaction->markEmailAsSent();
		$transaction->save();
	}

	public function setWalleePaid(){
		if (!$this->isWleOrder()) {
			WalleeModule::log(Logger::WARNING,
					"Order {$this->getId()} already has oxpaid set, skipping re-assignment.");
			return;
		}
		if ($this->getFieldData('oxpaid') !== '0000-00-00 00:00:00') {
			WalleeModule::log(Logger::INFO,
					"Attempted to call " . __METHOD__ . " on non-Wallee order {$this->getId()}, skipping.");
			return;
		}
		$this->_setFieldData('oxpaid', date('Y-m-d H:i:s'), \OxidEsales\Eshop\Core\Field::T_RAW);
	}

	/**
	 * Sets the order state to the given state, and saves the message on the associated transaction.
	 *
	 * @param $message
	 * @param $state
	 * @param bool $cancel If the order should be cancelled
	 * @param bool $rethrow if exceptions should be thrown.
	 */
	public function WalleeFail($message, $state, $cancel = false, $rethrow = false){
		if (!$this->isWleOrder()) {
			WalleeModule::log(Logger::WARNING,
					"Attempted to call " . __METHOD__ . " on non-Wallee order {$this->getId()}, skipping.");
			return;
		}
		$transaction = oxNew(\Wle\Wallee\Application\Model\Transaction::class);

		/* @var $transaction Transaction */
		if ($transaction->loadByOrder($this->getId())) {
			try {
				$transaction->setFailureReason($message);
				$transaction->save();
				$this->resetCoupon();
			}
			catch (\Exception $e) {
				// treat optimisticlockingexception equally.
				WalleeModule::log(Logger::ERROR, "Unable to save transaction with ID {$transaction->getId()}: {$e->getMessage()}.");
				if ($rethrow) {
					throw $e;
				}
			}
		}
		else {
			WalleeModule::log(Logger::ERROR, "Unable to save failure message '{$message}' on transaction for order {$this->getId()}.");
		}
		$this->getSession()->deleteVariable("sess_challenge"); // allow new orders
		try {
			$this->setWalleeState($state);
			if ($cancel) {
				$this->cancelOrder();
			}
		}
		catch (\Exception $e) {
			WalleeModule::log(Logger::ERROR, "Unable to cancel order: {$e->getMessage()}.");
			if ($rethrow) {
				throw $e;
			}
		}
	}

	public function resetCoupon() {
		// have to reset the coupon to empty as they are already generated in the database
		$userId = $this->getOrderUser()->getId();
		$orderId = $this->getId();
		$query = "UPDATE oxvouchers 
			SET
				oxorderid = '',
				oxuserid = '',
				oxdiscount = NULL,
				oxdateused = NULL,
				oxreserved = 0
			WHERE 
				oxorderid = '{$orderId}' AND
				oxuserid = '{$userId}'";
		WalleeModule::log(Logger::DEBUG, "query {$query}.");
		\OxidEsales\Eshop\Core\DatabaseProvider::getDb()->execute($query);
	}

	public function getWalleeDownloads(){
		$downloads = array();
		if ($this->isWleOrder()) {
			$transaction = $this->getWalleeTransaction();
			if ($transaction && in_array($transaction->getState(),
					array(
						TransactionState::COMPLETED,
						TransactionState::FULFILL,
						TransactionState::DECLINE
					))) {
				if (WalleeModule::settings()->isDownloadInvoiceEnabled()) {
					$downloads[] = array(
						'link' => WalleeModule::getControllerUrl('wle_wallee_Pdf', 'invoice',
								$this->getId()),
						'text' => WalleeModule::instance()->translate('Download Invoice')
					);
				}
				if (WalleeModule::settings()->isDownloadPackingEnabled()) {
					$downloads[] = array(
						'link' => WalleeModule::getControllerUrl('wle_wallee_Pdf', 'packingSlip',
								$this->getId()),
						'text' => WalleeModule::instance()->translate('Download Packing Slip')
					);
				}
			}
		}
		return $downloads;
	}

	public function finalizeOrder(\OxidEsales\Eshop\Application\Model\Basket $oBasket, $oUser, $blRecalculatingOrder = false){
		if (!$this->isWleOrder($oBasket)) {
			return $this->_Order_finalizeOrder_parent($oBasket, $oUser, $blRecalculatingOrder);
		}

		if ($this->getFieldData('oxtransstatus') === 'WALLEE_' . TransactionState::PENDING) {
			if ($this->confirming) {
				return self::ORDER_STATE_OK;
			}
			else {
				$transaction = $this->getWalleeTransaction();
				if ($transaction) {
					WalleeModule::instance()->getUtils()->redirect($transaction->getPaymentPageUrl());
					exit();
				}
				return self::ORDER_STATE_PAYMENTERROR;
			}
		}

		$result = $this->_Order_finalizeOrder_parent($oBasket, $oUser, $blRecalculatingOrder);

		// The reload blocker of the shop would answer a second attempt with the thank you page, although
		// the payment of the blocking order never happened. Drop the blocker so the next attempt builds a
		// new order, and send the customer back to the payment step.
		if ($result === self::ORDER_STATE_ORDEREXISTS && !$blRecalculatingOrder && !$this->confirming) {
			WalleeModule::log(Logger::WARNING,
					"Order already exists while the payment was not completed, returning to the payment step.");
			$this->getSession()->deleteVariable('sess_challenge');
			return self::ORDER_STATE_PAYMENTERROR;
		}

		if ($result == self::ORDER_STATE_OK && !$blRecalculatingOrder) {
			$result = 'WALLEE_' . TransactionState::PENDING;
			$this->_setOrderStatus($result);

			// update transaction, confirm transaction, and redirect away
			if (!$this->confirming) {
				WalleeModule::log(Logger::ERROR, "Attempted to finalize order without confirmation. Redirecting to payment page.",
						array(
							$this
						));
				$transaction = Transaction::loadPendingFromSession($this->getSession());
				$transaction->setTempBasket($this->getBasket());
				$transaction->setOrderId($this->getId());
				$transaction->updateFromSession(true);
				WalleeModule::instance()->getUtils()->redirect($transaction->getPaymentPageUrl());
				exit();
			}
		}

		return $result;
	}

	protected function _Order_finalizeOrder_parent(\OxidEsales\Eshop\Application\Model\Basket $oBasket, $oUser, $blRecalculatingOrder = false){
		return parent::finalizeOrder($oBasket, $oUser, $blRecalculatingOrder);
	}

	protected function _sendOrderByEmail($oUser = null, $oBasket = null, $oPayment = null){

		$isWleOrder = $this->isWleOrder();
		$isSettingEnabled = WalleeModule::settings()->isEmailConfirmationActive();

		if ($isWleOrder && $isSettingEnabled) {
			// The confirmation email must only go out once the payment was authorized. The webhook sets the
			// order state right before it calls WalleeAuthorize(), so reading the order state
			// answers this without loading the transaction from Wallee.
			if (!WalleeModule::isAuthorizedState($this->getFieldData('oxtransstatus'))) {
				return self::ORDER_STATE_OK;
			}
		}

		return $this->_Order_sendOrderByEmail_parent($oUser, $oBasket, $oPayment);
	}

	protected function _sendOrderByEmailForced($oUser = null, $oBasket = null, $oPayment = null){
		$basketItem = oxNew(\OxidEsales\Eshop\Application\Model\BasketItem::class);
		/* @var $basketItem \Wle\Wallee\Extend\Application\Model\BasketItem */
		$basketItem->wleDisableCheckProduct(true);

		$result = $this->_sendOrderByEmail($oUser, $oBasket, $oPayment);

		$basketItem->wleDisableCheckProduct(false);

		return $result;
	}

	protected function _Order_sendOrderByEmail_parent($oUser = null, $oBasket = null, $oPayment = null){
		$conf = \OxidEsales\Eshop\Core\Registry::getConfig();
		$oldCurrency = $conf->getShopCurrency();
		$orderCurrencyObj = $this->getOrderCurrency();
		
		// Temporarily set the active shop currency to the order's currency.
		// Oxid's email templates use oxEmail::getCurrency(), which returns the active shop currency 
		// instead of the basket/order currency. When an order is confirmed via a webhook without a user session, 
		// the shop defaults to the default currenty, which can be different from the order currency.
		if (isset($orderCurrencyObj->id)) {
			$conf->setActShopCurrency($orderCurrencyObj->id);
		}

		$result = parent::_sendOrderByEmail($oUser, $oBasket, $oPayment);

		// Restore the original active currency
		if (isset($orderCurrencyObj->id)) {
			$conf->setActShopCurrency($oldCurrency);
		}

		return $result;
	}

	public function isWleOrder($basket = null){
		$paymentType = $this->getFieldData('oxpaymenttype');
		if (empty($paymentType)) {
			if ($this->getBasket()) {
				$paymentType = $this->getBasket()->getPaymentId();
			}
			else if ($basket instanceof \OxidEsales\Eshop\Application\Model\Basket) {
				$paymentType = $basket->getPaymentId();
			}
		}
		return substr($paymentType, 0, strlen(WalleeModule::PAYMENT_PREFIX)) === WalleeModule::PAYMENT_PREFIX;
	}

	public function getWalleeTransaction(){
		if ($this->getId()) {
			$transaction = oxNew(\Wle\Wallee\Application\Model\Transaction::class);
			/* @var $transaction Transaction */
			if ($transaction->loadByOrder($this->getId())) {
				return $transaction;
			}
		}
		return null;
	}

	public function getCurrentOrderState() {
		$currentState = substr($this->getFieldData('OXTRANSSTATUS'), strlen('WALLEE_'));
		return $currentState;
	}
}
