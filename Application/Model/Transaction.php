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
namespace Wle\Wallee\Application\Model;

use Monolog\Logger;
use Wallee\Sdk\Model\CriteriaOperator;
use Wallee\Sdk\Model\EntityQuery;
use Wallee\Sdk\Model\EntityQueryFilter;
use Wallee\Sdk\Model\EntityQueryFilterType;
use Wallee\Sdk\Model\Label;
use Wallee\Sdk\Model\Refund;
use Wallee\Sdk\Model\TransactionCompletion;
use Wallee\Sdk\Model\TransactionLineItemVersionCreate;
use Wallee\Sdk\Model\TransactionState;
use Wallee\Sdk\Model\TransactionVoid;
use Wallee\Sdk\Service\RefundService;
use Wallee\Sdk\Service\TransactionCompletionService;
use Wallee\Sdk\Service\TransactionVoidService;
use Wle\Wallee\Core\Adapter\BasketAdapter;
use Wle\Wallee\Core\Adapter\SessionAdapter;
use Wle\Wallee\Core\Service\TransactionService;
use Wle\Wallee\Core\WalleeModule;
use Wallee\Sdk\Model\Transaction as sdkTransaction;
use Wallee\Sdk\ApiException;
use Wallee\Sdk\VersioningException;
use Wle\Wallee\Core\Exception\OptimisticLockingException;

/**
 * Class Transaction.
 * Transaction model.
 */
class Transaction extends \OxidEsales\Eshop\Core\Model\BaseModel {
	private $_sTableName = 'wleWallee_transaction';
	private $version = false;
	protected $dbVersion = null;
	/**
	 *
	 * @var sdkTransaction
	 */
	private $sdkTransaction;
	/**
	 * Whether the sdk transaction above was read from Wallee during this request,
	 * so it does not have to be pulled again before an update.
	 *
	 * @var bool
	 */
	private $sdkTransactionFresh = false;
	/**
	 * Fingerprint of the session data last pushed to Wallee during this request.
	 *
	 * @var string|null
	 */
	private $pushedFingerprint = null;
	/**
	 * Fingerprint of the row as it is currently stored in the database, null while it was never written.
	 *
	 * @var string|null
	 */
	private $persistedFingerprint = null;
	/**
	 * Pending transaction of the current request, so it is only fetched from Wallee once.
	 *
	 * @var Transaction|null
	 */
	private static $sessionTransaction = null;
	/**
	 * Session state the cached transaction above belongs to.
	 *
	 * @var string|null
	 */
	private static $sessionTransactionKey = null;
	protected $_aSkipSaveFields = [
		'oxtimestamp',
		'wleversion',
		'wleupdated' 
	];

	/**
	 * Class constructor.
	 */
	public function __construct(){
		parent::__construct();
		
		$this->init($this->_sTableName);
	}

	public function getTransactionId(){
		return $this->getFieldData('wletransactionid');
	}

	public function getOrderId(){
		return $this->getFieldData('oxorderid');
	}

	public function getSdkTransaction(){
		return $this->sdkTransaction;
	}

	public function getState(){
		return $this->getFieldData('wlestate');
	}

	public function getSpaceId(){
		return $this->getFieldData('wlespaceid');
	}

	/**
	 *
	 * @return \OxidEsales\Eshop\Application\Model\Basket
	 */
	public function getTempBasket(){
		return unserialize(base64_decode($this->getFieldData('wletempbasket')));
	}

	public function setTempBasket($basket){
		$this->_setFieldData('wletempbasket', base64_encode(serialize($basket)));
	}

	public function getSpaceViewId(){
		return $this->getFieldData('wlespaceviewid');
	}

	public function setFailureReason($value){
		$this->_setFieldData('wlefailurereason', base64_encode(serialize($value)));
	}

	public function getFailureReason(){
		$value = unserialize(base64_decode($this->getFieldData('wlefailurereason')));
		if (is_array($value)) {
			$value = WalleeModule::instance()->WalleeTranslate($value);
		}
		return $value;
	}

	public function getVersion(){
		return $this->version;
	}

	public function getEmailSent(){
		return $this->getFieldData('wleemailsent');
	}

	public function setOrderId($value){
		$this->_setFieldData('oxorderid', $value);
	}

	protected function setState($value){
		$this->_setFieldData('wlestate', $value);
	}

	protected function setSpaceId($value){
		$this->_setFieldData('wlespaceid', $value);
	}

	protected function setSpaceViewId($value){
		$this->_setFieldData('wlespaceviewid', $value);
	}

	protected function setTransactionId($value){
		$this->_setFieldData('wletransactionid', $value);
	}

	protected function setVersion($value){
		$this->version = $value;
	}

	protected function setSdkTransaction($value){
		$this->sdkTransaction = $value;
	}

	public function setEmailSent($value){
		$this->_setFieldData('wleemailsent', $value);
	}

	public function markEmailAsSent(){
		$this->setEmailSent(true);
	}

	public function loadByOrder($orderId){
		$select = $this->buildSelectString(array(
			'oxorderid' => $orderId
		));
		$this->_isLoaded = $this->assignRecord($select);
		$this->dbVersion = $this->getFieldData('wleversion');
		if ($this->_isLoaded) {
			$this->persistedFingerprint = $this->getPersistedFingerprint();
		}
		return $this->_isLoaded;
	}

	/**
	 *
	 * @param \OxidEsales\Eshop\Core\Session $session
	 * @return bool|object|Transaction
	 * @throws ApiException
	 * @throws \Exception
	 */
	public static function loadPendingFromSession(\OxidEsales\Eshop\Core\Session $session){
		$cached = self::getCachedSessionTransaction($session);
		if ($cached !== null) {
			WalleeModule::log(Logger::DEBUG, "Reusing pending transaction {$cached->getTransactionId()} of this request.");
			return $cached;
		}

		$transaction = self::loadFromSession($session, TransactionState::PENDING);
		if (!$transaction) {
			$transaction = oxNew(\Wle\Wallee\Application\Model\Transaction::class);
			/* @var $transaction \Wle\Wallee\Application\Model\Transaction */
			$transaction->create();
		} else {
			$transaction->updateFromSession();
		}
		self::cacheSessionTransaction($session, $transaction);
		return $transaction;
	}

	/**
	 * Drops the cached pending transaction of the current request.
	 */
	private static function clearSessionTransactionCache(){
		self::$sessionTransaction = null;
		self::$sessionTransactionKey = null;
	}

	/**
	 * Returns the cached transaction if it still matches the session and is still pending.
	 *
	 * @param \OxidEsales\Eshop\Core\Session $session
	 * @return Transaction|null
	 */
	private static function getCachedSessionTransaction(\OxidEsales\Eshop\Core\Session $session){
		if (self::$sessionTransaction === null) {
			return null;
		}
		$key = self::getSessionCacheKey($session);
		// The transaction leaves the PENDING state once it is confirmed, from then on a new one has to be created.
		if ($key === null || $key !== self::$sessionTransactionKey ||
				self::$sessionTransaction->getState() !== TransactionState::PENDING) {
			self::clearSessionTransactionCache();
			return null;
		}
		return self::$sessionTransaction;
	}

	private static function cacheSessionTransaction(\OxidEsales\Eshop\Core\Session $session, Transaction $transaction){
		self::$sessionTransactionKey = self::getSessionCacheKey($session);
		self::$sessionTransaction = self::$sessionTransactionKey === null ? null : $transaction;
	}

	/**
	 * Identifies the transaction the session currently points to.
	 *
	 * @param \OxidEsales\Eshop\Core\Session $session
	 * @return string|null
	 */
	private static function getSessionCacheKey(\OxidEsales\Eshop\Core\Session $session){
		$transactionId = $session->getVariable('Wallee_transaction_id');
		$spaceId = $session->getVariable('Wallee_space_id');
		$userId = $session->getVariable('Wallee_user_id');
		if (!$transactionId || !$spaceId || !$userId) {
			return null;
		}
		return implode('|', array(
			$transactionId,
			$spaceId,
			$userId
		));
	}

	/**
	 *
	 * @param \OxidEsales\Eshop\Core\Session $session
	 * @return bool|object|Transaction
	 * @throws ApiException
	 * @throws \Exception
	 */
	public static function loadConfirmedFromSession(\OxidEsales\Eshop\Core\Session $session){
		return self::loadFromSession($session, TransactionState::CONFIRMED);
	}

	/**
	 *
	 * @param \OxidEsales\Eshop\Core\Session $session
	 * @return bool|object|Transaction
	 * @throws ApiException
	 * @throws \Exception
	 */
	public static function loadFailedFromSession(\OxidEsales\Eshop\Core\Session $session){
		return self::loadFromSession($session, TransactionState::FAILED);
	}

	/**
	 * Loads a transaction from the variables stored in the session, with the given state (In Wallee, not in DB).
	 *
	 * @param \OxidEsales\Eshop\Core\Session $session
	 * @param $expectedState
	 * @return bool|object|Transaction
	 * @throws ApiException
	 * @throws \Exception
	 */
	protected static function loadFromSession(\OxidEsales\Eshop\Core\Session $session, $expectedState){
		$transaction = oxNew(\Wle\Wallee\Application\Model\Transaction::class);
		/* @var $transaction Transaction */
		$transactionId = $session->getVariable('Wallee_transaction_id');
		$spaceId = $session->getVariable('Wallee_space_id');
		$userId = $session->getVariable('Wallee_user_id');
		// The session may still carry the transaction while the user is already gone, for example
		// when the customer logs out on the checkout page.
		$user = $session->getUser();

		if ($user && $transactionId && $spaceId && $userId == $user->getId() && $spaceId == WalleeModule::settings()->getSpaceId()) {
			if (!$transaction->loadByTransactionAndSpace($transactionId, $spaceId)) {
				$transaction->setSpaceId($spaceId);
				$transaction->setTransactionId($transactionId);
			}
			$transaction->pull();
			if ($transaction->getState() === $expectedState) {
				$transaction->dbVersion = $transaction->getFieldData('wleversion');
				return $transaction;
			}
		}
		return false;
	}

	public function loadByTransactionAndSpace($transactionId, $spaceId){
		$select = $this->buildSelectString(
				array(
					'wletransactionid' => $transactionId,
					'wlespaceid' => $spaceId 
				));
		$this->_isLoaded = $this->assignRecord($select);
		$this->dbVersion = $this->getFieldData('wleversion');
		if ($this->_isLoaded) {
			$this->persistedFingerprint = $this->getPersistedFingerprint();
		}
		return $this->_isLoaded;
	}

	public function getLabels(){
		return array(
			'transaction' => $this->getTransactionLabels(),
			'completions' => array(
				'title' => WalleeModule::instance()->translate('Completions'),
				'labelGroup' => $this->getCompletionLabels() 
			),
			'voids' => array(
				'title' => WalleeModule::instance()->translate('Voids'),
				'labelGroup' => $this->getVoidLabels() 
			),
			'refunds' => array(
				'title' => WalleeModule::instance()->translate('Refunds'),
				'labelGroup' => $this->getRefundLabels() 
			) 
		);
	}

	/**
	 * Creates a query containing a filter for the transaction id.
	 * The field name can be overwritten using the parameter, standard is transaction.id
	 *
	 * @param string $fieldName
	 * @return EntityQuery
	 */
	private function getTransactionQuery($fieldName = 'transaction.id'){
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
		$filter->setValue($this->getTransactionId());
		$query->setFilter($filter);
		return $query;
	}

	private function getTransactionLabels(){
		$paymentMethod = $paymentDescription = '';
		if ($this->getSdkTransaction()->getPaymentConnectorConfiguration()) {
			if ($this->getSdkTransaction()->getPaymentConnectorConfiguration()->getPaymentMethodConfiguration()) {
				$paymentDescription = WalleeModule::instance()->WalleeTranslate(
						$this->getSdkTransaction()->getPaymentConnectorConfiguration()->getPaymentMethodConfiguration()->getResolvedDescription());
				$paymentMethod = WalleeModule::instance()->WalleeTranslate(
						$this->getSdkTransaction()->getPaymentConnectorConfiguration()->getPaymentMethodConfiguration()->getResolvedTitle());
			}
			else {
				$paymentMethod = $this->getSdkTransaction()->getPaymentConnectorConfiguration()->getName();
				$paymentDescription = $this->getSdkTransaction()->getPaymentConnectorConfiguration()->getId();
			}
		}
		
		$openText = WalleeModule::instance()->translate('Open');
		$labels = array(
			'title' => WalleeModule::instance()->translate('Transaction information'),
			'labelGroup' => array(
				array(
					'title' => WalleeModule::instance()->translate("Transaction #!id", true, array(
						'!id' => $this->getTransactionId() 
					)),
					'labels' => array(
						array(
							'title' => WalleeModule::instance()->translate('Status'),
							'description' => WalleeModule::instance()->translate('Status in the wallee system'),
							'value' => $this->getState() 
						),
						array(
							'title' => WalleeModule::instance()->translate('wallee Link'),
							'description' => WalleeModule::instance()->translate('Open in your wallee backend'),
							'value' => $this->getWalleeLink('transaction', $this->getSpaceId(), $this->getTransactionId(), $openText) 
						),
						array(
							'title' => WalleeModule::instance()->translate('Authorization amount'),
							'description' => WalleeModule::instance()->translate(
									'The amount which was authorized with the wallee transaction.'),
							'value' => $this->getSdkTransaction()->getAuthorizationAmount() 
						),
						array(
							'title' => WalleeModule::instance()->translate('Payment method'),
							'description' => $paymentDescription,
							'value' => $paymentMethod 
						) 
					) 
				) 
			) 
		);
		
		$order = oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
		if (!$order->load($this->getOrderId())) {
			throw new \Exception("Unable to load order {$this->getOrderId()} for transaction {$this->getTransactionId()}.");
		}
		
		foreach ($order->getWalleeDownloads() as $download) {
			$labels['labelGroup'][0]['labels'][] = array(
				'title' => $download['text'],
				'description' => $download['text'],
				'value' => "<a href='{$download['link']}' target='_blank' style='text-decoration: underline;'>$openText</a>" 
			);
		}
		
		return $labels;
	}

	private function getWalleeLink($type, $space, $id, $link_text){
		$base_url = WalleeModule::settings()->getBaseUrl();
		$url = "$base_url/s/$space/payment/$type/view/$id";
		return "<a href='$url' target='_blank' style='text-decoration: underline;'>$link_text</a>";
	}

	/**
	 *
	 * @return array
	 * @throws ApiException
	 */
	private function getCompletionLabels(){
		$service = new TransactionCompletionService(WalleeModule::instance()->getApiClient());
		$completions = $service->search($this->getSpaceId(), $this->getTransactionQuery('lineItemVersion.transaction.id'));
		return $this->convertJobLabels($completions);
	}

	/**
	 *
	 * @param TransactionCompletion[]|TransactionVoid[]|Refund[] $jobs
	 * @return array
	 */
	private function convertJobLabels($jobs){
		$labelGroup = array();
		foreach ($jobs as $job) {
			$jobLabels = array(
				array(
					'title' => WalleeModule::instance()->translate('Status'),
					'description' => WalleeModule::instance()->translate('Status in the wallee system.'),
					'value' => $job->getState() 
				),
				array(
					'title' => WalleeModule::instance()->translate('wallee Link'),
					'description' => WalleeModule::instance()->translate('Open in your wallee backend.'),
					'value' => $this->getWalleeLink($this->getJobLinkType($job), $job->getLinkedSpaceId(), $job->getId(),
							WalleeModule::instance()->translate('Open')) 
				) 
			);
			foreach ($job->getLabels() as $label) {
				$jobLabels[] = $this->convertLabel($label);
			}
			if ($job instanceof Refund) {
				$message = 'Refund #!id';
			}
			else if ($job instanceof TransactionCompletion) {
				$message = 'Completion #!id';
			}
			else if ($job instanceof TransactionVoid) {
				$message = 'Void #!id';
			}
			else {
				$message = get_class($job) . ' !id';
			}
			
			$labelGroup[$job->getId()] = array(
				'title' => WalleeModule::instance()->translate($message, true, array(
					'!id' => $job->getId() 
				)),
				'labels' => $jobLabels 
			);
		}
		return $labelGroup;
	}

	private function getJobLinkType($job){
		if ($job instanceof TransactionVoid) {
			return 'void';
		}
		else if ($job instanceof TransactionCompletion) {
			return 'completion';
		}
		else if ($job instanceof Refund) {
			return 'refund';
		}
		$type = get_class($job);
		WalleeModule::log(Logger::ERROR, "Unable to match job link type for $type.");
		return $type;
	}

	private function convertLabel(Label $label){
		/**
		 * @noinspection PhpParamsInspection
		 */
		return array(
			'title' => WalleeModule::instance()->WalleeTranslate($label->getDescriptor()->getName()),
			'description' => WalleeModule::instance()->WalleeTranslate($label->getDescriptor()->getDescription()),
			'value' => $label->getContentAsString() 
		);
	}

	/**
	 *
	 * @return array
	 * @throws ApiException
	 */
	private function getVoidLabels(){
		$service = new TransactionVoidService(WalleeModule::instance()->getApiClient());
		$voids = $service->search($this->getSpaceId(), $this->getTransactionQuery());
		return $this->convertJobLabels($voids);
	}

	/**
	 *
	 * @return array
	 * @throws ApiException
	 */
	private function getRefundLabels(){
		$service = new RefundService(WalleeModule::instance()->getApiClient());
		$refunds = $service->search($this->getSpaceId(), $this->getTransactionQuery());
		return $this->convertJobLabels($refunds);
	}

	/**
	 *
	 * @throws ApiException
	 * @throws \Exception
	 */
	public function pull(){
		WalleeModule::log(Logger::DEBUG, "Start transaction pull.");
		if (!$this->getTransactionId()) {
			throw new \Exception('Transaction id must be set to pull.');
		}
		$this->apply(TransactionService::instance()->read($this->getTransactionId(), $this->getSpaceId()));
		WalleeModule::log(Logger::DEBUG, "Transaction pull complete.");
	}

	/**
	 *
	 * @param bool $confirm
	 * @return sdkTransaction
	 * @throws ApiException
	 * @throws \Exception
	 */
	public function updateFromSession($confirm = false){
		WalleeModule::log(Logger::DEBUG, "Start update from session.");
		// A confirm always pulls: it is the last call of the checkout and must run on the version the
		// portal currently holds, the iframe validation may have moved it on since the page was built.
		if ($confirm || !$this->sdkTransactionFresh) {
			$this->pull(); // ensure updateable
		}
		$this->assertPending();

		$adapter = new SessionAdapter($this->getSession());
		$updateData = $adapter->getUpdateData($this);
		$fingerprint = $adapter->getUpdateFingerprint($updateData);
		// A confirm must always be sent, it is not a plain data push.
		if (!$confirm && $fingerprint === $this->pushedFingerprint) {
			WalleeModule::log(Logger::DEBUG, "Session data unchanged since the last push, skipping transaction update.");
			return $this->getSdkTransaction();
		}

		try {
			$transaction = TransactionService::instance()->update($updateData, $confirm);
		}
		catch (\Exception $e) {
			// An outdated local version is the only failure we may safely repeat. The call was rejected
			// with a conflict, so nothing was applied yet, and the pull below makes sure the transaction
			// is still pending before it is sent again.
			if (!($e instanceof VersioningException)) {
				throw $e;
			}
			WalleeModule::log(Logger::DEBUG, "Outdated transaction version, refreshing and retrying update once.");
			$this->pull();
			$this->assertPending();
			// rebuild, the pull assigned a new version
			$transaction = TransactionService::instance()->update($adapter->getUpdateData($this), $confirm);
		}
		$this->apply($transaction);
		$this->pushedFingerprint = $fingerprint;
		WalleeModule::log(Logger::DEBUG, "Complete update from session.");
		return $transaction;
	}

	/**
	 *
	 * @throws \Exception
	 */
	private function assertPending(){
		if ($this->getState() !== TransactionState::PENDING) {
			throw new \Exception('Transaction not in state PENDING may no longer be updated:' . $this->getTransactionId());
		}
	}
	
	public function getPaymentPageUrl() {
		return TransactionService::instance()->getPaymentPageUrl($this->getTransactionId(), $this->getSpaceId());
	}

	public function updateLineItems(){
		WalleeModule::log(Logger::DEBUG, "Start update line items.");
		$order = oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
		/* @var $order\OxidEsales\Eshop\Application\Model\Order */
		if (!$order->load($this->getOrderId())) {
			throw new \Exception("Unable to load order {$this->getOrderId()} for transaction {$this->getTransactionId()}.");
		}
		$adapter = new BasketAdapter($order->getWalleeBasket());
		$adapter->getLineItemData();
		$update = new TransactionLineItemVersionCreate();
		$update->setLineItems($adapter->getLineItemData());
		$update->setTransaction($this->getTransactionId());
	    	$update->setExternalId(uniqid($this->getTransactionId()));
		TransactionService::instance()->updateLineItems($this->getSpaceId(), $update);
		$this->pull();
		WalleeModule::log(Logger::DEBUG, "Complete update line items.");
		return $this->getSdkTransaction();
	}

	/**
	 *
	 * @return sdkTransaction
	 * @throws ApiException
	 * @throws \Exception
	 */
	public function create(){
		WalleeModule::log(Logger::DEBUG, "Start transaction create.");
		$adapter = new SessionAdapter($this->getSession());
		$transaction = TransactionService::instance()->create($adapter->getCreateData());
		$this->dbVersion = 0;
		$this->apply($transaction);
		
		WalleeModule::log(Logger::DEBUG, "Complete transaction create.");
		return $transaction;
	}

	/**
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function save(){
		WalleeModule::log(Logger::DEBUG, "Start transaction save.");
		// only save to db with order, otherwise save relevant ids to session.
		if ($this->getOrderId()) {
			WalleeModule::log(Logger::DEBUG, "Saving to database.");
			$result = parent::save();
			$this->persistedFingerprint = $this->getPersistedFingerprint();
			return $result;
		}
		else if ($this->getSession()->getUser()) {
			WalleeModule::log(Logger::DEBUG, "Saving to session.");
			$this->getSession()->setVariable('Wallee_transaction_id', $this->getTransactionId());
			$this->getSession()->setVariable('Wallee_space_id', $this->getSpaceId());
			$this->getSession()->setVariable('Wallee_user_id', $this->getSession()->getUser()->getId());
		}
		return false;
	}

	/**
	 *
	 * @param sdkTransaction $transaction
	 * @throws \Exception
	 */
	protected function apply(sdkTransaction $transaction){
		$this->sdkTransactionFresh = true;
		$this->setSdkTransaction($transaction);
		$this->applySdkFields($transaction);

		// A read which brought back the same data must not write the row again, every write increments
		// the optimistic locking version and makes concurrent webhooks fail.
		if ($this->persistedFingerprint !== null && $this->persistedFingerprint === $this->getPersistedFingerprint()) {
			WalleeModule::log(Logger::DEBUG, "Transaction row is unchanged, skipping save.");
			return;
		}
		try {
			$this->save();
		}
		catch (OptimisticLockingException $e) {
			// Another process, usually a webhook for the very state we just read, wrote the row while this
			// request was working with it. Reloading keeps what it wrote and lets this request finish
			// instead of aborting the whole checkout step with a locking error.
			WalleeModule::log(Logger::DEBUG,
					"Transaction row was changed concurrently, reloading and saving once more.");
			$this->reapplyAfterConflict($transaction);
		}
	}

	/**
	 * Copies the state of the Wallee transaction onto this model.
	 *
	 * @param sdkTransaction $transaction
	 */
	private function applySdkFields(sdkTransaction $transaction){
		$this->setTransactionId($transaction->getId());
		$this->setVersion($transaction->getVersion());
		$this->setState($transaction->getState());
		$this->setSpaceId($transaction->getLinkedSpaceId());
		$this->setSpaceViewId($transaction->getSpaceViewId());
	}

	/**
	 * Re-reads the row after a locking conflict and writes it again, with the fields this request
	 * changed on top of the ones the concurrent writer left behind.
	 *
	 * @param sdkTransaction $transaction
	 * @throws \Exception
	 */
	private function reapplyAfterConflict(sdkTransaction $transaction){
		$ownFields = array(
			'oxorderid' => $this->getOrderId(),
			'wletempbasket' => $this->getFieldData('wletempbasket'),
			'wlefailurereason' => $this->getFieldData('wlefailurereason')
		);
		if (!$this->loadByTransactionAndSpace($transaction->getId(), $transaction->getLinkedSpaceId())) {
			throw new \Exception("Unable to reload transaction {$transaction->getId()} after a locking conflict.");
		}
		foreach ($ownFields as $field => $value) {
			if ($value) {
				$this->_setFieldData($field, $value);
			}
		}
		$this->applySdkFields($transaction);
		if ($this->persistedFingerprint === $this->getPersistedFingerprint()) {
			WalleeModule::log(Logger::DEBUG, "Transaction row already holds this state, skipping save.");
			return;
		}
		$this->save();
	}

	/**
	 * Fingerprint over every field this model writes to the database.
	 *
	 * @return string
	 */
	private function getPersistedFingerprint(){
		return md5(implode('|', array(
			(string) $this->getTransactionId(),
			(string) $this->getState(),
			(string) $this->getSpaceId(),
			(string) $this->getSpaceViewId(),
			(string) $this->getOrderId(),
			(string) $this->getFieldData('wletempbasket'),
			(string) $this->getFieldData('wlefailurereason'),
			(string) $this->getEmailSent()
		)));
	}

	/**
	 * Overwrite _update method to introduce optimistic locking.
	 *
	 * {@inheritdoc}
	 * @see \OxidEsales\EshopCommunity\Core\Model\BaseModel::_update()
	 */
	protected function _update(){
		//do not allow derived item update
		if (!$this->allowDerivedUpdate()) {
			return false;
		}
		
		if (!$this->getId()) {
			$exception = oxNew(\OxidEsales\Eshop\Core\Exception\ObjectException::class);
			$exception->setMessage('EXCEPTION_OBJECT_OXIDNOTSET');
			$exception->setObject($this);
			throw $exception;
		}
		$coreTableName = $this->getCoreTableName();
		
		$idKey = \OxidEsales\Eshop\Core\Registry::getUtils()->getArrFldName($coreTableName . '.oxid');
		$this->$idKey = new \OxidEsales\Eshop\Core\Field($this->getId(), \OxidEsales\Eshop\Core\Field::T_RAW);
		$database = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();
		
		$dbVersion = $this->dbVersion;
		if (!$dbVersion) {
			$dbVersion = 0;
		}
		$updateQuery = "update {$coreTableName} set " . $this->_getUpdateFields() . " , wleversion=wleversion + 1 " .
				 " where {$coreTableName}.oxid = " . $database->quote($this->getId()) .
				 " and {$coreTableName}.wleversion = {$dbVersion}";
		WalleeModule::log(Logger::DEBUG, "Updating  transaction with query [$updateQuery]");

		$this->beforeUpdate();
		$affected = \OxidEsales\Eshop\Core\DatabaseProvider::getDb()->execute($updateQuery);

		if ($affected === 0) {
			throw new OptimisticLockingException($this->getId(), $this->_sTableName, $updateQuery);
		}
		$this->dbVersion = $dbVersion + 1;
		
		return true;
	}
}