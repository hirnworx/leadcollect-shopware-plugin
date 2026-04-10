<?php declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class CartRestoreSubscriber implements EventSubscriberInterface
{
    private Connection $connection;
    private CartService $cartService;
    private RequestStack $requestStack;

    public function __construct(
        Connection $connection,
        CartService $cartService,
        RequestStack $requestStack
    ) {
        $this->connection = $connection;
        $this->cartService = $cartService;
        $this->requestStack = $requestStack;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onCartPageLoaded',
        ];
    }

    public function onCartPageLoaded(CheckoutCartPageLoadedEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        // Check for our restore parameter
        $restoreCode = $request->query->get('lc_restore');
        if (!$restoreCode) {
            return;
        }

        // Prevent multiple restores in same session
        $session = $request->getSession();
        if ($session->get('lc_restored_' . $restoreCode)) {
            return;
        }

        try {
            // Find the abandoned cart by coupon code
            $abandonedCart = $this->connection->fetchAssociative(
                'SELECT * FROM mailcampaigns_abandoned_cart WHERE id IN (
                    SELECT UNHEX(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.abandonedCartId")), "-", ""))
                    FROM promotion_individual_code 
                    WHERE code = :code
                ) OR cart_token = :code
                LIMIT 1',
                ['code' => $restoreCode]
            );

            // Fallback: search by individual code directly in our tracking
            if (!$abandonedCart) {
                // Try to find via promotion code
                $promoData = $this->connection->fetchAssociative(
                    'SELECT payload FROM promotion_individual_code WHERE code = :code LIMIT 1',
                    ['code' => $restoreCode]
                );
                
                if ($promoData && isset($promoData['payload'])) {
                    $payload = json_decode($promoData['payload'], true);
                    if (isset($payload['cartToken'])) {
                        $abandonedCart = $this->connection->fetchAssociative(
                            'SELECT * FROM mailcampaigns_abandoned_cart WHERE cart_token = :token LIMIT 1',
                            ['token' => $payload['cartToken']]
                        );
                    }
                }
            }

            if (!$abandonedCart) {
                return;
            }

            // Get line items from the abandoned cart
            $lineItems = [];
            if (isset($abandonedCart['line_items'])) {
                $lineItems = json_decode($abandonedCart['line_items'], true) ?: [];
            }

            if (empty($lineItems)) {
                return;
            }

            $context = $event->getSalesChannelContext();
            $cart = $this->cartService->getCart($context->getToken(), $context);

            // Add products to cart
            foreach ($lineItems as $item) {
                // Skip non-product items
                if (!isset($item['type']) || $item['type'] !== 'product') {
                    continue;
                }

                $productId = $item['referencedId'] ?? $item['id'] ?? null;
                $quantity = (int)($item['quantity'] ?? 1);

                if (!$productId) {
                    continue;
                }

                try {
                    $lineItem = new LineItem(
                        Uuid::randomHex(),
                        LineItem::PRODUCT_LINE_ITEM_TYPE,
                        $productId,
                        $quantity
                    );
                    $lineItem->setStackable(true);
                    $lineItem->setRemovable(true);

                    $cart = $this->cartService->add($cart, $lineItem, $context);
                } catch (\Throwable $e) {
                    // Product might not exist anymore, skip it
                    continue;
                }
            }

            // Mark as restored in session
            $session->set('lc_restored_' . $restoreCode, true);

        } catch (\Throwable $e) {
            // Silent fail - don't break the page
        }
    }
}
