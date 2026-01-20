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
 * Extends \OxidEsales\Eshop\Application\Controller\BasketController.
 *
 * @mixin \OxidEsales\Eshop\Application\Controller\BasketController
 */
class BasketController extends BasketController_parent
{
    /**
     * Keep the original template return value to preserve OXID inheritance chain.
     *
     * @return string
     */
    public function render()
    {
        $template = parent::render();
        $deviceId = $this->ensureWalleeDeviceCookie();
        $this->_aViewData['WalleeDeviceScript'] = $this->getWalleeDeviceUrl($deviceId);

        return $template;
    }

    /**
     * Build device script URL.
     *
     * @param string $deviceId
     * @return string
     */
    private function getWalleeDeviceUrl($deviceId)
    {
        $baseUrl = (string) WalleeModule::settings()->getBaseUrl();
        $spaceId = (string) WalleeModule::settings()->getSpaceId();
        $script = rtrim($baseUrl, '/')
            . '/s/[spaceId]/payment/device.js?sessionIdentifier=[UniqueSessionIdentifier]';

        $script = str_replace(
            array('[spaceId]', '[UniqueSessionIdentifier]'),
            array($spaceId, rawurlencode((string) $deviceId)),
            $script
        );

        return $script;
    }

    /**
     * Ensure device cookie exists and return its value.
     *
     * @return string
     */
    private function ensureWalleeDeviceCookie()
    {
        $cookieName = 'Wallee_device_id';

        $value = isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] !== ''
            ? $_COOKIE[$cookieName]
            : (string) WalleeModule::getUtilsObject()->generateUId();

        $expire = time() + 365 * 24 * 60 * 60;

        setcookie($cookieName, $value, $expire, '/');
        $_COOKIE[$cookieName] = $value;

        return $value;
    }
}
