<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Service to create and manage promotion/coupon codes for abandoned cart recovery
 */
class CouponService
{
    private EntityRepository $promotionRepository;
    private EntityRepository $promotionIndividualCodeRepository;
    private EntityRepository $salesChannelRepository;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    private const CONFIG_COUPON_TYPE = 'MailCampaignsAbandonedCart.config.leadCollectCouponType';
    private const CONFIG_COUPON_VALUE = 'MailCampaignsAbandonedCart.config.leadCollectCouponValue';
    private const CONFIG_COUPON_VALID_DAYS = 'MailCampaignsAbandonedCart.config.leadCollectCouponValidDays';
    private const CONFIG_COUPON_MIN_ORDER = 'MailCampaignsAbandonedCart.config.leadCollectCouponMinOrder';
    private const CONFIG_BASE_PROMOTION_ID = 'MailCampaignsAbandonedCart.config.leadCollectBasePromotionId';

    // Storefront sales channel type ID (constant in Shopware)
    private const STOREFRONT_SALES_CHANNEL_TYPE = '8a243080f92e4c719546314b577cf82b';

    public function __construct(
        EntityRepository $promotionRepository,
        EntityRepository $promotionIndividualCodeRepository,
        EntityRepository $salesChannelRepository,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->promotionRepository = $promotionRepository;
        $this->promotionIndividualCodeRepository = $promotionIndividualCodeRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * Get all storefront sales channel IDs
     */
    private function getAllStorefrontSalesChannels(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('typeId', self::STOREFRONT_SALES_CHANNEL_TYPE));
        
        $result = $this->salesChannelRepository->searchIds($criteria, $context);
        
        return $result->getIds();
    }

    /**
     * Create a unique coupon code for abandoned cart recovery
     * 
     * Uses Shopware's individual promotion codes feature
     */
    public function createCouponCode(
        string $customerId,
        string $abandonedCartId,
        ?string $salesChannelId = null,
        Context $context = null
    ): ?array {
        $context = $context ?? Context::createDefaultContext();

        // Get or create base promotion
        $basePromotionId = $this->getOrCreateBasePromotion($salesChannelId, $context);
        
        if (!$basePromotionId) {
            $this->logger->error('LeadCollect Coupon: Could not get or create base promotion');
            return null;
        }

        // Generate unique code
        $code = $this->generateUniqueCode();
        
        // Calculate validity
        $validDays = (int) ($this->systemConfigService->get(self::CONFIG_COUPON_VALID_DAYS, $salesChannelId) ?? 30);
        $validUntil = new \DateTime();
        $validUntil->modify("+{$validDays} days");

        try {
            // Create individual code
            $codeId = Uuid::randomHex();
            
            // Create code WITHOUT payload - Shopware marks codes as "redeemed" if payload has any value!
            // The payload is set by Shopware when the code is actually used in an order
            $this->promotionIndividualCodeRepository->create([
                [
                    'id' => $codeId,
                    'promotionId' => $basePromotionId,
                    'code' => $code,
                    // NO payload here! Leave it NULL so code appears as "not redeemed"
                ],
            ], $context);

            $couponType = $this->systemConfigService->get(self::CONFIG_COUPON_TYPE, $salesChannelId) ?? 'percentage';
            $couponValue = (float) ($this->systemConfigService->get(self::CONFIG_COUPON_VALUE, $salesChannelId) ?? 10);

            $this->logger->info('LeadCollect Coupon: Created individual code', [
                'code' => $code,
                'abandonedCartId' => $abandonedCartId,
                'validUntil' => $validUntil->format('Y-m-d'),
            ]);

            return [
                'code' => $code,
                'codeId' => $codeId,
                'promotionId' => $basePromotionId,
                'type' => $couponType,
                'value' => $couponValue,
                'validUntil' => $validUntil,
            ];

        } catch (\Exception $e) {
            $this->logger->error('LeadCollect Coupon: Failed to create individual code', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if a coupon code was created by LeadCollect
     */
    public function isLeadCollectCoupon(string $code, Context $context = null): bool
    {
        $context = $context ?? Context::createDefaultContext();

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('code', $code));
            
            $result = $this->promotionIndividualCodeRepository->search($criteria, $context);
            
            if ($result->count() === 0) {
                return false;
            }

            $individualCode = $result->first();
            $payload = $individualCode->getPayload();

            return isset($payload['createdBy']) && $payload['createdBy'] === 'LeadCollect';

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get coupon details by code
     */
    public function getCouponDetails(string $code, Context $context = null): ?array
    {
        $context = $context ?? Context::createDefaultContext();

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('code', $code));
            $criteria->addAssociation('promotion');
            $criteria->addAssociation('promotion.discounts');
            
            $result = $this->promotionIndividualCodeRepository->search($criteria, $context);
            
            if ($result->count() === 0) {
                return null;
            }

            $individualCode = $result->first();
            $promotion = $individualCode->getPromotion();
            $payload = $individualCode->getPayload() ?? [];

            // Get discount value
            $discountType = 'percentage';
            $discountValue = 0;
            
            if ($promotion && $promotion->getDiscounts() && $promotion->getDiscounts()->count() > 0) {
                $discount = $promotion->getDiscounts()->first();
                $discountType = $discount->getType();
                $discountValue = $discount->getValue();
            }

            return [
                'code' => $code,
                'codeId' => $individualCode->getId(),
                'promotionId' => $individualCode->getPromotionId(),
                'customerId' => $payload['customerId'] ?? null,
                'abandonedCartId' => $payload['abandonedCartId'] ?? null,
                'discountType' => $discountType,
                'discountValue' => $discountValue,
                'isLeadCollect' => ($payload['createdBy'] ?? null) === 'LeadCollect',
            ];

        } catch (\Exception $e) {
            $this->logger->error('LeadCollect Coupon: Failed to get coupon details', [
                'code' => $code,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get or create the base promotion for LeadCollect recovery coupons
     */
    private function getOrCreateBasePromotion(?string $salesChannelId, Context $context): ?string
    {
        // Check if we have a configured base promotion
        $configuredId = $this->systemConfigService->get(self::CONFIG_BASE_PROMOTION_ID, $salesChannelId);
        
        if ($configuredId) {
            // Verify it exists
            $criteria = new Criteria([$configuredId]);
            $result = $this->promotionRepository->search($criteria, $context);
            
            if ($result->count() > 0) {
                return $configuredId;
            }
        }

        // Create new base promotion
        return $this->createBasePromotion($salesChannelId, $context);
    }

    /**
     * Create the base promotion for recovery coupons
     */
    private function createBasePromotion(?string $salesChannelId, Context $context): ?string
    {
        $couponType = $this->systemConfigService->get(self::CONFIG_COUPON_TYPE, $salesChannelId) ?? 'percentage';
        $couponValue = (float) ($this->systemConfigService->get(self::CONFIG_COUPON_VALUE, $salesChannelId) ?? 10);
        $minOrder = (float) ($this->systemConfigService->get(self::CONFIG_COUPON_MIN_ORDER, $salesChannelId) ?? 0);

        $promotionId = Uuid::randomHex();
        $discountId = Uuid::randomHex();

        // Build sales channels array - if specific channel given, use it; otherwise get all storefront channels
        $salesChannelsData = [];
        if ($salesChannelId) {
            $salesChannelsData = [
                [
                    'salesChannelId' => $salesChannelId,
                    'priority' => 1,
                ],
            ];
        } else {
            // Get all storefront sales channels
            $allChannels = $this->getAllStorefrontSalesChannels($context);
            foreach ($allChannels as $channelId) {
                $salesChannelsData[] = [
                    'salesChannelId' => $channelId,
                    'priority' => 1,
                ];
            }
        }

        try {
            $this->promotionRepository->create([
                [
                    'id' => $promotionId,
                    'name' => 'LeadCollect Warenkorbabbrecher Recovery',
                    'active' => true,
                    'validFrom' => new \DateTime(),
                    'validUntil' => (new \DateTime())->modify('+10 years'),
                    'exclusive' => false,
                    'useCodes' => true,
                    'useIndividualCodes' => true,
                    'individualCodePattern' => 'COMEBACK-%s',
                    'maxRedemptionsGlobal' => null,
                    'maxRedemptionsPerCustomer' => null, // No customer limit - codes are universal
                    'orderCount' => 0,
                    'discounts' => [
                        [
                            'id' => $discountId,
                            'scope' => 'cart',
                            'type' => $couponType,
                            'value' => $couponValue,
                            'considerAdvancedRules' => false,
                        ],
                    ],
                    'salesChannels' => $salesChannelsData,
                ],
            ], $context);

            // Save the promotion ID to config
            $this->systemConfigService->set(
                self::CONFIG_BASE_PROMOTION_ID,
                $promotionId,
                $salesChannelId
            );

            $this->logger->info('LeadCollect Coupon: Created base promotion', [
                'promotionId' => $promotionId,
                'type' => $couponType,
                'value' => $couponValue,
            ]);

            return $promotionId;

        } catch (\Exception $e) {
            $this->logger->error('LeadCollect Coupon: Failed to create base promotion', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate a unique coupon code
     */
    private function generateUniqueCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = 'COMEBACK-';
        
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $code;
    }
}
