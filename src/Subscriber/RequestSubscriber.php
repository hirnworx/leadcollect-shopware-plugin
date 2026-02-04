<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RequestSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        // Write to file for debugging
        file_put_contents('/tmp/request_test.log', date('Y-m-d H:i:s') . " Request received\n", FILE_APPEND);
    }
}
