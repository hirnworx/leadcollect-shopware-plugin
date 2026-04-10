<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Controller;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

class CartRestoreController extends StorefrontController
{
    private Connection $connection;
    private CartService $cartService;
    private ?LoggerInterface $logger;

    public function __construct(
        Connection $connection,
        CartService $cartService,
        ?LoggerInterface $logger = null
    ) {
        $this->connection = $connection;
        $this->cartService = $cartService;
        $this->logger = $logger;
    }

    /**
     * Restore an abandoned cart and apply coupon code
     * URL: /leadcollect-restore?token={cartToken}&coupon={couponCode}
     */
    public function restore(Request $request, SalesChannelContext $context): Response
    {
        $cartToken = $request->query->get('token');
        $couponCode = $request->query->get('coupon');

        $this->log('info', 'LeadCollect cart restore requested', [
            'cartToken' => $cartToken,
            'couponCode' => $couponCode
        ]);

        try {
            // Try to restore the cart if token is provided
            if ($cartToken) {
                $restored = $this->restoreCartFromToken($cartToken, $context);
                if ($restored) {
                    $this->log('info', 'Cart restored successfully', ['cartToken' => $cartToken]);
                } else {
                    $this->log('warning', 'Could not restore cart', ['cartToken' => $cartToken]);
                }
            }

            // Apply coupon code if provided
            if ($couponCode) {
                $this->applyCouponCode($couponCode, $context);
                $this->log('info', 'Coupon code applied', ['couponCode' => $couponCode]);
            }

            // Track the restore event
            $this->trackRestoreEvent($cartToken, $couponCode);

            // Redirect to checkout/cart page
            return new RedirectResponse($this->generateUrl('frontend.checkout.cart.page'));

        } catch (\Exception $e) {
            $this->log('error', 'Cart restore failed', [
                'error' => $e->getMessage(),
                'cartToken' => $cartToken
            ]);

            // Still redirect to cart, even if restore failed
            return new RedirectResponse($this->generateUrl('frontend.checkout.cart.page'));
        }
    }

    /**
     * Restore cart from SKU-based URL (used by QR codes on postcards)
     * URL: /leadcollect/restore?sku=SKU1,SKU2&q=QTY1,QTY2&c=COUPONCODE
     */
    public function restoreBySku(Request $request, SalesChannelContext $context): Response
    {
        $skus = $request->query->get('sku', '');
        $quantities = $request->query->get('q', '');
        $couponCode = $request->query->get('c');

        $this->log('info', 'LeadCollect SKU-based cart restore requested', [
            'skus' => $skus,
            'quantities' => $quantities,
            'couponCode' => $couponCode
        ]);

        try {
            // Parse SKUs and quantities
            $skuArray = array_filter(explode(',', $skus));
            $qtyArray = array_filter(explode(',', $quantities));

            if (!empty($skuArray)) {
                $this->restoreCartFromSkus($skuArray, $qtyArray, $context);
            }

            // Apply coupon code if provided
            if ($couponCode) {
                $this->applyCouponCode($couponCode, $context);
                $this->log('info', 'Coupon code applied', ['couponCode' => $couponCode]);
            }

            // Redirect to checkout/cart page
            return new RedirectResponse($this->generateUrl('frontend.checkout.cart.page'));

        } catch (\Exception $e) {
            $this->log('error', 'SKU-based cart restore failed', [
                'error' => $e->getMessage(),
                'skus' => $skus
            ]);

            // Still redirect to cart, even if restore failed
            return new RedirectResponse($this->generateUrl('frontend.checkout.cart.page'));
        }
    }

    /**
     * Restore cart by adding products based on SKU (product number)
     */
    private function restoreCartFromSkus(array $skus, array $quantities, SalesChannelContext $context): void
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);

        foreach ($skus as $index => $sku) {
            $sku = trim($sku);
            if (empty($sku)) {
                continue;
            }

            $quantity = isset($quantities[$index]) ? (int)$quantities[$index] : 1;
            if ($quantity < 1) {
                $quantity = 1;
            }

            try {
                // Look up product by product number (SKU)
                $productId = $this->getProductIdBySku($sku, $context->getSalesChannel()->getId());
                
                if ($productId) {
                    $lineItem = new LineItem(
                        $productId,
                        LineItem::PRODUCT_LINE_ITEM_TYPE,
                        $productId,
                        $quantity
                    );
                    $lineItem->setStackable(true);
                    $lineItem->setRemovable(true);

                    $this->cartService->add($cart, $lineItem, $context);
                    
                    $this->log('info', 'Product added to cart', [
                        'sku' => $sku,
                        'productId' => $productId,
                        'quantity' => $quantity
                    ]);
                } else {
                    $this->log('warning', 'Product not found by SKU', ['sku' => $sku]);
                }
            } catch (\Exception $e) {
                $this->log('warning', 'Could not add product to cart', [
                    'sku' => $sku,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get product ID by SKU (product number)
     */
    private function getProductIdBySku(string $sku, string $salesChannelId): ?string
    {
        try {
            // Query product by product_number
            $sql = "
                SELECT LOWER(HEX(p.id)) as id
                FROM product p
                INNER JOIN product_visibility pv ON p.id = pv.product_id
                WHERE p.product_number = :sku
                  AND pv.sales_channel_id = UNHEX(REPLACE(:salesChannelId, '-', ''))
                  AND p.active = 1
                LIMIT 1
            ";
            
            $result = $this->connection->fetchAssociative($sql, [
                'sku' => $sku,
                'salesChannelId' => $salesChannelId
            ]);
            
            return $result['id'] ?? null;
        } catch (\Exception $e) {
            $this->log('error', 'Error looking up product by SKU', [
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Restore cart from the abandoned cart token
     */
    private function restoreCartFromToken(string $cartToken, SalesChannelContext $context): bool
    {
        try {
            // Find the abandoned cart data
            $abandonedCart = $this->connection->fetchAssociative(
                'SELECT * FROM abandoned_cart WHERE cart_token = :token ORDER BY created_at DESC LIMIT 1',
                ['token' => $cartToken]
            );

            if (!$abandonedCart) {
                // Try to find the original cart
                $originalCart = $this->connection->fetchAssociative(
                    'SELECT * FROM cart WHERE token = :token',
                    ['token' => $cartToken]
                );

                if (!$originalCart) {
                    return false;
                }

                // Cart exists, customer can continue shopping
                return true;
            }

            // Get cart data from abandoned cart
            $cartData = $abandonedCart['cart'] ?? null;
            if (!$cartData) {
                return false;
            }

            // Decode cart data
            $cartPayload = @unserialize($cartData);
            if (!$cartPayload) {
                $cartPayload = @json_decode($cartData, true);
            }

            if (!$cartPayload || !is_array($cartPayload)) {
                return false;
            }

            // Get current cart and add items from abandoned cart
            $cart = $this->cartService->getCart($context->getToken(), $context);

            // Try to restore line items
            $lineItems = $cartPayload['lineItems'] ?? [];
            if (is_array($lineItems)) {
                foreach ($lineItems as $lineItem) {
                    try {
                        // Only restore product items
                        $type = $lineItem['type'] ?? '';
                        if ($type === 'product') {
                            $productId = $lineItem['referencedId'] ?? $lineItem['id'] ?? null;
                            $quantity = (int)($lineItem['quantity'] ?? 1);

                            if ($productId) {
                                $this->cartService->add(
                                    $cart,
                                    [
                                        'id' => $productId,
                                        'type' => 'product',
                                        'referencedId' => $productId,
                                        'quantity' => $quantity
                                    ],
                                    $context
                                );
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip items that can't be added
                        $this->log('warning', 'Could not restore line item', [
                            'error' => $e->getMessage(),
                            'lineItem' => $lineItem['id'] ?? 'unknown'
                        ]);
                    }
                }
            }

            return true;

        } catch (\Exception $e) {
            $this->log('error', 'Error restoring cart', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Apply a coupon code to the current cart
     */
    private function applyCouponCode(string $couponCode, SalesChannelContext $context): void
    {
        try {
            $cart = $this->cartService->getCart($context->getToken(), $context);

            // Add promotion line item
            $promotionItemBuilder = new PromotionItemBuilder();
            $promotionLineItem = $promotionItemBuilder->buildPlaceholderItem($couponCode);

            $this->cartService->add($cart, [$promotionLineItem], $context);

        } catch (\Exception $e) {
            $this->log('warning', 'Could not apply coupon code', [
                'couponCode' => $couponCode,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Track the restore event (optional - for analytics)
     */
    private function trackRestoreEvent(?string $cartToken, ?string $couponCode): void
    {
        try {
            if ($cartToken) {
                $this->connection->executeStatement(
                    'UPDATE abandoned_cart SET updated_at = NOW() WHERE cart_token = :token',
                    ['token' => $cartToken]
                );
            }
        } catch (\Exception $e) {
            // Silently fail - tracking is not critical
        }
    }

    /**
     * Log helper
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->$level('[LeadCollect CartRestore] ' . $message, $context);
        }
    }
}
