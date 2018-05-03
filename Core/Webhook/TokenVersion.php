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
namespace Wle\Wallee\Core\Webhook;

use Wle\Wallee\Core\Service\Token as TokenService;

/**
 * Webhook processor to handle token version state transitions.
 */
class TokenVersion extends AbstractWebhook
{

    public function process(Request $request)
    {
        TokenService::instance()->updateTokenVersion($request->getSpaceId(), $request->getEntityId());
    }
}