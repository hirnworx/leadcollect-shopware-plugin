<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Controller;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Api\Response\JsonApiResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

/**
 * API Controller for LeadCollect to fetch abandoned carts
 * 
 * @Route(defaults={"_routeScope"={"api"}})
 */
class LeadCollectApiController extends AbstractController
{
    private Connection $connection;
    private ?LoggerInterface $logger;

    public function __construct(
        Connection $connection,
        ?LoggerInterface $logger = null
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * Get abandoned carts for LeadCollect polling
     * 
     * @Route("/api/leadcollect/carts", name="api.leadcollect.carts", methods={"GET"})
     */
    public function getCarts(Request $request): JsonResponse
    {
        try {
            // Min age in seconds (default 1 hour)
            $minAgeSeconds = (int) $request->query->get('min_age', 3600);
            // Limit results
            $limit = min((int) $request->query->get('limit', 100), 500);
            
            $this->log('info', 'LeadCollect API: Fetching carts', [
                'minAgeSeconds' => $minAgeSeconds,
                'limit' => $limit
            ]);

            // Calculate threshold timestamp
            $threshold = new \DateTime();
            $threshold->modify("-{$minAgeSeconds} seconds");

            // Query for abandoned carts:
            // - Cart is older than threshold
            // - Customer has address data
            // - Cart has items
            // - No order was placed with this cart token
            $sql = "
                SELECT 
                    LOWER(HEX(c.token)) as cart_token,
                    c.price as cart_total,
                    c.line_item_count,
                    c.created_at as cart_created_at,
                    c.updated_at as cart_updated_at,
                    c.payload as cart_payload,
                    LOWER(HEX(cu.id)) as customer_id,
                    cu.first_name,
                    cu.last_name,
                    cu.email,
                    cu.salutation_id,
                    ca.street,
                    ca.zipcode,
                    ca.city,
                    LOWER(HEX(ca.country_id)) as country_id,
                    co.iso as country_iso,
                    LOWER(HEX(c.sales_channel_id)) as sales_channel_id
                FROM cart c
                INNER JOIN customer cu ON c.customer_id = cu.id
                INNER JOIN customer_address ca ON cu.default_billing_address_id = ca.id
                INNER JOIN country co ON ca.country_id = co.id
                LEFT JOIN `order` o ON c.token = o.cart_token
                WHERE c.created_at < :threshold
                  AND c.line_item_count > 0
                  AND ca.street IS NOT NULL
                  AND ca.street != ''
                  AND o.id IS NULL
                ORDER BY c.created_at DESC
                LIMIT :limit
            ";

            $stmt = $this->connection->prepare($sql);
            $result = $stmt->executeQuery([
                'threshold' => $threshold->format('Y-m-d H:i:s'),
                'limit' => $limit
            ]);
            
            $carts = $result->fetchAllAssociative();
            
            $this->log('info', 'LeadCollect API: Found carts', ['count' => count($carts)]);

            // Format response
            $formattedCarts = [];
            foreach ($carts as $cart) {
                // Parse cart payload for line items
                $lineItems = $this->parseCartPayload($cart['cart_payload']);
                
                // Skip carts with no valid products
                if (empty($lineItems)) {
                    continue;
                }

                $formattedCarts[] = [
                    'cartToken' => $cart['cart_token'],
                    'cartTotal' => (float) $cart['cart_total'],
                    'lineItemCount' => (int) $cart['line_item_count'],
                    'createdAt' => $cart['cart_created_at'],
                    'updatedAt' => $cart['cart_updated_at'],
                    'salesChannelId' => $cart['sales_channel_id'],
                    'customer' => [
                        'id' => $cart['customer_id'],
                        'firstName' => $cart['first_name'],
                        'lastName' => $cart['last_name'],
                        'email' => $cart['email'],
                        'address' => [
                            'street' => $cart['street'],
                            'zipcode' => $cart['zipcode'],
                            'city' => $cart['city'],
                            'country' => $cart['country_iso'] ?? 'DE'
                        ]
                    ],
                    'lineItems' => $lineItems
                ];
            }

            return new JsonResponse([
                'success' => true,
                'count' => count($formattedCarts),
                'carts' => $formattedCarts,
                'queriedAt' => (new \DateTime())->format('c'),
                'minAgeSeconds' => $minAgeSeconds
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'LeadCollect API: Error fetching carts', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Health check endpoint
     * 
     * @Route("/api/leadcollect/health", name="api.leadcollect.health", methods={"GET"})
     */
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'plugin' => 'MailCampaignsAbandonedCart',
            'version' => '1.3.0',
            'timestamp' => (new \DateTime())->format('c')
        ]);
    }

    /**
     * Parse cart payload to extract line items
     */
    private function parseCartPayload(?string $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        try {
            // Try to unserialize (Shopware 6.5)
            $data = @unserialize($payload);
            if ($data === false) {
                // Try JSON decode (Shopware 6.6+)
                $data = @json_decode($payload, true);
            }

            if (!is_array($data)) {
                return [];
            }

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

        } catch (\Exception $e) {
            $this->log('warning', 'Failed to parse cart payload', ['error' => $e->getMessage()]);
            return [];
        }
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
