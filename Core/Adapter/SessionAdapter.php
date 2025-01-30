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
namespace Wle\Wallee\Core\Adapter;

use Wallee\Sdk\Model\AbstractTransactionPending;
use Wallee\Sdk\Model\TransactionCreate;
use Wallee\Sdk\Model\TransactionPending;
use Wle\Wallee\Core\WalleeModule;
use Wle\Wallee\Application\Model\Transaction;
use Wallee\Sdk\Model\LineItemCreate;
use Wallee\Sdk\Model\LineItemType;

/**
 * Class SessionAdapter
 * Converts Oxid Session Data into data which can be fed into the Wallee SDK.
 *
 * @codeCoverageIgnore
 */
class SessionAdapter implements ITransactionServiceAdapter {
	private $session = null;
	private $basketAdapter = null;
	private $addressAdapter = null;

	/**
	 * SessionAdapter constructor.
	 *
	 * Checks if user is logged in and basket is present as well, and throws an exception if either is not present.
	 *
	 * @param \OxidEsales\Eshop\Core\Session $session
	 * @throws \Exception
	 */
	public function __construct(\OxidEsales\Eshop\Core\Session $session){
		if (!$session->getUser() || !$session->getBasket()) {
			throw new \Exception("User must be logged in and basket must be present.");
		}
		$this->session = $session;
		$this->basketAdapter = new BasketAdapter($session->getBasket());
		$this->addressAdapter = new AddressAdapter($session->getUser()->getSelectedAddress(), $session->getUser());
	}

	public function getCreateData(){
		$transactionCreate = new TransactionCreate();
		if (isset($_COOKIE['Wallee_device_id'])) {
			$transactionCreate->setDeviceSessionIdentifier($_COOKIE['Wallee_device_id']);
		}
		$transactionCreate->setAutoConfirmationEnabled(false);
		$transactionCreate->setChargeRetryEnabled(false);
		$this->applyAbstractTransactionData($transactionCreate);
		return $transactionCreate;
	}

	public function getUpdateData(Transaction $transaction){
		$transactionPending = new TransactionPending();
		$transactionPending->setId($transaction->getTransactionId());
		$transactionPending->setVersion($transaction->getVersion());
		$this->applyAbstractTransactionData($transactionPending);
		
		if ($transaction->getOrderId()) {
			$transactionPending->setFailedUrl(
					WalleeModule::getControllerUrl('order', 'wleError', $transaction->getOrderId(), true));
			$order = oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
			/* @var $order \OxidEsales\Eshop\Application\Model\Order */
			if ($order->load($transaction->getOrderId())) {
				$transactionPending->setMerchantReference($order->oxorder__oxordernr->value);
				$transactionPending->setAllowedPaymentMethodConfigurations(
						array(
							WalleeModule::extractWalleeId($order->oxorder__oxpaymenttype->value) 
						));
				$totalDifference = $this->getTotalsDifference($transactionPending->getLineItems(), $order);
				if($totalDifference) {
					if(WalleeModule::settings()->enforceLineItemConsistency()) {
						throw new \Exception(WalleeModule::instance()->translate('Totals mismatch, please contact merchant or use another payment method.'));
					}
					else {
						$lineItems = $transactionPending->getLineItems();
						$lineItems[] = $this->createRoundingAdjustment($totalDifference);
						$transactionPending->setLineItems($lineItems);
					}
				}
			}
		}
		
		return $transactionPending;
	}

	private function applyAbstractTransactionData(AbstractTransactionPending $transaction){
		$transaction->setCustomerId($this->session->getUser()->getId());
		$transaction->setCustomerEmailAddress($this->session->getUser()->getFieldData('oxusername'));
		/**
		 * @noinspection PhpUndefinedFieldInspection
		 */
		$transaction->setCurrency($this->session->getBasket()->getBasketCurrency()->name);
		$transaction->setLineItems($this->basketAdapter->getLineItemData());
		$transaction->setBillingAddress($this->addressAdapter->getBillingAddressData());
		$transaction->setShippingAddress($this->addressAdapter->getShippingAddressData());
		$transaction->setLanguage(\OxidEsales\Eshop\Core\Registry::getLang()->getLanguageAbbr());
		$transaction->setSuccessUrl(WalleeModule::getControllerUrl('thankyou', null, null, true));
		$transaction->setFailedUrl(WalleeModule::getControllerUrl('order', 'wleError', null, true));
	}
	
	private function getTotalsDifference(array $lineItems, \OxidEsales\Eshop\Application\Model\Order $order) {
		$total = 0;
		foreach($lineItems as $lineItem) {

			if ($lineItem->getType() === \Wallee\Sdk\Model\LineItemType::DISCOUNT) {
				// convert negative values to positive in order to be able to subtract it.
				$total -= abs( $lineItem->getAmountIncludingTax() );
			} else {
				$total += $lineItem->getAmountIncludingTax();
			}
		}
		return \OxidEsales\Eshop\Core\Registry::getUtils()->fRound($total - $order->getTotalOrderSum(), $order->getOrderCurrency());
	}
	
	private function createRoundingAdjustment($amount)
	{
		$lineItem = new LineItemCreate();
		/** @noinspection PhpParamsInspection */
		$lineItem->setType(LineItemType::FEE);
		$lineItem->setAmountIncludingTax($amount);
		$lineItem->setName(WalleeModule::instance()->translate('Rounding Adjustment'));
		$lineItem->setQuantity(1);
		$lineItem->setUniqueId('rounding_adjustment');
		$lineItem->setSku('rounding_adjustment');
		return $lineItem;
	}
	
}