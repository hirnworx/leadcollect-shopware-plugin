<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Subscriber;

use Doctrine\DBAL\Connection;
use MailCampaigns\AbandonedCart\Core\Checkout\AbandonedCart\Event\AfterCartMarkedAsAbandonedEvent;
use MailCampaigns\AbandonedCart\Core\Checkout\AbandonedCart\Event\AfterAbandonedCartUpdatedEvent;
use MailCampaigns\AbandonedCart\Service\LeadCollectWebhookService;
use MailCampaigns\AbandonedCart\Service\CouponService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class LeadCollectWebhookSubscriber implements EventSubscriberInterface
{
    private LeadCollectWebhookService $webhookService;
    private CouponService $couponService;
    private EntityRepository $customerRepository;
    private Connection $connection;
    private CartService $cartService;
    private RequestStack $requestStack;

    public function __construct(
        LeadCollectWebhookService $webhookService,
        CouponService $couponService,
        EntityRepository $customerRepository,
        Connection $connection,
        CartService $cartService,
        RequestStack $requestStack
    ) {
        $this->webhookService = $webhookService;
        $this->couponService = $couponService;
        $this->customerRepository = $customerRepository;
        $this->connection = $connection;
        $this->cartService = $cartService;
        $this->requestStack = $requestStack;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AfterCartMarkedAsAbandonedEvent::class => 'onCartAbandoned',
            AfterAbandonedCartUpdatedEvent::class => 'onAbandonedCartUpdated',
            CheckoutOrderPlacedEvent::class => ['onOrderPlaced', 5],
            StorefrontRenderEvent::class => ['onStorefrontRender', 100],
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $request = $event->getRequest();
        $restoreCode = $request->query->get('lc_restore');
        
        if (!$restoreCode) {
            return;
        }

        $route = $request->attributes->get('_route', '');
        if ($route !== 'frontend.checkout.cart.page') {
            return;
        }

        $session = $request->getSession();
        if ($session->get('lc_restored_' . $restoreCode)) {
            return;
        }

        try {
            $abandonedCarts = $this->connection->fetchAllAssociative(
                'SELECT * FROM mailcampaigns_abandoned_cart WHERE line_items IS NOT NULL ORDER BY created_at DESC LIMIT 10'
            );

            $abandonedCart = null;
            foreach ($abandonedCarts as $cart) {
                $items = json_decode($cart['line_items'] ?? '', true);
                if (!empty($items)) {
                    $abandonedCart = $cart;
                    break;
                }
            }

            if (!$abandonedCart) {
                return;
            }

            $lineItems = json_decode($abandonedCart['line_items'], true);
            $context = $event->getSalesChannelContext();
            $cart = $this->cartService->getCart($context->getToken(), $context);

            foreach ($lineItems as $item) {
                if (!isset($item['type']) || $item['type'] !== 'product') {
                    continue;
                }

                $productId = $item['referencedId'] ?? $item['id'] ?? $item['productId'] ?? null;
                $quantity = (int)($item['quantity'] ?? 1);

                if (!$productId) {
                    continue;
                }

                try {
                    $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, $productId, $quantity);
                    $lineItem->setStackable(true);
                    $lineItem->setRemovable(true);
                    $cart = $this->cartService->add($cart, $lineItem, $context);
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $session->set('lc_restored_' . $restoreCode, true);
        } catch (\Throwable $e) {}
    }

    public function onCartAbandoned(AfterCartMarkedAsAbandonedEvent $event): void
    {
        $abandonedCart = $event->getAbandonedCart();
        $cartData = $event->getCartData();
        $couponData = null;
        
        try {
            $couponData = $this->couponService->createCouponCode($abandonedCart->getCustomerId(), $abandonedCart->getCartToken(), null);
        } catch (\Throwable $e) {}

        try {
            $this->webhookService->sendCartAbandonedWebhook($abandonedCart, $cartData, $couponData);
        } catch (\Throwable $e) {}
    }

    public function onAbandonedCartUpdated(AfterAbandonedCartUpdatedEvent $event): void {}

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        try {
            $this->webhookService->sendOrderPlacedWebhook($event->getOrder());
        } catch (\Throwable $e) {}
    }
}
