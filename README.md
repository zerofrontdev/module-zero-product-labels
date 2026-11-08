# Zero_ProductLabels

More badges on product photos in lists and above the title on the product
page, next to Zero_Core's own New and Sale -N%:

- **The product's own badge**: a "Badge" attribute (Catalog > Products >
  a product, General), a short text per store view: Bestseller, Limited,
  Vegan. Shown first.
- **Only a few left**: while a simple or virtual product's salable quantity
  is at or below a threshold (MSI's salable quantity for the website's
  stock, or the stock item's quantity without MSI), looked up for all the
  products of a list at once (three queries a list, not six a product).
  Cards are cached for up to an hour, so the badge says "a few", never a
  count that may be stale.
- **Free shipping**: while the price alone reaches the Free Shipping
  method's minimum order (Sales > Delivery Methods > Free Shipping, enabled).
  The minimum is in base currency, so a price shown in another currency is
  turned back into base before the comparison.

Each one on or off in Stores > Configuration > Zero > Theme > Product
Badges, the same group as New and Sale (per store view). The own badge is on
by default; Only a few left (threshold 0) and Free shipping are off.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-product-labels
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## How it plugs in

Zero_Core renders the badges (`Zero\Core\ViewModel\ProductBadges`, one
cached block for every card). Modules add theirs through
`Zero\Core\Api\ProductBadgeProviderInterface`, registered in
`etc/frontend/di.xml`; this module is one. Each badge has a `code` (the
class `product-badge-<code>`), a `label` and a `sort` (custom 10, New 20,
Sale 30, Only a few left 40, Free shipping 50). The colours are in
`view/frontend/zero/tailwind.css`, built into the theme's CSS while the
module is enabled; a child theme restyles them like any other class.

## Removing it

The "Badge" attribute and its values stay (product data), and so do the
settings; New and Sale keep working from Zero_Core.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

Zero is made by [Fixstacks](https://fixstacks.com).
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
