<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSnsCallbackBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\AmazonSnsCallbackBundle\AmazonSnsCallbackBundle;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CallbackSubscriber implements EventSubscriberInterface
{
    private const TYPE_SUBSCRIPTION_CONFIRMATION = 'SubscriptionConfirmation';
    private const TYPE_BOUNCE                    = 'Bounce';
    private const TYPE_COMPLAINT                 = 'Complaint';
    private const TYPE_NOTIFICATION              = 'Notification';
    private const TYPE_DELIVERY                  = 'Delivery';

    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => 'processCallbackRequest',
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));

        if (!in_array($dsn->getScheme(), AmazonSnsCallbackBundle::SUPPORTED_MAILER_SCHEMES, true)) {
            return;
        }

        $payload = json_decode($event->getRequest()->getContent(), true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $event->setResponse(new Response('Invalid JSON', Response::HTTP_BAD_REQUEST));

            return;
        }

        try {
            $this->processPayload($payload);
            $event->setResponse(new Response('Amazon SNS Callback processed'));
        } catch (\Exception $e) {
            $this->logger->error('Failed to process payload: '.$e->getMessage());
            $event->setResponse(new Response('Bad Request', Response::HTTP_BAD_REQUEST));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processPayload(array $payload): void
    {
        $type = $payload['Type'] ?? $payload['eventType'] ?? $payload['notificationType'] ?? null;

        switch ($type) {
            case self::TYPE_SUBSCRIPTION_CONFIRMATION:
                $this->processSubscriptionConfirmation($payload);
                break;
            case self::TYPE_BOUNCE:
                $this->processBounce($payload);
                break;
            case self::TYPE_COMPLAINT:
                $this->processComplaint($payload);
                break;
            case self::TYPE_NOTIFICATION:
                $this->processNotification($payload);
                break;
            case self::TYPE_DELIVERY:
                // Nothing to do
                break;
            default:
                throw new \InvalidArgumentException('Unsupported message type: '.($type ?? 'null'));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processSubscriptionConfirmation(array $payload): void
    {
        if (!isset($payload['SubscribeURL'])) {
            throw new \InvalidArgumentException('SubscribeURL is missing in the payload.');
        }

        if (!filter_var($payload['SubscribeURL'], FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid SubscribeURL provided in the payload.');
        }

        try {
            $response = $this->httpClient->request('GET', $payload['SubscribeURL']);

            if (Response::HTTP_OK === $response->getStatusCode()) {
                $this->logger->info('Subscription confirmed successfully.');
            } else {
                $this->logger->warning('Failed to confirm subscription. Status code: '.$response->getStatusCode());
            }
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('HTTP request to SubscribeURL failed: '.$e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processBounce(array $payload): void
    {
        if ('Permanent' !== $payload['bounce']['bounceType']) {
            return;
        }

        $emailId = $this->getEmailId($payload);

        $bouncedRecipients = $payload['bounce']['bouncedRecipients'] ?? [];

        foreach ($bouncedRecipients as $bouncedRecipient) {
            $address = Address::create($bouncedRecipient['emailAddress']);

            $bounceType     = $payload['bounce']['bounceType'] ?? 'unknown';
            $bounceSubType  = $payload['bounce']['bounceSubType'] ?? 'unknown';
            $diagnosticCode = $bouncedRecipient['diagnosticCode'] ?? 'unknown';

            $reason = sprintf(
                '%s - %s: %s',
                ucfirst($bounceType),
                ucfirst($bounceSubType),
                $diagnosticCode
            );

            $this->transportCallback->addFailureByAddress(
                $address->getAddress(),
                $reason,
                DoNotContact::BOUNCED,
                '' !== $emailId ? (int) $emailId : null
            );

            $this->logger->info("Processed bounce for {$address->getAddress()} with reason: {$reason}");
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processComplaint(array $payload): void
    {
        $emailId = $this->getEmailId($payload);

        $complainedRecipients = $payload['complaint']['complainedRecipients'] ?? [];

        foreach ($complainedRecipients as $complainedRecipient) {
            $address = Address::create($complainedRecipient['emailAddress']);

            $reason = $payload['complaint']['complaintFeedbackType'] ?? 'unknown';

            $this->transportCallback->addFailureByAddress(
                $address->getAddress(),
                $reason,
                DoNotContact::UNSUBSCRIBED,
                '' !== $emailId ? (int) $emailId : null
            );

            $this->logger->info("Processed complaint for {$address->getAddress()} with reason: {$reason}");
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processNotification(array $payload): void
    {
        if (isset($payload['Message'])) {
            $innerPayload = json_decode($payload['Message'], true);

            if (JSON_ERROR_NONE === json_last_error() && is_array($innerPayload)) {
                $this->processPayload($innerPayload);

                return;
            } else {
                $this->logger->warning('Invalid inner JSON in Message field of TYPE_NOTIFICATION.');
            }
        }
        $this->logger->info('Received TYPE_NOTIFICATION without inner message or with unsupported content.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function getEmailId(array $payload): ?string
    {
        $headers = $payload['mail']['headers'] ?? [];

        foreach ($headers as $header) {
            if (isset($header['name']) && 'X-EMAIL-ID' === strtoupper($header['name'])) {
                return $header['value'];
            }
        }

        return null;
    }
}
