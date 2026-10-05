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

use Wle\Wallee\Application\Model\Transaction;
use Wle\Wallee\Core\Service\PaymentService;
use Wle\Wallee\Core\WalleeModule;
use Monolog\Logger;

/**
 * Class PaymentList.
 * Extends \OxidEsales\Eshop\Application\Model\PaymentList.
 *
 * @mixin \OxidEsales\Eshop\Application\Model\PaymentList
 */
class PaymentList extends PaymentList_parent
{

    /**
     * Loads all Wallee payment methods.
     */
    public function loadWalleePayments()
    {
        $prefix = WalleeModule::PAYMENT_PREFIX;
        $this->selectString("SELECT * FROM `oxpayments` WHERE `oxid` LIKE '$prefix%'");
        return $this->_aArray;
    }

    /**
     * Loads all Wallee payment methods.
     */
    public function loadActiveWalleePayments()
    {
        $prefix = WalleeModule::PAYMENT_PREFIX;
        $this->selectString("SELECT * FROM `oxpayments` WHERE `oxid` LIKE '$prefix%' AND `oxactive` = '1'");
        return $this->_aArray;
    }

    /**
     * Filtered payment lists of the current request, keyed by the arguments they were built with.
     * OXID asks for the payment list several times per request, without this the Wallee
     * lookup behind it would be repeated just as often.
     *
     * @var array
     */
    private static $filteredListCache = array();

    public function getPaymentList($sShipSetId, $dPrice, $oUser = null)
    {
        if ($this->isAdmin()) {
            return $this->_PaymentList_getPaymentList_parent($sShipSetId, $dPrice, $oUser);
        }

        // The shop recalculates the basket outside of the checkout as well, a login with an empty
        // basket for example. Loading the pending transaction here would create a transaction in
        // Wallee for a customer who has nothing to pay for.
        if (!$this->hasWalleePayableBasket()) {
            return $this->_PaymentList_getPaymentList_parent($sShipSetId, $dPrice, $oUser);
        }

        $cacheKey = $this->getWalleeListCacheKey($sShipSetId, $dPrice, $oUser);
        if (isset(self::$filteredListCache[$cacheKey])) {
            return $this->setWalleePayments(self::$filteredListCache[$cacheKey]);
        }

        $oxPayments = $this->_PaymentList_getPaymentList_parent($sShipSetId, $dPrice, $oUser);
        $this->clear();
        $WalleePayments = array();
        try {
            $transaction = Transaction::loadPendingFromSession($this->getSession());
            $WalleePayments = PaymentService::instance()->fetchAvailablePaymentMethods($transaction->getTransactionId(), $transaction->getSpaceId());
        } catch (\Exception $e) {
            WalleeModule::log(Logger::ERROR, $e->getMessage(), array($this, $e));
            // an incomplete list must not be cached, the next call has to try again
            return $this->filterWalleePayments($oxPayments, $WalleePayments);
        }

        $filtered = $this->filterWalleePayments($oxPayments, $WalleePayments);
        self::$filteredListCache[$cacheKey] = $filtered;
        return $filtered;
    }

    /**
     * Tells whether there is anything to pay for at all. Without a basket holding products there is
     * no checkout the payment methods could be filtered for.
     *
     * @return bool
     */
    private function hasWalleePayableBasket()
    {
        $basket = $this->getSession()->getBasket();
        return $basket && $basket->getProductsCount() > 0;
    }

    /**
     * Keeps the non Wallee payments and those which are available for the transaction.
     *
     * @param array $oxPayments
     * @param array $WalleePayments
     * @return array
     */
    private function filterWalleePayments($oxPayments, $WalleePayments)
    {
        $this->clear();
        foreach ($oxPayments as $oxPayment) {
            /* @var $oxPayment \OxidEsales\Eshop\Application\Model\Payment */
            if (WalleeModule::isWalleePayment($oxPayment->getId())) {
                if (in_array($oxPayment->getId(), $WalleePayments)) {
                    $this->add($oxPayment);
                }
            } else {
                $this->add($oxPayment);
            }
        }
        return $this->_aArray;
    }

    private function setWalleePayments($payments)
    {
        $this->clear();
        foreach ($payments as $payment) {
            $this->add($payment);
        }
        return $this->_aArray;
    }

    /**
     * Identifies a payment list by everything it is built from. Only valid within one request,
     * the basket cannot change while it is being rendered.
     *
     * @param string $sShipSetId
     * @param double $dPrice
     * @param \OxidEsales\Eshop\Application\Model\User|null $oUser
     * @return string
     */
    private function getWalleeListCacheKey($sShipSetId, $dPrice, $oUser = null)
    {
        $user = $oUser ? $oUser : $this->getSession()->getUser();
        return implode('|', array(
            (string) $sShipSetId,
            (string) $dPrice,
            $user ? $user->getId() : '',
            (string) \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage()
        ));
    }

    protected function _PaymentList_getPaymentList_parent($sShipSetId, $dPrice, $oUser = null)
    {
        return parent::getPaymentList($sShipSetId, $dPrice, $oUser);
    }
}