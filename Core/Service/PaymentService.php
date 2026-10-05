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
namespace Wle\Wallee\Core\Service;

use Wallee\Sdk\Model\EntityQuery;
use Wallee\Sdk\Model\PaymentMethodConfiguration;
use Wallee\Sdk\Service\PaymentMethodConfigurationService;
use Wle\Wallee\Core\WalleeModule;
use \Wallee\Sdk\Service\TransactionService as SdkTransactionService;
use Wallee\Sdk\Model\EntityQueryFilter;
use Wallee\Sdk\Model\EntityQueryFilterType;
use Wallee\Sdk\Model\CriteriaOperator;

/**
 * Class PaymentService
 * Handles api interactions regarding payment methods.
 */
class PaymentService extends AbstractService {
	private static $cache = array();
	private $transactionService;
	private $configurationService;

	protected function getTransactionService(){
		if ($this->transactionService === null) {
			$this->transactionService = new SdkTransactionService(WalleeModule::instance()->getApiClient());
		}
		return $this->transactionService;
	}

	protected function getConfigurationService(){
		if ($this->configurationService === null) {
			$this->configurationService = new PaymentMethodConfigurationService(WalleeModule::instance()->getApiClient());
		}
		return $this->configurationService;
	}

	public static function getOxPaymentId($WalleeId){
		return WalleeModule::PAYMENT_PREFIX . $WalleeId;
	}

	/**
	 * Fetches a list of available payment methods (oxpayment.oxid).
	 *
	 * @param $transactionId
	 * @param $spaceId
	 * @return array
	 */
	public function fetchAvailablePaymentMethods($transactionId, $spaceId){
		if (isset(self::$cache[$spaceId . $transactionId])) {
			return self::$cache[$spaceId . $transactionId];
		}
		try {
			$service = $this->getTransactionService();
			$integrationMode = WalleeModule::settings()->getIntegrationMode();
			$possibleMethods = ApiCallTracker::track('transaction.fetchPaymentMethods', function () use ($service, $spaceId, $transactionId, $integrationMode){
				return $service->fetchPaymentMethods($spaceId, $transactionId, $integrationMode);
			});
			foreach ($possibleMethods as $paymentMethod) {
				self::$cache[$spaceId . $transactionId][] = WalleeModule::createOxidPaymentId($paymentMethod->getId());
			}
		}
		catch (\Exception $e) {
			self::$cache[$spaceId . $transactionId] = array();
			throw $e;
		}
		return self::$cache[$spaceId . $transactionId];
	}

	/**
	 *
	 * @throws \Exception
	 * @throws \Wallee\Sdk\ApiException
	 */
	public function synchronize(){
		$service = $this->getConfigurationService();
		$spaceId = WalleeModule::settings()->getSpaceId();
		$query = $this->getQueryFilter('state', \Wallee\Sdk\Model\CreationEntityState::ACTIVE);
		$paymentMethods = ApiCallTracker::track('paymentMethodConfiguration.search', function () use ($service, $spaceId, $query){
			return $service->search($spaceId, $query);
		});

		$paymentList = oxNew(\OxidEsales\Eshop\Application\Model\PaymentList::class);
		/* @var $paymentList \Wle\Wallee\Extend\Application\Model\PaymentList */
		$paymentList->loadWalleePayments();

		$synchronized = array();
		foreach ($paymentMethods as $paymentMethod) {
			$this->updatePaymentMethod($paymentMethod);
			$synchronized[] = self::getOxPaymentId($paymentMethod->getId());
		}

		foreach ($paymentList as $payment) {
			/* @var $payment \OxidEsales\Eshop\Application\Model\Payment */
			if (!in_array($payment->getId(), $synchronized)) {
				self::disablePaymentMethod($payment->getId());
			}
		}
	}
	
	private function getQueryFilter($fieldName, $fieldValue){
		$query = new EntityQuery();
		$filter = new EntityQueryFilter();
		/**
		 * @noinspection PhpParamsInspection
		 */
		$filter->setType(EntityQueryFilterType::LEAF);
		/**
		 * @noinspection PhpParamsInspection
		 */
		$filter->setOperator(CriteriaOperator::EQUALS);
		$filter->setFieldName($fieldName);
		/**
		 * @noinspection PhpParamsInspection
		 */
		$filter->setValue($fieldValue);
		$query->setFilter($filter);
		return $query;
	}
	
	/**
	 *
	 * @param $paymentId
	 * @throws \Exception
	 */
	private static function disablePaymentMethod($paymentId){
		$payment = oxNew(\OxidEsales\Eshop\Application\Model\Payment::class);
		/* @var $payment \OxidEsales\Eshop\Application\Model\Payment */
		if ($payment->load($paymentId)) {
			$payment->oxpayments__oxactive = new \OxidEsales\Eshop\Core\Field(0);
			$payment->save();
		}
	}

	/**
	 * Adds or updates the given payment method.
	 * Returns true if the method was newly created, or false if an existing payment method was updated.
	 *
	 * @param PaymentMethodConfiguration $paymentMethod
	 * @return bool
	 * @throws \Exception
	 */
	private function updatePaymentMethod(PaymentMethodConfiguration $paymentMethod){
		$newMethod = false;
		
		$payment = oxNew(\OxidEsales\Eshop\Application\Model\Payment::class);
		/* @var $payment \OxidEsales\Eshop\Application\Model\Payment */
		if (!$payment->load(self::getOxPaymentId($paymentMethod->getId()))) {
			$payment->setId(self::getOxPaymentId($paymentMethod->getId()));
			$payment->oxpayments__oxaddsum = new \OxidEsales\Eshop\Core\Field(0);
			$payment->oxpayments__oxaddsumtype = new \OxidEsales\Eshop\Core\Field('abs');
			$payment->oxpayments__oxfromboni = new \OxidEsales\Eshop\Core\Field(0);
			$payment->oxpayments__oxfromamount = new \OxidEsales\Eshop\Core\Field(0);
			$payment->oxpayments__oxtoamount = new \OxidEsales\Eshop\Core\Field(100000);
			$newMethod = true;
		}

		// method is active in the space, so it must be selectable in the shop again (e.g. after switching spaces back and forth)
		$payment->oxpayments__oxactive = new \OxidEsales\Eshop\Core\Field(1);
		$payment->oxpayments__oxsort = new \OxidEsales\Eshop\Core\Field($paymentMethod->getSortOrder());
		
		$language = \OxidEsales\Eshop\Core\Registry::getLang();
		$languages = $language->getLanguageIds();
		
		$titles = $paymentMethod->getResolvedTitle();
		$descriptions = $paymentMethod->getResolvedDescription();

		$payment->save();

		/**
		 * @noinspection PhpParamsInspection
		 */
		foreach (array_keys($titles) as $languageCode) {
			$languageId = array_search(substr($languageCode, 0, 2), $languages);
			if ($languageId !== false) {
				$payment->setLanguage($languageId);
				$payment->oxpayments__oxdesc = new \OxidEsales\Eshop\Core\Field($titles[$languageCode]);
				$payment->oxpayments__oxlongdesc = new \OxidEsales\Eshop\Core\Field($descriptions[$languageCode]);
				$payment->save();
			}
		}

		if ($newMethod) {
			$this->assignPaymentToDeliverySets($payment->getId());
		}

		return $newMethod;
	}

	/**
	 * Assigns a payment method to all delivery sets, so it is selectable in the checkout without manual configuration.
	 *
	 * @param string $paymentId
	 * @throws \Exception
	 */
	private function assignPaymentToDeliverySets($paymentId){
		$db = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();
		$deliverySetIds = $db->getCol('SELECT `OXID` FROM `oxdeliveryset`');
		foreach ($deliverySetIds as $deliverySetId) {
			$exists = $db->getOne('SELECT `OXID` FROM `oxobject2payment` WHERE `OXPAYMENTID` = ? AND `OXOBJECTID` = ? AND `OXTYPE` = ?',
				array($paymentId, $deliverySetId, 'oxdelset'));
			if (!$exists) {
				$assignment = oxNew(\OxidEsales\Eshop\Core\Model\BaseModel::class);
				$assignment->init('oxobject2payment');
				$assignment->oxobject2payment__oxpaymentid = new \OxidEsales\Eshop\Core\Field($paymentId);
				$assignment->oxobject2payment__oxobjectid = new \OxidEsales\Eshop\Core\Field($deliverySetId);
				$assignment->oxobject2payment__oxtype = new \OxidEsales\Eshop\Core\Field('oxdelset');
				$assignment->save();
			}
		}
	}
}