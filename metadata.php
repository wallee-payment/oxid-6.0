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


/**
 * Metadata version
 */
$sMetadataVersion = '2.0';

/**
 * Module information
 */
$aModule = array(
    'id' => 'wleWallee',
    'title' => array(
        'de' => 'WLE :: Wallee',
        'en' => 'WLE :: Wallee'
    ),
    'description' => array(
        'de' => 'WLE Wallee Module',
        'en' => 'WLE Wallee Module'
    ),
    'thumbnail' => 'out/pictures/picture.png',
    'version' => '1.0.48',
    'author' => 'customweb GmbH',
    'url' => 'https://www.customweb.com',
    'email' => 'info@customweb.com',
    'extend' => array(
        \OxidEsales\Eshop\Application\Model\Order::class => Wle\Wallee\Extend\Application\Model\Order::class,
        \OxidEsales\Eshop\Application\Model\PaymentList::class => Wle\Wallee\Extend\Application\Model\PaymentList::class,
        \OxidEsales\Eshop\Application\Model\BasketItem::class => Wle\Wallee\Extend\Application\Model\BasketItem::class,
        \OxidEsales\Eshop\Application\Controller\StartController::class => Wle\Wallee\Extend\Application\Controller\StartController::class,
        \OxidEsales\Eshop\Application\Controller\BasketController::class => Wle\Wallee\Extend\Application\Controller\BasketController::class,
        \OxidEsales\Eshop\Application\Controller\OrderController::class => Wle\Wallee\Extend\Application\Controller\OrderController::class,
        \OxidEsales\Eshop\Application\Controller\Admin\LoginController::class => Wle\Wallee\Extend\Application\Controller\Admin\LoginController::class,
        \OxidEsales\Eshop\Application\Controller\Admin\ModuleConfiguration::class => Wle\Wallee\Extend\Application\Controller\Admin\ModuleConfiguration::class,
        \OxidEsales\Eshop\Application\Controller\Admin\NavigationController::class => Wle\Wallee\Extend\Application\Controller\Admin\NavigationController::class,
        \OxidEsales\Eshop\Application\Controller\Admin\OrderList::class => Wle\Wallee\Extend\Application\Controller\Admin\OrderList::class,
    ),
    'controllers' => array(
        'wle_wallee_RefundJob' => Wle\Wallee\Application\Controller\Admin\RefundJob::class,
        'wle_wallee_Cron' => Wle\Wallee\Application\Controller\Cron::class,
        'wle_wallee_Pdf' => Wle\Wallee\Application\Controller\Pdf::class,
        'wle_wallee_Webhook' => Wle\Wallee\Application\Controller\Webhook::class,
        'wle_wallee_Transaction' => Wle\Wallee\Application\Controller\Admin\Transaction::class,
        'wle_wallee_Alert' => Wle\Wallee\Application\Controller\Admin\Alert::class
    ),
    'templates' => array(
        'wleWalleeCheckoutBasket.tpl' => 'wle/Wallee/Application/views/pages/wleWalleeCheckoutBasket.tpl',
        'wleWalleeCron.tpl' => 'wle/Wallee/Application/views/pages/wleWalleeCron.tpl',
        'wleWalleeError.tpl' => 'wle/Wallee/Application/views/pages/wleWalleeError.tpl',
        'wleWalleeTransaction.tpl' => 'wle/Wallee/Application/views/admin/tpl/wleWalleeTransaction.tpl',
        'wleWalleeRefundJob.tpl' => 'wle/Wallee/Application/views/admin/tpl/wleWalleeRefundJob.tpl',
        'wleWalleeOrderList.tpl' => 'wle/Wallee/Application/views/admin/tpl/wleWalleeOrderList.tpl',
    ),
    'blocks' => array(
        array(
            'template' => 'page/checkout/order.tpl',
            'block' => 'shippingAndPayment',
            'file' => 'Application/views/blocks/wleWallee_checkout_order_shippingAndPayment.tpl'
        ),
        array(
            'template' => 'page/checkout/order.tpl',
            'block' => 'checkout_order_btn_submit_bottom',
            'file' => 'Application/views/blocks/wleWallee_checkout_order_btn_submit_bottom.tpl'
        ),
        array(
            'template' => 'layout/base.tpl',
            'block' => 'base_js',
            'file' => 'Application/views/blocks/wleWallee_include_cron.tpl'
        ),
        array(
            'template' => 'login.tpl',
            'block' => 'admin_login_form',
            'file' => 'Application/views/blocks/wleWallee_include_cron.tpl'
        ),
    	array(
    		'template' => 'header.tpl',
    		'block' => 'admin_header_links',
    		'file' => 'Application/views/blocks/wleWallee_admin_header_links.tpl'
    	),
    	array(
    		'template' => 'page/account/order.tpl',
    		'block' => 'account_order_history',
    		'file' => 'Application/views/blocks/wleWallee_account_order_history.tpl'
    	),
    ),
	'settings' => array(
		array(
			'group' => 'wleWalleewalleeSettings',
			'name' => 'wleWalleeSpaceId',
			'type' => 'str',
			'value' => ''
		),
		array(
			'group' => 'wleWalleewalleeSettings',
			'name' => 'wleWalleeUserId',
			'type' => 'str',
			'value' => ''
		),
		array(
			'group' => 'wleWalleewalleeSettings',
			'name' => 'wleWalleeAppKey',
			'type' => 'password',
			'value' => ''
		),
		array(
			'group' => 'wleWalleeShopSettings',
			'name' => 'wleWalleeEmailConfirm',
			'type' => 'bool',
			'value' => true
		),
		array(
			'group' => 'wleWalleeShopSettings',
			'name' => 'wleWalleeEnforceConsistency',
			'type' => 'bool',
			'value' => true
		),
		array(
			'group' => 'wleWalleeShopSettings',
			'name' => 'wleWalleeInvoiceDoc',
			'type' => 'bool',
			'value' => true
		),
		array(
			'group' => 'wleWalleeShopSettings',
			'name' => 'wleWalleePackingDoc',
			'type' => 'bool',
			'value' => true
		),
		array(
			'group' => 'wleWalleeShopSettings',
			'name' => 'wleWalleeLogLevel',
			'type' => 'select',
			'value' => 'Error',
			'constraints' => 'Error|Info|Debug'
		),
		array(
			'group' => 'wleWalleeSpaceViewSettings',
			'name' => 'wleWalleeSpaceViewId',
			'type' => 'str',
			'value' => ''
		),
        array(
            'group' => 'wleWalleeShopSettings',
			'name' => 'wleWalleeMigration',
			'type' => 'num',
			'value' => 0,
        )
    ),
    'events' => array(
        'onActivate' => Wle\Wallee\Core\WalleeModule::class . '::onActivate',
        'onDeactivate' => Wle\Wallee\Core\WalleeModule::class . '::onDeactivate'
    )
);