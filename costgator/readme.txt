=== CostGator Margin Audit for WooCommerce ===
Contributors: feriman
Tags: woocommerce, profit, margin, cost of goods, coupons
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Test a store-wide discount before you launch it. Find WooCommerce products, sales and percentage coupons that could sell below cost or your margin.

== Description ==

Planning a store-wide sale? Run a scan, then enter the discount in Promotion Preflight. You see which products with a known cost would drop below cost or below your minimum margin, before the first order comes in.

CostGator also checks your published products and variations, and shows where a current price, a scheduled sale or a percentage coupon would put a product below cost or below your minimum margin.

Click **Scan My Store** under WooCommerce > CostGator and get a clear list of:

* **Products selling below cost.** The current price, including running sales, is lower than the unit cost.
* **Scheduled sales at risk.** A sale that has not started yet would push the price below cost or below your margin threshold.
* **Low margin products.** Not below cost, but below the minimum margin you set (10% by default).
* **Risky percentage coupons.** Coupons that would leave eligible products below cost or below your threshold. Product, category and sale item restrictions are respected.
* **Missing cost information.** Products whose profitability cannot be checked.

**Promotion Preflight.** Enter a percentage discount and choose whether it replaces running sales or stacks on top of them. It uses the data of the last scan and skips products without a known cost.

= Read-only and private =

* The audit never changes prices, products, orders or coupons.
* No data leaves your site. The plugin makes no external requests and has no tracking.

= Where costs come from =

* WooCommerce native Cost of Goods Sold (WooCommerce > Settings > Advanced > Features), including variation cost inheritance and additive variation costs.
* If a product has no native cost, the plugin reads the cost fields of the Cost of Goods plugins by SkyVerge (`_wc_cog_cost`) and WPFactory (`_alg_wc_cog_cost`). Developers can add more meta keys with the `costgator_cost_meta_sources` filter.

= How the calculation works =

* Margin is gross margin: (net price - cost) / net price.
* If your prices include tax, they are converted to net with your store base tax rates and the product tax class before comparison. Costs are assumed to be net of tax and in your store currency.
* Only product price and cost are compared. Shipping, payment fees, marketing and other costs are not included, so this is not a full profit calculation.
* Coupon checks are potential risks. Minimum spend, customer restrictions and coupon combinations are not evaluated. Fixed amount coupons are not analyzed in this version.

= Trademarks =

WooCommerce is a registered trademark of Automattic Inc. This plugin is not affiliated with or endorsed by Automattic or WooCommerce.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Enable Cost of Goods Sold under WooCommerce > Settings > Advanced > Features and enter your product costs, or use a supported cost plugin.
3. Go to WooCommerce > CostGator and click Scan My Store.

== Frequently Asked Questions ==

= Does it change anything in my store? =

No. It only reads products and coupons. It stores the last scan result and your threshold setting in the options table, and removes them on uninstall.

= Why does a product with a cost of 0 appear as missing cost? =

The WooCommerce native Cost of Goods Sold feature stores a zero cost on simple products as empty, so a zero cost and a missing cost cannot be told apart. To keep the results consistent, a cost of 0 is treated as missing for every cost source.

= How large a catalog can it handle? =

The scan runs in small batches from your browser, which keeps each request short and lowers the risk of server time limits. It was tested with more than 12,000 products and variations. Keep the page open until the scan finishes.

= Which products are checked? =

Published simple products and the enabled variations of published variable products. Drafts, private products, disabled variations, external and grouped products are skipped.

= Is there a Pro version? =

CostGator Pro is planned, with scheduled audits and email alerts, coupon combination analysis, and per-category margin rules with CSV export. The price and release date are not set yet. You can join the waitlist at [getcostgator.com](https://getcostgator.com/). The free plugin stays free.

== Screenshots ==

1. Promotion Preflight: see which products a planned discount would push below cost or below your margin threshold.
2. Scan results: products selling below cost, scheduled sales at risk and low margin products.
3. Risky coupons and products with missing cost information.

== Support ==

Ask questions and report problems in the [support forum](https://wordpress.org/support/plugin/costgator/). Please include your WooCommerce version and where your product costs come from.

== Changelog ==

= 1.0.2 =
* Promotion Preflight now says how many rows are not shown when more than 100 products are affected.
* Added a one-time review request on the CostGator screen. It appears after a scan with known costs and goes away for good with either button.

= 1.0.1 =
* The summary card now reads "Products at risk" instead of "Total at risk".
* Added a link to the CostGator Pro waitlist on the plugin page.

= 1.0.0 =
* First stable release. No functional changes since 0.1.0.

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 1.0.2 =
Preflight shows how many rows are hidden, and a one-time review request.

= 1.0.1 =
Small label fix and a link to the Pro waitlist.

= 1.0.0 =
First stable release.
