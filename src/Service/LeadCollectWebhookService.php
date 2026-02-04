<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use MailCampaigns\AbandonedCart\Entity\AbandonedCartEntity;

/**
 * Service to send webhooks to LeadCollect when cart events occur
 */
class LeadCollectWebhookService
{
    private Client $httpClient;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    private const CONFIG_WEBHOOK_URL = 'MailCampaignsAbandonedCart.config.leadCollectWebhookUrl';
    private const CONFIG_WEBHOOK_SECRET = 'MailCampaignsAbandonedCart.config.leadCollectWebhookSecret';
    private const CONFIG_WEBHOOK_ENABLED = 'MailCampaignsAbandonedCart.config.leadCollectWebhookEnabled';

    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 1000;

    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
        $this->httpClient = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * Check if webhook is enabled and configured
     */
    public function isEnabled(?string $salesChannelId = null): bool
    {
        $enabled = $this->systemConfigService->get(self::CONFIG_WEBHOOK_ENABLED, $salesChannelId);
        $webhookUrl = $this->getWebhookUrl($salesChannelId);
        
        return $enabled && !empty($webhookUrl);
    }

    /**
     * Get the configured webhook URL
     */
    private function getWebhookUrl(?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->get(self::CONFIG_WEBHOOK_URL, $salesChannelId);
    }

    /**
     * Get the configured webhook secret
     */
    private function getWebhookSecret(?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->get(self::CONFIG_WEBHOOK_SECRET, $salesChannelId);
    }

    /**
     * Send webhook when cart is marked as abandoned
     */
    public function sendCartAbandonedWebhook(
        AbandonedCartEntity $abandonedCart,
        array $cartData,
        ?string $salesChannelId = null
    ): bool {
        if (!$this->isEnabled($salesChannelId)) {
            return false;
        }

        $customer = $abandonedCart->getCustomer();
        if (!$customer) {
            $this->logger->warning('LeadCollect Webhook: No customer found for abandoned cart', [
                'cartId' => $abandonedCart->getId(),
            ]);
            return false;
        }

        // Get customer address
        $address = $this->getCustomerAddress($customer);
        if (!$address) {
            $this->logger->warning('LeadCollect Webhook: No address found for customer', [
                'customerId' => $customer->getId(),
            ]);
            return false;
        }

        // Build payload
        $payload = [
            'eventType' => 'cart_abandoned',
            'externalCartId' => $abandonedCart->getId(),
            'externalCustomerId' => $customer->getId(),
            'abandonedAt' => $abandonedCart->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'customer' => [
                'salutation' => $customer->getSalutation()?->getDisplayName(),
                'firstName' => $customer->getFirstName(),
                'lastName' => $customer->getLastName(),
                'email' => $customer->getEmail(),
                'phone' => $address['phone'] ?? null,
                'address' => [
                    'street' => $address['street'],
                    'zipcode' => $address['zipcode'],
                    'city' => $address['city'],
                    'country' => $address['country'],
                ],
            ],
            'cart' => [
                'totalPrice' => $abandonedCart->getPrice(),
                'currency' => $cartData['price']['currencyId'] ?? 'EUR',
                'lineItems' => $this->formatLineItems($cartData['lineItems'] ?? []),
            ],
        ];

        return $this->sendWebhook($payload, $salesChannelId);
    }

    /**
     * Send webhook when coupon is redeemed
     */
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

    /**
     * Send webhook when order is placed (for recovery tracking)
     */
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

    /**
     * Send webhook with retry logic
     */
    private function sendWebhook(array $payload, ?string $salesChannelId = null): bool
    {
        $webhookUrl = $this->getWebhookUrl($salesChannelId);
        $webhookSecret = $this->getWebhookSecret($salesChannelId);

        if (!$webhookUrl) {
            return false;
        }

        // Build full URL with secret
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
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt); // Exponential backoff
                }
            }
        }

        return false;
    }

    /**
     * Extract customer address from CustomerEntity
     */
    private function getCustomerAddress(CustomerEntity $customer): ?array
    {
        $defaultBillingAddress = $customer->getDefaultBillingAddress();
        
        if (!$defaultBillingAddress) {
            // Try to get any address
            $addresses = $customer->getAddresses();
            if ($addresses && $addresses->count() > 0) {
                $defaultBillingAddress = $addresses->first();
            }
        }

        if (!$defaultBillingAddress) {
            return null;
        }

        return [
            'street' => $defaultBillingAddress->getStreet(),
            'zipcode' => $defaultBillingAddress->getZipcode(),
            'city' => $defaultBillingAddress->getCity(),
            'country' => $defaultBillingAddress->getCountry()?->getIso() ?? 'DE',
            'phone' => $defaultBillingAddress->getPhoneNumber(),
        ];
    }

    /**
     * Format line items for webhook payload
     */
    private function formatLineItems(array $lineItems): array
    {
        $formatted = [];

        foreach ($lineItems as $item) {
            // Skip non-product items
            if (($item['type'] ?? '') !== 'product') {
                continue;
            }

            $formatted[] = [
                'name' => $item['label'] ?? $item['productId'] ?? 'Produkt',
                'sku' => $item['payload']['productNumber'] ?? $item['productId'] ?? null,
                'price' => $item['price']['unitPrice'] ?? $item['unitPrice'] ?? 0,
                'quantity' => $item['quantity'] ?? 1,
                'imageUrl' => $this->extractImageUrl($item),
            ];
        }

        return $formatted;
    }

    /**
     * Extract product image URL from line item
     */
    private function extractImageUrl(array $item): ?string
    {
        // Try different possible locations for cover image
        $cover = $item['cover'] ?? $item['payload']['cover'] ?? null;
        
        if ($cover && isset($cover['url'])) {
            return $cover['url'];
        }

        if ($cover && isset($cover['media']['url'])) {
            return $cover['media']['url'];
        }

        return null;
    }
}
