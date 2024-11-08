<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSnsCallbackBundle;

use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;

class AmazonSnsCallbackBundle extends AbstractPluginBundle
{
    public const SUPPORTED_MAILER_SCHEMES = [
        'ses+smtp',
        'ses+api',
        'ses+https',
    ];
}
