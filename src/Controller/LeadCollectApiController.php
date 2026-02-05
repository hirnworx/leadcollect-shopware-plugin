<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Controller;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

/**
 * API Controller for LeadCollect to fetch abandoned carts
 * This is a PUBLIC endpoint secured by webhook secret
 * 
 * Compatible with Shopware 6.5 and 6.6+
 */
class LeadCollectApiController extends AbstractController
{
    private Connection $connection;
    private SystemConfigService $systemConfigService;
    private ?LoggerInterface $logger;
    private ?bool $isLegacyCartTable = null;

    private const CONFIG_WEBHOOK_SECRET = 'MailCampaignsAbandonedCart.config.leadCollectWebhookSecret';

    public function __construct(
        Connection $connection,
        SystemConfigService $systemConfigService,
        ?LoggerInterface $logger = null
    ) {
        $this->connection = $connection;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * Get abandoned carts for LeadCollect polling
     * Public endpoint - secured by secret parameter
     */
    public function getCarts(Request $request): JsonResponse
    {
        // Verify secret
        $secret = $request->query->get('secret') ?? $request->headers->get('X-LeadCollect-Secret');
        $expectedSecret = $this->systemConfigService->get(self::CONFIG_WEBHOOK_SECRET);
        
        if (!$secret || !$expectedSecret || $secret !== $expectedSecret) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid or missing secret'
            ], 401);
        }

        try {
            // Min age in seconds (default 1 hour)
            $minAgeSeconds = (int) $request->query->get('min_age', 3600);
            // Limit results
            $limit = min((int) $request->query->get('limit', 100), 500);
            
            $this->log('info', 'LeadCollect API: Fetching carts', [
                'minAgeSeconds' => $minAgeSeconds,
                'limit' => $limit,
                'isLegacy' => $this->isLegacyCartTable()
            ]);

            // Calculate threshold timestamp
            $threshold = new \DateTime();
            $threshold->modify("-{$minAgeSeconds} seconds");

            // Get carts based on Shopware version
            if ($this->isLegacyCartTable()) {
                $carts = $this->getCartsLegacy($threshold, $limit);
            } else {
                $carts = $this->getCartsModern($threshold, $limit);
            }
            
            $this->log('info', 'LeadCollect API: Found carts', ['count' => count($carts)]);

            return new JsonResponse([
                'success' => true,
                'count' => count($carts),
                'carts' => $carts,
                'queriedAt' => (new \DateTime())->format('c'),
                'minAgeSeconds' => $minAgeSeconds,
                'shopwareVersion' => $this->isLegacyCartTable() ? '6.5' : '6.6+'
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'LeadCollect API: Error fetching carts', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if we're using the legacy cart table structure (Shopware 6.5)
     * Legacy has: customer_id, price, line_item_count columns
     * Modern (6.6+) only has: token, payload, created_at, etc.
     */
    private function isLegacyCartTable(): bool
    {
        if ($this->isLegacyCartTable !== null) {
            return $this->isLegacyCartTable;
        }

        try {
            $columns = $this->connection->executeQuery("SHOW COLUMNS FROM cart")->fetchAllAssociative();
            $columnNames = array_column($columns, 'Field');
            
            // Legacy table has customer_id column
            $this->isLegacyCartTable = in_array('customer_id', $columnNames);
            
            $this->log('info', 'Cart table structure detected', [
                'isLegacy' => $this->isLegacyCartTable,
                'columns' => $columnNames
            ]);
            
            return $this->isLegacyCartTable;
        } catch (\Exception $e) {
            $this->log('warning', 'Could not detect cart table structure', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get carts for Shopware 6.5 (legacy structure)
     */
    private function getCartsLegacy(\DateTime $threshold, int $limit): array
    {
        $sql = "
            SELECT 
                LOWER(HEX(c.token)) as cart_token,
                c.price as cart_total,
                c.line_item_count,
                c.created_at as cart_created_at,
                c.payload as cart_payload,
                LOWER(HEX(cu.id)) as customer_id,
                cu.first_name,
                cu.last_name,
                cu.email,
                ca.street,
                ca.zipcode,
                ca.city,
                co.iso as country_iso
            FROM cart c
            INNER JOIN customer cu ON c.customer_id = cu.id
            LEFT JOIN customer_address ca ON cu.default_billing_address_id = ca.id
            LEFT JOIN country co ON ca.country_id = co.id
            WHERE c.created_at < ?
              AND c.line_item_count > 0
              AND ca.street IS NOT NULL
              AND ca.street != ''
            ORDER BY c.created_at DESC
            LIMIT " . (int)$limit;

        $result = $this->connection->executeQuery($sql, [
            $threshold->format('Y-m-d H:i:s')
        ]);
        
        $carts = $result->fetchAllAssociative();
        return $this->formatCarts($carts, true);
    }

    /**
     * Get carts for Shopware 6.6+ (modern structure)
     * In 6.6+ the cart table only has token, payload, created_at
     * We need to extract customer info from the payload
     */
    private function getCartsModern(\DateTime $threshold, int $limit): array
    {
        // First get all carts older than threshold
        $sql = "
            SELECT 
                token as cart_token,
                payload as cart_payload,
                created_at as cart_created_at
            FROM cart
            WHERE created_at < ?
            ORDER BY created_at DESC
            LIMIT " . (int)$limit;

        $result = $this->connection->executeQuery($sql, [
            $threshold->format('Y-m-d H:i:s')
        ]);
        
        $rawCarts = $result->fetchAllAssociative();
        $formattedCarts = [];

        foreach ($rawCarts as $cart) {
            $cartData = $this->parseModernCartPayload($cart['cart_payload']);
            
            if (!$cartData) {
                continue;
            }

            // Skip carts without products
            if (empty($cartData['lineItems'])) {
                continue;
            }

            // Skip carts without customer address
            if (empty($cartData['customer']['address']['street'])) {
                continue;
            }

            // Convert binary token to hex if needed
            $token = $cart['cart_token'];
            if (!ctype_xdigit($token)) {
                $token = bin2hex($token);
            }

            $formattedCarts[] = [
                'cartToken' => strtolower($token),
                'cartTotal' => $cartData['price'] ?? 0,
                'lineItemCount' => count($cartData['lineItems']),
                'createdAt' => $cart['cart_created_at'],
                'customer' => $cartData['customer'],
                'lineItems' => $cartData['lineItems']
            ];
        }

        return $formattedCarts;
    }

    /**
     * Parse modern cart payload (Shopware 6.6+)
     * The payload contains everything: customer, items, price
     */
    private function parseModernCartPayload(?string $payload): ?array
    {
        if (empty($payload)) {
            return null;
        }

        try {
            // Payload might be compressed
            $decompressed = @gzuncompress($payload);
            if ($decompressed !== false) {
                $payload = $decompressed;
            }

            // Try unserialize first
            $data = @unserialize($payload);
            if ($data === false) {
                // Try JSON
                $data = @json_decode($payload, true);
            }

            if (!is_array($data)) {
                return null;
            }

            // Extract customer info from payload
            $customer = [
                'id' => null,
                'firstName' => '',
                'lastName' => '',
                'email' => '',
                'address' => [
                    'street' => '',
                    'zipcode' => '',
                    'city' => '',
                    'country' => 'DE'
                ]
            ];

            // In modern carts, customer data might be in different places
            if (isset($data['customer'])) {
                $c = $data['customer'];
                $customer['id'] = $c['id'] ?? null;
                $customer['firstName'] = $c['firstName'] ?? '';
                $customer['lastName'] = $c['lastName'] ?? '';
                $customer['email'] = $c['email'] ?? '';
                
                // Address might be in activeBillingAddress or defaultBillingAddress
                $address = $c['activeBillingAddress'] ?? $c['defaultBillingAddress'] ?? null;
                if ($address) {
                    $customer['address'] = [
                        'street' => $address['street'] ?? '',
                        'zipcode' => $address['zipcode'] ?? '',
                        'city' => $address['city'] ?? '',
                        'country' => $address['country']['iso'] ?? 'DE'
                    ];
                }
            }

            // Extract line items
            $lineItems = $this->parseLineItems($data);

            // Extract price
            $price = 0;
            if (isset($data['price']['totalPrice'])) {
                $price = (float) $data['price']['totalPrice'];
            } elseif (isset($data['price']['netPrice'])) {
                $price = (float) $data['price']['netPrice'];
            }

            return [
                'customer' => $customer,
                'lineItems' => $lineItems,
                'price' => $price
            ];

        } catch (\Exception $e) {
            $this->log('warning', 'Failed to parse modern cart payload', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Format carts from legacy query result
     */
    private function formatCarts(array $carts, bool $isLegacy): array
    {
        $formattedCarts = [];
        
        foreach ($carts as $cart) {
            $lineItems = $this->parseLineItems($this->decodePayload($cart['cart_payload']));
            
            if (empty($lineItems)) {
                continue;
            }

            $formattedCarts[] = [
                'cartToken' => $cart['cart_token'],
                'cartTotal' => (float) ($cart['cart_total'] ?? 0),
                'lineItemCount' => (int) ($cart['line_item_count'] ?? count($lineItems)),
                'createdAt' => $cart['cart_created_at'],
                'customer' => [
                    'id' => $cart['customer_id'] ?? null,
                    'firstName' => $cart['first_name'] ?? '',
                    'lastName' => $cart['last_name'] ?? '',
                    'email' => $cart['email'] ?? '',
                    'address' => [
                        'street' => $cart['street'] ?? '',
                        'zipcode' => $cart['zipcode'] ?? '',
                        'city' => $cart['city'] ?? '',
                        'country' => $cart['country_iso'] ?? 'DE'
                    ]
                ],
                'lineItems' => $lineItems
            ];
        }

        return $formattedCarts;
    }

    /**
     * Decode payload (handles compression, serialization, and Shopware Cart objects)
     */
    private function decodePayload(?string $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        try {
            // Try decompression first
            $decompressed = @gzuncompress($payload);
            if ($decompressed !== false) {
                $payload = $decompressed;
            }

            // Try unserialize
            $data = @unserialize($payload);
            
            // Handle Shopware Cart object (common in Shopware 6.5)
            if ($data !== false && is_object($data)) {
                return $this->convertCartObjectToArray($data);
            }
            
            if ($data !== false && is_array($data)) {
                return $data;
            }

            // Try JSON
            $data = @json_decode($payload, true);
            if (is_array($data)) {
                return $data;
            }

            return [];
        } catch (\Exception $e) {
            $this->log('warning', 'Failed to decode payload', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Convert a Shopware Cart object to an array for processing
     */
    private function convertCartObjectToArray(object $cartObject): array
    {
        $result = ['lineItems' => []];

        try {
            // Check if it's a Shopware Cart object
            if (method_exists($cartObject, 'getLineItems')) {
                $lineItems = $cartObject->getLineItems();
                
                foreach ($lineItems as $item) {
                    // Only process product items
                    if (method_exists($item, 'getType') && $item->getType() !== 'product') {
                        continue;
                    }

                    $lineItem = [
                        'type' => 'product',
                        'label' => method_exists($item, 'getLabel') ? $item->getLabel() : 'Produkt',
                        'quantity' => method_exists($item, 'getQuantity') ? $item->getQuantity() : 1,
                        'referencedId' => method_exists($item, 'getReferencedId') ? $item->getReferencedId() : null,
                    ];

                    // Get price
                    if (method_exists($item, 'getPrice') && $item->getPrice() !== null) {
                        $priceObj = $item->getPrice();
                        $lineItem['price'] = [
                            'unitPrice' => method_exists($priceObj, 'getUnitPrice') ? $priceObj->getUnitPrice() : 0,
                            'totalPrice' => method_exists($priceObj, 'getTotalPrice') ? $priceObj->getTotalPrice() : 0,
                        ];
                    }

                    // Get product number from payload
                    if (method_exists($item, 'getPayloadValue')) {
                        $productNumber = $item->getPayloadValue('productNumber');
                        if ($productNumber) {
                            $lineItem['payload'] = ['productNumber' => $productNumber];
                        }
                    }

                    // Get cover image
                    if (method_exists($item, 'getCover') && $item->getCover() !== null) {
                        $cover = $item->getCover();
                        if (method_exists($cover, 'getUrl')) {
                            $lineItem['cover'] = ['url' => $cover->getUrl()];
                        }
                    }

                    $result['lineItems'][] = $lineItem;
                }
            }

            // Get price info
            if (method_exists($cartObject, 'getPrice') && $cartObject->getPrice() !== null) {
                $priceObj = $cartObject->getPrice();
                $result['price'] = [
                    'totalPrice' => method_exists($priceObj, 'getTotalPrice') ? $priceObj->getTotalPrice() : 0,
                    'netPrice' => method_exists($priceObj, 'getNetPrice') ? $priceObj->getNetPrice() : 0,
                ];
            }

        } catch (\Exception $e) {
            $this->log('warning', 'Failed to convert Cart object', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Parse line items from cart data
     */
    private function parseLineItems(array $data): array
    {
        $lineItems = [];
        $items = $data['lineItems'] ?? $data['line_items'] ?? [];
        
        foreach ($items as $item) {
            // Only include product items
            $type = $item['type'] ?? '';
            if ($type !== 'product') {
                continue;
            }

            $price = 0;
            if (isset($item['price'])) {
                if (is_array($item['price'])) {
                    $price = $item['price']['unitPrice'] ?? $item['price']['totalPrice'] ?? 0;
                } else {
                    $price = (float) $item['price'];
                }
            }

            $imageUrl = null;
            if (isset($item['cover']['url'])) {
                $imageUrl = $item['cover']['url'];
            } elseif (isset($item['cover']['media']['url'])) {
                $imageUrl = $item['cover']['media']['url'];
            }

            $lineItems[] = [
                'name' => $item['label'] ?? $item['name'] ?? 'Produkt',
                'sku' => $item['payload']['productNumber'] ?? $item['referencedId'] ?? null,
                'productId' => $item['referencedId'] ?? $item['id'] ?? null,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => (float) $price,
                'imageUrl' => $imageUrl
            ];
        }

        return $lineItems;
    }

    /**
     * Health check endpoint
     */
    public function health(): JsonResponse
    {
        $isLegacy = $this->isLegacyCartTable();
        
        return new JsonResponse([
            'status' => 'ok',
            'plugin' => 'MailCampaignsAbandonedCart',
            'version' => '1.4.1',
            'shopwareCartStructure' => $isLegacy ? 'legacy (6.5)' : 'modern (6.6+)',
            'timestamp' => (new \DateTime())->format('c')
        ]);
    }

    /**
     * Log helper
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->$level('[LeadCollect API] ' . $message, $context);
        }
    }
}
