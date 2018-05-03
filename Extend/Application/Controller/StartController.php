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

use Wle\Wallee\Application\Controller\Cron;


/**
 * Class used to include tracking device id on basket.
 *
 * Class BasketController.
 * Extends \OxidEsales\Eshop\Application\Controller\StartController.
 *
 * @mixin \OxidEsales\Eshop\Application\Controller\StartController
 */
class StartController extends StartController_parent
{
    public function render()
    {
        $this->_aViewData['wleCronUrl'] = Cron::getCronUrl();
        return $this->_StartController_render_parent();
    }

    protected function _StartController_render_parent()
    {
        return parent::render();
    }
}