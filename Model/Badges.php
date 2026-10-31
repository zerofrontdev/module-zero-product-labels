<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductLabels\Model;

use Magento\Catalog\Model\Product;
use Zero\Core\Api\ProductBadgeProviderInterface;
use Zero\Core\Model\Pricing\Amounts;
use Zero\Core\Model\Pricing\CachedPrices;

/**
 * The badges this module adds to Zero_Core's New and Sale, each switched on
 * in Stores > Configuration > Zero > Theme > Product Badges:
 * - the product's own badge: its "Badge" attribute (Bestseller, Limited, …),
 *   first;
 * - Only a few left: a simple product's salable quantity at or below the
 *   threshold (LowStock). Cards are cached for up to an hour, so it reads as
 *   "a few", never a count that may be out of date;
 * - Free shipping: the final price alone reaches the Free Shipping method's
 *   minimum subtotal (the price is in display currency, the minimum in
 *   base: compared in base).
 */
class Badges implements ProductBadgeProviderInterface
{
    public const ATTRIBUTE = 'zero_badge';

    public function __construct(
        private readonly Config $config,
        private readonly LowStock $lowStock,
        private readonly CachedPrices $prices,
        private readonly Amounts $amounts
    ) {
    }

    public function getBadges(Product $product): array
    {
        $badges = [];
        $custom = trim((string) $product->getData(self::ATTRIBUTE));
        if ($custom !== '' && $this->config->showsCustom()) {
            $badges[] = ['code' => 'custom', 'label' => mb_substr($custom, 0, 40), 'sort' => 10];
        }
        $threshold = $this->config->lowStockQty();
        if ($threshold > 0) {
            $qty = $this->lowStock->qty($product);
            if ($qty !== null && $qty > 0 && $qty <= $threshold) {
                $badges[] = ['code' => 'low-stock', 'label' => (string) __('Only a few left'), 'sort' => 40];
            }
        }
        $freeFrom = $this->config->freeShippingFrom();
        if ($freeFrom > 0 && $this->amounts->toBase($this->prices->get($product)['final']) >= $freeFrom) {
            $badges[] = ['code' => 'free-shipping', 'label' => (string) __('Free shipping'), 'sort' => 50];
        }

        return $badges;
    }
}
