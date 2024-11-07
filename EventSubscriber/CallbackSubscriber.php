<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSnsCallbackBundle\EventSubscriber;

use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper
    ) {
    }
}
