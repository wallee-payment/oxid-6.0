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

use Monolog\Logger;
use Wallee\Sdk\Model\EntityQuery;
use Wallee\Sdk\Model\TransactionCreate;
use Wallee\Sdk\Model\TransactionLineItemVersionCreate;
use Wallee\Sdk\Model\TransactionPending;
use Wle\Wallee\Core\WalleeModule;
use \Wallee\Sdk\Service\TransactionService as SdkTransactionService;
use \Wallee\Sdk\Service\TransactionIframeService;
use Wallee\Sdk\Service\TransactionInvoiceService;
use Wallee\Sdk\Service\TransactionLineItemVersionService;
use \Wallee\Sdk\Service\TransactionPaymentPageService;
use Wle\Wallee\Core\Settings\IntegrationMode;

/**
 * Class TransactionService
 * Handles api interactions regarding transaction.
 *
 * @codeCoverageIgnore
 */
class TransactionService extends AbstractService {
	private $service;
	private $invoiceService;
	private $paymentPageService;
	private $iframeService;
	private $transactionLineItemVersionService;

	protected function getService(){
		if (!$this->service) {
			$this->service = new SdkTransactionService(WalleeModule::instance()->getApiClient());
		}
		return $this->service;
	}

	protected function getTransactionLineItemVersionService(){
	    if (!$this->transactionLineItemVersionService) {
		$this->transactionLineItemVersionService = new TransactionLineItemVersionService(WalleeModule::instance()->getApiClient());
	    }
	    return $this->transactionLineItemVersionService;
	}

	/**
	 *
	 * @return TransactionInvoiceService
	 */
	protected function getInvoiceService(){
		if (!$this->invoiceService) {
			$this->invoiceService = new TransactionInvoiceService(WalleeModule::instance()->getApiClient());
		}
		return $this->invoiceService;
	}
	
	/**
	 *
	 * @return TransactionPaymentPageService
	 */
	protected function getPaymentPageService(){
		if (!$this->paymentPageService) {
			$this->paymentPageService = new TransactionPaymentPageService(WalleeModule::instance()->getApiClient());
		}
		return $this->paymentPageService;
	}
	
	/**
	 *
	 * @return TransactionIframeService
	 */
	protected function getIframeService(){
		if (!$this->iframeService) {
			$this->iframeService = new TransactionIframeService(WalleeModule::instance()->getApiClient());
		}
		return $this->iframeService;
	}

	/**
	 * Reads a transaction entity from Wallee
	 *
	 * @param $transactionId
	 * @param $spaceId
	 * @return \Wallee\Sdk\Model\Transaction
	 * @throws \Wallee\Sdk\ApiException
	 */
	public function read($transactionId, $spaceId){
		$service = $this->getService();
		return ApiCallTracker::track('transaction.read', function () use ($service, $spaceId, $transactionId){
			return $service->read($spaceId, $transactionId);
		});
	}

	/**
	 *
	 * @param TransactionCreate $transaction
	 * @return \Wallee\Sdk\Model\Transaction
	 * @throws \Wallee\Sdk\ApiException
	 */
	public function create(TransactionCreate $transaction){
		$service = $this->getService();
		$spaceId = WalleeModule::settings()->getSpaceId();
		return ApiCallTracker::track('transaction.create', function () use ($service, $spaceId, $transaction){
			return $service->create($spaceId, $transaction);
		});
	}

	/**
	 *
	 * @param $transactionId
	 * @param $spaceId
	 * @return \Wallee\Sdk\Model\TransactionInvoice
	 * @throws \Exception
	 * @throws \Wallee\Sdk\ApiException
	 */
	public function getInvoice($transactionId, $spaceId){
		$query = new EntityQuery();
		$query->setFilter($this->createEntityFilter('completion.lineItemVersion.transaction.id', $transactionId));
		$query->setNumberOfEntities(1);
		$service = $this->getInvoiceService();
		$invoices = ApiCallTracker::track('transactionInvoice.search', function () use ($service, $spaceId, $query){
			return $service->search($spaceId, $query);
		});
		if (empty($invoices)) {
			throw new \Exception("No transaction invoice found for transaction $transactionId / $spaceId.");
		}
		return $invoices[0];
	}

	/**
	 *
	 * @param string $transactionId
	 * @param string $spaceId
	 * @return string
	 * @throws \Wallee\Sdk\ApiException
	 */
	public function getPaymentPageUrl($transactionId, $spaceId){
		$service = $this->getPaymentPageService();
		return ApiCallTracker::track('transaction.paymentPageUrl', function () use ($service, $spaceId, $transactionId){
			return $service->paymentPageUrl($spaceId, $transactionId);
		});
	}

	/**
	 *
	 * @param TransactionPending $transaction
	 * @param bool $confirm
	 * @return \Wallee\Sdk\Model\Transaction
	 * @throws \Wallee\Sdk\ApiException
	 */
	public function update(TransactionPending $transaction, $confirm = false){
		$service = $this->getService();
		$spaceId = WalleeModule::settings()->getSpaceId();
		if ($confirm) {
			return ApiCallTracker::track('transaction.confirm', function () use ($service, $spaceId, $transaction){
				return $service->confirm($spaceId, $transaction);
			});
		}
		else {
			return ApiCallTracker::track('transaction.update', function () use ($service, $spaceId, $transaction){
				return $service->update($spaceId, $transaction);
			});
		}
	}
	/**
	* Create a version of line items
	*
	* @param string $spaceId
	* @param \Wallee\Sdk\Model\TransactionLineItemVersionCreate $lineItemVersion
	* @return \Wallee\Sdk\Model\TransactionLineItemVersion
	* @throws \Wallee\Sdk\ApiException
	* @throws \Wallee\Sdk\Http\ConnectionException
	* @throws \Wallee\Sdk\VersioningException 
	*/
	public function updateLineItems($spaceId, TransactionLineItemVersionCreate $lineItemVersion){
		$service = $this->getTransactionLineItemVersionService();
		return ApiCallTracker::track('transaction.updateLineItems', function () use ($service, $spaceId, $lineItemVersion){
			return $service->create($spaceId, $lineItemVersion);
		});
	}

	/**
	 *
	 * @param $transactionId
	 * @param $spaceId
	 * @return string
	 * @throws \Wallee\Sdk\ApiException
	 */
	public function getJavascriptUrl($transactionId, $spaceId){
		$jsUrl = '';
		switch (WalleeModule::settings()->getIntegrationMode()) {
			case IntegrationMode::IFRAME:
				$service = $this->getIframeService();
				$jsUrl = ApiCallTracker::track('transaction.javascriptUrl', function () use ($service, $spaceId, $transactionId){
					return $service->javascriptUrl($spaceId, $transactionId);
				});
				break;
		}
		return $jsUrl;
	}
}