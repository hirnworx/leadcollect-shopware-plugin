<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Subscriber;

use MailCampaigns\AbandonedCart\Core\Checkout\AbandonedCart\Event\AfterCartMarkedAsAbandonedEvent;
use MailCampaigns\AbandonedCart\Core\Checkout\AbandonedCart\Event\AfterAbandonedCartUpdatedEvent;
use MailCampaigns\AbandonedCart\Service\LeadCollectWebhookService;
use MailCampaigns\AbandonedCart\Service\CouponService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to abandoned cart and order events to send webhooks to LeadCollect
 */
class LeadCollectWebhookSubscriber implements EventSubscriberInterface
{
    private LeadCollectWebhookService $webhookService;
    private LoggerInterface $logger;
    private EntityRepository $promotionRepository;
    private CouponService $couponService;

    public function __construct(
        LeadCollectWebhookService $webhookService,
        LoggerInterface $logger,
        EntityRepository $promotionRepository,
        CouponService $couponService
    ) {
        $this->webhookService = $webhookService;
        $this->logger = $logger;
        $this->promotionRepository = $promotionRepository;
        $this->couponService = $couponService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AfterCartMarkedAsAbandonedEvent::class => 'onCartAbandoned',
            AfterAbandonedCartUpdatedEvent::class => 'onAbandonedCartUpdated',
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    /**
     * Called when a cart is first marked as abandoned
     */
    public function onCartAbandoned(AfterCartMarkedAsAbandonedEvent $event): void
    {
        $abandonedCart = $event->getAbandonedCart();
        $cartData = $event->getCartData();
        $salesChannelId = null; // SalesChannelId not directly available on this entity
        
        $customerId = $abandonedCart->getCustomerId();
        $cartToken = $abandonedCart->getCartToken();

        $this->logger->info('LeadCollect: Cart abandoned event received', [
            'cartId' => $cartToken,
            'customerId' => $customerId,
        ]);

        // Generate coupon code for recovery
        $couponData = null;
        try {
            $couponData = $this->couponService->createCouponCode(
                $customerId,
                $cartToken,
                $salesChannelId
            );
            
            if ($couponData) {
                $this->logger->info('LeadCollect: Coupon created', [
                    'code' => $couponData['code'],
                    'value' => $couponData['value'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('LeadCollect: Could not create coupon', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $success = $this->webhookService->sendCartAbandonedWebhook(
                $abandonedCart,
                $cartData,
                $salesChannelId,
                $couponData
            );

            if ($success) {
                $this->logger->info('LeadCollect: Cart abandoned webhook sent successfully', [
                    'cartId' => $cartToken,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('LeadCollect: Failed to send cart abandoned webhook', [
                'cartId' => $cartToken,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Called when an abandoned cart is updated (e.g., customer adds more items)
     */
    public function onAbandonedCartUpdated(AfterAbandonedCartUpdatedEvent $event): void
    {
        // Optional: Send update webhook if cart value changed significantly
        // For now, we skip updates to avoid webhook spam
    }

    /**
     * Called when an order is placed - for recovery tracking
     */
    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $salesChannelId = $event->getSalesChannelId();

        // Check if order has a promotion code (potential recovery)
        $couponCode = $this->extractCouponCode($order);
        
        // Get customer email
        $customer = $order->getOrderCustomer();
        $customerEmail = $customer?->getEmail();
        $customerId = $customer?->getCustomerId();

        if (!$customerId) {
            return; // Guest order without tracking
        }

        $this->logger->info('LeadCollect: Order placed event received', [
            'orderId' => $order->getId(),
            'customerId' => $customerId,
            'hasCoupon' => !empty($couponCode),
        ]);

        try {
            // Calculate order value
            $orderValue = $order->getAmountTotal();

            $success = $this->webhookService->sendOrderPlacedWebhook(
                $order->getId(),
                $orderValue,
                $couponCode,
                $customerId,
                $customerEmail,
                $salesChannelId
            );

            if ($success) {
                $this->logger->info('LeadCollect: Order placed webhook sent successfully', [
                    'orderId' => $order->getId(),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('LeadCollect: Failed to send order placed webhook', [
                'orderId' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract coupon/promotion code from order
     */
    private function extractCouponCode(OrderEntity $order): ?string
    {
        $lineItems = $order->getLineItems();
        
        if (!$lineItems) {
            return null;
        }

        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() === 'promotion') {
                $payload = $lineItem->getPayload();
                
                // Try to get the promotion code
                if (isset($payload['code'])) {
                    return $payload['code'];
                }
                
                // Fallback: get promotion ID and look up code
                if (isset($payload['promotionId'])) {
                    return $this->getPromotionCode($payload['promotionId']);
                }
            }
        }

        return null;
    }

    /**
     * Look up promotion code by ID
     */
    private function getPromotionCode(string $promotionId): ?string
    {
        try {
            $criteria = new Criteria([$promotionId]);
            $criteria->addAssociation('discounts');
            
            $promotion = $this->promotionRepository->search($criteria, \Shopware\Core\Framework\Context::createDefaultContext())->first();
            
            if ($promotion) {
                return $promotion->getCode();
            }
        } catch (\Exception $e) {
            $this->logger->warning('LeadCollect: Could not look up promotion code', [
                'promotionId' => $promotionId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
