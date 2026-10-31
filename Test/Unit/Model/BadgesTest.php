<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductLabels\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\TestCase;
use Zero\Core\Model\Pricing\Amounts;
use Zero\Core\Model\Pricing\CachedPrices;
use Zero\ProductLabels\Model\Badges;
use Zero\ProductLabels\Model\Config;
use Zero\ProductLabels\Model\LowStock;

class BadgesTest extends TestCase
{
    /**
     * @param array{custom?: bool, low?: float, free?: float} $on
     */
    private function badges(array $on, float $final, ?float $qty, string $own = '', ?Amounts $amounts = null): array
    {
        $config = $this->createStub(Config::class);
        $config->method('showsCustom')->willReturn($on['custom'] ?? true);
        $config->method('lowStockQty')->willReturn($on['low'] ?? 5.0);
        $config->method('freeShippingFrom')->willReturn($on['free'] ?? 0.0);
        $prices = $this->createStub(CachedPrices::class);
        $prices->method('get')->willReturn(['regular' => $final, 'final' => $final]);
        $lowStock = $this->createStub(LowStock::class);
        $lowStock->method('qty')->willReturn($qty);
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnCallback(static fn ($key) => $key === Badges::ATTRIBUTE ? $own : null);

        if ($amounts === null) {
            $amounts = $this->createStub(Amounts::class);
            $amounts->method('toBase')->willReturnArgument(0);
        }

        return array_column((new Badges($config, $lowStock, $prices, $amounts))->getBadges($product), 'label', 'code');
    }

    public function testEveryBadgeWhenItApplies(): void
    {
        $this->assertSame(
            ['custom' => 'Bestseller', 'low-stock' => 'Only a few left', 'free-shipping' => 'Free shipping'],
            $this->badges(['free' => 100.0], 120.0, 3.0, '  Bestseller ')
        );
    }

    public function testNothingAboveTheThresholdsOrSwitchedOff(): void
    {
        $this->assertSame([], $this->badges(['free' => 200.0], 100.0, 6.0));
        $this->assertSame([], $this->badges(['custom' => false, 'low' => 0.0], 120.0, 3.0, 'Bestseller'));
    }

    public function testNoLowStockBadgeWhenTheQuantityIsntCountedOrIsGone(): void
    {
        $this->assertSame([], $this->badges([], 10.0, null));
        $this->assertSame([], $this->badges([], 10.0, 0.0));
    }

    public function testFreeShippingComparesInBaseCurrency(): void
    {
        // A display currency at 0.8 of base: 80 shown is 100 in base, the minimum's currency.
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('convert')->willReturnCallback(static fn ($amount) => (float) $amount * 0.8);
        $amounts = new Amounts($priceCurrency);

        $this->assertSame(['free-shipping' => 'Free shipping'], $this->badges(['free' => 100.0], 80.0, null, '', $amounts));
        $this->assertSame([], $this->badges(['free' => 100.0], 79.0, null, '', $amounts));
        // Compared as shown, 90 would miss the minimum of 100.
        $this->assertSame(['free-shipping' => 'Free shipping'], $this->badges(['free' => 100.0], 90.0, null, '', $amounts));
    }
}
