<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSnsCallbackBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\AmazonSnsCallbackBundle\EventSubscriber\CallbackSubscriber;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CallbackSubscriberTest extends MauticMysqlTestCase
{
    protected function setUp(): void
    {
        $this->configParams['mailer_dsn'] = 'ses+api://access-key:secret-key@default';

        parent::setUp();
    }

    public function testAmazonSnsCallbackProcessBounce(): void
    {
        $payload = $this->getPayloadForBounce();

        $contact = $this->createContact('john.doe@example.com');
        $this->em->flush();

        $now          = new \DateTime();
        $nowFormatted = $now->format(DateTimeHelper::FORMAT_DB);

        $this->client->request(
            Request::METHOD_POST,
            '/mailer/callback',
            content: json_encode($payload),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        $response = $this->client->getResponse();
        Assert::assertSame('Amazon SNS Callback processed', $response->getContent());
        Assert::assertSame(200, $response->getStatusCode());

        $dnc = $contact->getDoNotContact()->current();
        Assert::assertSame('email', $dnc->getChannel());
        Assert::assertSame('Permanent - General: smtp; 550 5.1.1 user unknown', $dnc->getComments());

        // Assert::assertSame($nowFormatted, $dnc->getDateAdded()->format(DateTimeHelper::FORMAT_DB));
        $this->assertDateTimeWithinSeconds($now, $dnc->getDateAdded(), 10);

        Assert::assertSame($contact, $dnc->getLead());
        Assert::assertSame(DoNotContact::BOUNCED, $dnc->getReason());
    }

    public function testAmazonSnsCallbackProcessComplaint(): void
    {
        $payload = $this->getPayloadForComplaint();

        $contact = $this->createContact('john.doe@example.com');
        $this->em->flush();

        $now          = new \DateTime();
        $nowFormatted = $now->format(DateTimeHelper::FORMAT_DB);

        $this->client->request(
            Request::METHOD_POST,
            '/mailer/callback',
            content: json_encode($payload),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        $response = $this->client->getResponse();
        Assert::assertSame('Amazon SNS Callback processed', $response->getContent());
        Assert::assertSame(200, $response->getStatusCode());

        $dnc = $contact->getDoNotContact()->current();
        Assert::assertSame('email', $dnc->getChannel());
        Assert::assertSame('abuse', $dnc->getComments());

        // Assert::assertSame($nowFormatted, $dnc->getDateAdded()->format(DateTimeHelper::FORMAT_DB));
        $this->assertDateTimeWithinSeconds($now, $dnc->getDateAdded(), 10);

        Assert::assertSame($contact, $dnc->getLead());
        Assert::assertSame(DoNotContact::UNSUBSCRIBED, $dnc->getReason());
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        $this->em->persist($lead);

        return $lead;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayloadForBounce(): array
    {
        return [
            'Type'      => 'Notification',
            'MessageId' => 'mautic',
            'TopicArn'  => 'mautic',
            'Message'   => json_encode([
                'notificationType' => 'Bounce',
                'bounce'           => [
                    'feedbackId'        => 'mautic',
                    'bounceType'        => 'Permanent',
                    'bounceSubType'     => 'General',
                    'bouncedRecipients' => [
                        [
                            'emailAddress'   => 'john.doe@example.com',
                            'action'         => 'failed',
                            'status'         => '5.1.1',
                            'diagnosticCode' => 'smtp; 550 5.1.1 user unknown',
                        ],
                    ],
                    'timestamp'    => '2024-11-08T15:03:53.000Z',
                    'remoteMtaIp'  => '127.0.0.1',
                    'reportingMTA' => 'dns; b224-5.smtp-out.eu-central-1.amazonses.com',
                ],
                'mail' => [
                    'timestamp'        => '2024-11-08T15:03:52.994Z',
                    'source'           => 'Mautic <mautic@example.com>',
                    'sourceArn'        => 'mautic',
                    'sourceIp'         => '127.0.0.1',
                    'callerIdentity'   => 'mautic',
                    'sendingAccountId' => '961318160816',
                    'messageId'        => 'mautic',
                    'destination'      => [
                        'john.doe@example.com',
                    ],
                ],
            ]),
            'Timestamp'        => '2024-11-08T15:03:53.585Z',
            'SignatureVersion' => '1',
            'Signature'        => 'mautic',
            'SigningCertURL'   => 'mautic',
            'UnsubscribeURL'   => 'mautic',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayloadForComplaint(): array
    {
        return [
            'Type'      => 'Notification',
            'MessageId' => 'mautic',
            'TopicArn'  => 'mautic',
            'Message'   => json_encode([
                'notificationType' => 'Complaint',
                'complaint'        => [
                    'feedbackId'           => 'mautic',
                    'complaintSubType'     => null,
                    'complainedRecipients' => [
                        [
                            'emailAddress' => 'john.doe@example.com',
                        ],
                    ],
                    'timestamp'             => '2024-11-08T15:04:32.000Z',
                    'userAgent'             => 'mautic',
                    'complaintFeedbackType' => 'abuse',
                    'arrivalDate'           => '2024-11-08T15:04:32.953Z',
                ],
                'mail' => [
                    'timestamp'        => '2024-11-08T15:04:29.828Z',
                    'source'           => 'Mautic <mautic@example.com>',
                    'sourceArn'        => 'mautic',
                    'sourceIp'         => '127.0.0.1',
                    'callerIdentity'   => 'mautic',
                    'sendingAccountId' => 'mautic',
                    'messageId'        => 'mautic',
                    'destination'      => [
                        'john.doe@example.com',
                    ],
                ],
            ]),
            'Timestamp'        => '2024-11-08T15:04:32.969Z',
            'SignatureVersion' => '1',
            'Signature'        => 'mautic',
            'SigningCertURL'   => 'mautic',
            'UnsubscribeURL'   => 'mautic',
        ];
    }

    private function assertDateTimeWithinSeconds(\DateTimeInterface $expected, \DateTimeInterface $actual, int $deltaSeconds = 5): void
    {
        $diff = abs($actual->getTimestamp() - $expected->getTimestamp());
        Assert::assertLessThanOrEqual(
            $deltaSeconds,
            $diff,
            sprintf(
                'Expected datetime within %d seconds. Expected=%s, Actual=%s, Diff=%ds',
                $deltaSeconds,
                $expected->format(\DateTimeInterface::ATOM),
                $actual->format(\DateTimeInterface::ATOM),
                $diff
            )
        );
    }

    public function testSubscriptionConfirmationRejectsInvalidSnsHost(): void
    {
        $transportCallback = $this->createMock(TransportCallback::class);
        $coreParams        = $this->createMock(CoreParametersHelper::class);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::never())
            ->method('request');

        $logger = $this->createMock(LoggerInterface::class);

        $subscriber = new CallbackSubscriber(
            $transportCallback,
            $coreParams,
            $httpClient,
            $logger
        );

        $payload = [
            'Type'         => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.random.amazonaws.com.example.com/?x=1',
        ];

        $method = new \ReflectionMethod($subscriber, 'processSubscriptionConfirmation');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SubscribeURL host is not an allowed SNS endpoint.');

        $method->invoke($subscriber, $payload);
    }

    public function testSubscriptionConfirmationDoesNotRejectValidSnsHost(): void
    {
        $transportCallback = $this->createMock(TransportCallback::class);
        $coreParams        = $this->createMock(CoreParametersHelper::class);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->method('request')
            ->willThrowException(new \RuntimeException('stop here'));

        $logger = $this->createMock(LoggerInterface::class);

        $subscriber = new CallbackSubscriber(
            $transportCallback,
            $coreParams,
            $httpClient,
            $logger
        );

        $payload = [
            'Type'         => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/valid',
        ];

        $method = new \ReflectionMethod($subscriber, 'processSubscriptionConfirmation');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stop here');

        $method->invoke($subscriber, $payload);
    }
}
