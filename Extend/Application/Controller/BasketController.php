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

use Wle\Wallee\Core\WalleeModule;

/**
 * Class used to include tracking device id on basket.
 *
 * Class BasketController.
 * Extends \OxidEsales\Eshop\Application\Controller\BasketController.
 *
 * @mixin \OxidEsales\Eshop\Application\Controller\BasketController
 */
class BasketController extends BasketController_parent
{
    public function render()
    {
        parent::render();

        $this->setWalleeDeviceCookie();
        $this->_aViewData['WalleeDeviceScript'] = $this->getWalleeDeviceUrl();

        return 'wleWalleeCheckoutBasket.tpl';
    }

    private function getWalleeDeviceUrl()
    {
        $script = WalleeModule::settings()->getBaseUrl();
        $script .= '/s/[spaceId]/payment/device.js?sessionIdentifier=[UniqueSessionIdentifier]';

        $script = str_replace(array(
            '[spaceId]',
            '[UniqueSessionIdentifier]'
        ), array(
            WalleeModule::settings()->getSpaceId(),
            $_COOKIE['Wallee_device_id']
        ), $script);

        return $script;
    }

    private function setWalleeDeviceCookie()
    {
        if (isset($_COOKIE['Wallee_device_id'])) {
            $value = $_COOKIE['Wallee_device_id'];
        } else {
        	$_COOKIE['Wallee_device_id'] = $value = WalleeModule::getUtilsObject()->generateUId();
        }
        setcookie('Wallee_device_id', $value, time() + 365 * 24 * 60 * 60, '/');
    }
}