<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductLabels\Model;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * How many of a product can still be sold, for the "Only a few left" badge:
 * what MSI calls the salable quantity (the stock's quantity less the open
 * reservations and the item's minimum), or the stock item's quantity without
 * MSI. Null for a product that isn't counted (a configurable, a bundle, stock
 * not managed) or has none.
 *
 * Asked product by product (every card of a list) but answered from one set
 * of queries per batch: the products a loaded collection holds are expected
 * (Observer\RememberCollection) and fetched together the first time one of
 * them is asked about. MSI's own service cost five or six queries a product.
 */
class LowStock
{
    private const COUNTED_TYPES = ['simple', 'virtual'];
    private const STOCK_RESOLVER = 'Magento\InventorySalesApi\Api\StockResolverInterface';
    private const INDEX_TABLE_RESOLVER = 'Magento\InventoryIndexer\Model\StockIndexTableNameResolverInterface';
    private const BATCH = 500;

    /** @var array<int, float|null> product id => quantity */
    private array $loaded = [];
    /** @var array<int, string> product id => SKU, to fetch with the next question */
    private array $pending = [];
    private ?int $stockId = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly StoreManagerInterface $storeManager,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Products that will be asked about: fetched together with the first one.
     *
     * @param iterable<Product> $products
     */
    public function expect(iterable $products): void
    {
        foreach ($products as $product) {
            $id = (int) $product->getId();
            if ($id && !array_key_exists($id, $this->loaded) && !isset($this->pending[$id])
                && $this->isCounted($product) && count($this->pending) < self::BATCH
            ) {
                $this->pending[$id] = (string) $product->getSku();
            }
        }
    }

    public function qty(Product $product): ?float
    {
        $id = (int) $product->getId();
        if (!$id || !$this->isCounted($product)) {
            return null;
        }
        if (!array_key_exists($id, $this->loaded)) {
            $this->pending[$id] = (string) $product->getSku();
            $this->load();
        }

        return $this->loaded[$id];
    }

    private function isCounted(Product $product): bool
    {
        return (string) $product->getSku() !== '' && in_array($product->getTypeId(), self::COUNTED_TYPES, true);
    }

    private function load(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        try {
            $quantities = $this->quantities($pending);
        } catch (\Exception $e) {
            $quantities = [];
        }
        foreach (array_keys($pending) as $id) {
            $this->loaded[$id] = $quantities[$id] ?? null;
        }
    }

    /**
     * @param array<int, string> $pending product id => SKU
     * @return array<int, float> product id => quantity, for the managed, in-stock ones
     */
    private function quantities(array $pending): array
    {
        $connection = $this->resource->getConnection();
        // The stock item: whether its stock is managed and in stock, and its minimum (the default or its own).
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName('cataloginventory_stock_item'),
                    ['product_id', 'qty', 'is_in_stock', 'manage_stock', 'use_config_manage_stock', 'min_qty', 'use_config_min_qty']
                )
                ->where('product_id IN (?)', array_keys($pending))
                ->where('website_id = ?', 0)
        );
        $managedByDefault = (bool) $this->stockConfiguration->getManageStock();
        $minByDefault = (float) $this->stockConfiguration->getMinQty();
        $counted = [];
        foreach ($rows as $row) {
            $managed = $row['use_config_manage_stock'] ? $managedByDefault : (bool) $row['manage_stock'];
            if (!$managed || !$row['is_in_stock']) {
                continue;
            }
            $counted[(int) $row['product_id']] = [
                'qty' => (float) $row['qty'],
                'min' => $row['use_config_min_qty'] ? $minByDefault : (float) $row['min_qty'],
            ];
        }
        if (!$counted) {
            return [];
        }
        $msi = $this->msiQuantities(array_intersect_key($pending, $counted));
        $result = [];
        foreach ($counted as $id => $item) {
            $qty = $msi === null ? $item['qty'] : ($msi[$pending[$id]] ?? null);
            if ($qty !== null) {
                $result[$id] = $qty - $item['min'];
            }
        }

        return $result;
    }

    /**
     * MSI: the stock index's quantity plus the open reservations (negative once
     * an order is placed), by SKU, for the salable ones; null without MSI.
     *
     * @param array<int, string> $pending product id => SKU
     * @return array<string, float>|null
     */
    private function msiQuantities(array $pending): ?array
    {
        // Asked for here, not injected: MSI may be off (the classes then don't exist).
        if (!interface_exists(self::STOCK_RESOLVER) || !interface_exists(self::INDEX_TABLE_RESOLVER)) {
            return null;
        }
        $skus = array_values($pending);
        $stockId = $this->stockId();
        $connection = $this->resource->getConnection();
        $index = $connection->fetchPairs(
            $connection->select()
                ->from($this->objectManager->get(self::INDEX_TABLE_RESOLVER)->execute($stockId), ['sku', 'quantity'])
                ->where('sku IN (?)', $skus)
                ->where('is_salable = ?', 1)
        );
        $reserved = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('inventory_reservation'), ['sku', new \Zend_Db_Expr('SUM(quantity)')])
                ->where('stock_id = ?', $stockId)
                ->where('sku IN (?)', $skus)
                ->group('sku')
        );
        $result = [];
        foreach ($index as $sku => $quantity) {
            $result[(string) $sku] = (float) $quantity + (float) ($reserved[$sku] ?? 0);
        }

        return $result;
    }

    private function stockId(): int
    {
        if ($this->stockId === null) {
            $website = $this->storeManager->getWebsite();
            $this->stockId = (int) $this->objectManager->get(self::STOCK_RESOLVER)
                ->execute('website', (string) $website->getCode())->getStockId();
        }

        return $this->stockId;
    }
}
