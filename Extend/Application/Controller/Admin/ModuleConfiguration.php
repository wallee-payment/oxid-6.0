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


namespace Wle\Wallee\Extend\Application\Controller\Admin;

use Monolog\Logger;
use Wle\Wallee\Core\WalleeModule;
use Wle\Wallee\Core\Service\PaymentService;
use Wle\Wallee\Core\Webhook\Service as WebhookService;

/**
 * Class BasketItem.
 * Extends \OxidEsales\Eshop\Application\Controller\Admin\ModuleConfiguration.
 *
 * @mixin \OxidEsales\Eshop\Application\Controller\Admin\ModuleConfiguration
 */
class ModuleConfiguration extends ModuleConfiguration_parent
{

    public function init()
    {
        if ($this->getEditObjectId() == WalleeModule::instance()->getId() && $this->getFncName() !== 'saveConfVars') {
            // if plugin was inactive before and has settings changed (which we cannot interfere with as extensions are inactive) - force global parameters over current local settings.
            WalleeModule::settings()->setGlobalParameters($this->getConfig()->getBaseShopId());
        }
        $this->_ModuleConfiguration_init_parent();
    }

    protected function _ModuleConfiguration_init_parent()
    {
        parent::init();
    }

    public function saveConfVars()
    {
        $this->_ModuleConfiguration_saveConfVars_parent();
        if ($this->getEditObjectId() == WalleeModule::instance()->getId()) {
            try {
            	WalleeModule::settings()->setGlobalParameters();
            	WalleeModule::addMessage(WalleeModule::instance()->translate("Settings saved successfully."));
                // force api client refresh
                WalleeModule::instance()->getApiClient(true);

                $paymentService = new PaymentService();
                $paymentService->synchronize();
                WalleeModule::addMessage(WalleeModule::instance()->translate("Payment methods successfully synchronized."));

                $oldUrl = WalleeModule::settings()->getWebhookUrl();
                $newUrl = WalleeModule::instance()->createWebhookUrl();
                if ($oldUrl !== $newUrl) {
                    $webhookService = new WebhookService();
                    $webhookService->uninstall(WalleeModule::settings()->getSpaceId(), $oldUrl);;
                    $webhookService->install(WalleeModule::settings()->getSpaceId(), $newUrl);
                    WalleeModule::settings()->setWebhookUrl($newUrl);
                    WalleeModule::addMessage(WalleeModule::instance()->translate("Webhook URL updated successfully."));
                }
            } catch (\Exception $e) {
                WalleeModule::log(Logger::ERROR, "Unable to synchronize settings: {$e->getMessage()}.");
                WalleeModule::getUtilsView()->addErrorToDisplay($e->getMessage());
            }
        }
    }

    protected function _ModuleConfiguration_saveConfVars_parent()
    {
        parent::saveConfVars();
    }
}

