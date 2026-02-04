<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Service;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use MailCampaigns\AbandonedCart\Core\Checkout\AbandonedCart\AbandonedCartEntity;

class LeadCollectWebhookService
{
    private Client $httpClient;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;
    private EntityRepository $customerRepository;
    private Connection $connection;

    private const CONFIG_WEBHOOK_URL = 'MailCampaignsAbandonedCart.config.leadCollectWebhookUrl';
    private const CONFIG_WEBHOOK_SECRET = 'MailCampaignsAbandonedCart.config.leadCollectWebhookSecret';
    private const CONFIG_WEBHOOK_ENABLED = 'MailCampaignsAbandonedCart.config.leadCollectWebhookEnabled';

    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 1000;

    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        EntityRepository $customerRepository,
        Connection $connection
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
        $this->customerRepository = $customerRepository;
        $this->connection = $connection;
        $this->httpClient = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);
    }

    public function isEnabled(?string $salesChannelId = null): bool
    {
        $enabled = $this->systemConfigService->get(self::CONFIG_WEBHOOK_ENABLED, $salesChannelId);
        $webhookUrl = $this->getWebhookUrl($salesChannelId);
        return $enabled && !empty($webhookUrl);
    }

    private function getWebhookUrl(?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->get(self::CONFIG_WEBHOOK_URL, $salesChannelId);
    }

    private function getWebhookSecret(?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->get(self::CONFIG_WEBHOOK_SECRET, $salesChannelId);
    }

    public function sendCartAbandonedWebhook(
        AbandonedCartEntity $abandonedCart,
        array $cartData,
        ?string $salesChannelId = null,
        ?array $couponData = null
    ): bool {
        if (!$this->isEnabled($salesChannelId)) {
            return false;
        }

        $customerId = $abandonedCart->getCustomerId();
        
        // Load customer data from database
        $customerData = $this->loadCustomerData($customerId);
        if (!$customerData || empty($customerData['street'])) {
            $this->logger->warning('LeadCollect: No customer address found', [
                'customerId' => $customerId,
            ]);
            return false;
        }

        $payload = [
            'eventType' => 'cart_abandoned',
            'externalCartId' => $abandonedCart->getCartToken(),
            'externalCustomerId' => $customerId,
            'cartToken' => $abandonedCart->getCartToken(),  // For QR code cart restoration
            'abandonedAt' => date('c'),
            'customer' => [
                'firstName' => $customerData['first_name'] ?? 'Kunde',
                'lastName' => $customerData['last_name'] ?? '',
                'email' => $customerData['email'] ?? null,
                'address' => [
                    'street' => $customerData['street'] ?? '',
                    'zipcode' => $customerData['zipcode'] ?? '',
                    'city' => $customerData['city'] ?? '',
                    'country' => $customerData['country_iso'] ?? 'DE',
                ],
            ],
            'cart' => [
                'totalPrice' => $abandonedCart->getPrice(),
                'currency' => 'EUR',
                'lineItems' => $this->formatLineItems($abandonedCart->getLineItems()),
            ],
        ];
        
        if ($couponData) {
            $payload['coupon'] = [
                'code' => $couponData['code'] ?? null,
                'type' => $couponData['type'] ?? 'percentage',
                'value' => $couponData['value'] ?? 10,
                'validUntil' => $couponData['validUntil'] ?? null,
            ];
        }

        return $this->sendWebhook($payload, $salesChannelId);
    }

    public function sendCouponRedeemedWebhook(
        string $couponCode,
        ?string $orderId,
        ?float $orderValue,
        ?string $customerId,
        ?string $salesChannelId = null
    ): bool {
        if (!$this->isEnabled($salesChannelId)) {
            return false;
        }

        $payload = [
            'eventType' => 'coupon_redeemed',
            'couponCode' => $couponCode,
            'orderId' => $orderId,
            'orderValue' => $orderValue,
            'customerId' => $customerId,
        ];

        return $this->sendWebhook($payload, $salesChannelId);
    }

    public function sendOrderPlacedWebhook(
        string $orderId,
        float $orderValue,
        ?string $couponCode,
        string $customerId,
        ?string $customerEmail,
        ?string $salesChannelId = null
    ): bool {
        if (!$this->isEnabled($salesChannelId)) {
            return false;
        }

        $payload = [
            'eventType' => 'order_placed',
            'orderId' => $orderId,
            'orderValue' => $orderValue,
            'couponCode' => $couponCode,
            'customerId' => $customerId,
            'customerEmail' => $customerEmail,
        ];

        return $this->sendWebhook($payload, $salesChannelId);
    }

    private function sendWebhook(array $payload, ?string $salesChannelId = null): bool
    {
        $webhookUrl = $this->getWebhookUrl($salesChannelId);
        $webhookSecret = $this->getWebhookSecret($salesChannelId);

        if (!$webhookUrl) {
            return false;
        }

        $fullUrl = rtrim($webhookUrl, '/');
        if ($webhookSecret) {
            $fullUrl .= '/' . $webhookSecret;
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->httpClient->post($fullUrl, [
                    'json' => $payload,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'Shopware6-LeadCollect-Plugin/1.0',
                        'X-LeadCollect-Event' => $payload['eventType'] ?? 'unknown',
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                
                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logger->info('LeadCollect Webhook sent successfully', [
                        'eventType' => $payload['eventType'],
                        'statusCode' => $statusCode,
                    ]);
                    return true;
                }

                $this->logger->warning('LeadCollect Webhook returned non-success status', [
                    'eventType' => $payload['eventType'],
                    'statusCode' => $statusCode,
                    'attempt' => $attempt,
                ]);

            } catch (GuzzleException $e) {
                $this->logger->error('LeadCollect Webhook failed', [
                    'eventType' => $payload['eventType'],
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                }
            }
        }

        return false;
    }

    private function loadCustomerData(string $customerId): ?array
    {
        try {
            $sql = "
                SELECT 
                    c.first_name,
                    c.last_name,
                    c.email,
                    ca.street,
                    ca.zipcode,
                    ca.city,
                    co.iso as country_iso
                FROM customer c
                LEFT JOIN customer_address ca ON c.default_billing_address_id = ca.id
                LEFT JOIN country co ON ca.country_id = co.id
                WHERE c.id = UNHEX(REPLACE(:customerId, '-', ''))
            ";
            
            $stmt = $this->connection->prepare($sql);
            $result = $stmt->executeQuery(['customerId' => $customerId]);
            $data = $result->fetchAssociative();
            
            return $data ?: null;
        } catch (\Exception $e) {
            $this->logger->error('LeadCollect: Error loading customer', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function formatLineItems(array $lineItems): array
    {
        $formatted = [];

        foreach ($lineItems as $item) {
            // Handle LineItem object
            if (is_object($item) && $item instanceof \Shopware\Core\Checkout\Cart\LineItem\LineItem) {
                if ($item->getType() !== 'product') {
                    continue;
                }
                
                $priceObj = $item->getPrice();
                $unitPrice = $priceObj ? $priceObj->getUnitPrice() : 0;
                $cover = $item->getCover();
                $imageUrl = null;
                
                if ($cover && method_exists($cover, 'getUrl')) {
                    $imageUrl = $cover->getUrl();
                }
                
                $formatted[] = [
                    'name' => $item->getLabel() ?: 'Produkt',
                    'sku' => $item->getPayloadValue('productNumber') ?? $item->getReferencedId(),
                    'price' => $unitPrice,
                    'quantity' => $item->getQuantity(),
                    'imageUrl' => $imageUrl,
                ];
                continue;
            }
            
            // Handle serialized array format
            if (is_array($item)) {
                if (($item['type'] ?? '') !== 'product') {
                    continue;
                }
                
                $price = 0;
                if (isset($item['price'])) {
                    if (is_array($item['price']) && isset($item['price']['unitPrice'])) {
                        $price = (float) $item['price']['unitPrice'];
                    } elseif (is_numeric($item['price'])) {
                        $price = (float) $item['price'];
                    }
                }
                
                $imageUrl = null;
                if (isset($item['cover'])) {
                    $cover = $item['cover'];
                    if (is_array($cover) && isset($cover['url'])) {
                        $imageUrl = $cover['url'];
                    } elseif (is_array($cover) && isset($cover['media']['url'])) {
                        $imageUrl = $cover['media']['url'];
                    }
                }

                $formatted[] = [
                    'name' => $item['label'] ?? $item['productId'] ?? 'Produkt',
                    'sku' => $item['payload']['productNumber'] ?? $item['referencedId'] ?? null,
                    'price' => $price,
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'imageUrl' => $imageUrl,
                ];
            }
        }

        return $formatted;
    }
}
