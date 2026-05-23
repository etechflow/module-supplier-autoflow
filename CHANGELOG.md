# Changelog — ETechFlow Supplier Autoflow

All notable changes to this module. Adheres to [Semantic Versioning](https://semver.org/).

---

## [0.1.0] — 2026-05-23 — Initial release

First release of `etechflow/module-supplier-autoflow`. Engine complete; admin audit-log grid lands in v0.1.1.

### What it does

You sell the same product through multiple suppliers in priority order:

```
S1 = Onlyda          (manufacturer; cheap; ships from own stock)
S2 = Auto Remote     (drop-ship; more expensive; ships when S1 is out)
S3 = Remkeys         (drop-ship fallback)
```

This module makes that workflow automatic:

1. **Auto-toggle**: when the configured "stock-dependent" supplier's stock hits 0, flip that slot's `active` flag off. Other slots (drop-ship) stay manual-only.
2. **Reprice**: with the next slot now first-active, recompute the customer-facing price as `cost × (1 + markup/100)`. Apply configured rounding + (optional) anchor strategy for strikethrough pricing. Write to `price` and/or `special_price` per the configured output target.
3. **Audit**: every flip + reprice writes to `etechflow_supplier_autoflow_log` so finance can trace price changes.
4. **NDE integration** (when `etechflow/module-next-day-eligibility` is installed): synchronously calls NDE's `EligibilityEvaluator` after every change so `next_day_eligible` reflects the new active supplier. Pairs cleanly with NDE v1.6.4's first-active-wins mode.

### Everything is merchant-configurable

No supplier names, no attribute codes, no markup percentages are hardcoded. All configuration lives in `Stores → Configuration → eTechFlow → Supplier Autoflow`:

- **Supplier slot definitions** — pipe-separated lines, priority-ordered (top = highest)
  Format: `label|active_attr|name_attr|cost_attr|markup_attr[|stock_source_or_qty_attr]`
- **Stock-dependent supplier names** — case-insensitive list of slots that get auto-toggle behaviour. Other slots stay manual.
- **Stock trigger source** — five modes: `magento_qty` / `msi_default` / `msi_per_slot` / `per_slot_qty_attr` / `disabled`. MSI modes soft-detected.
- **Price output target** — `price` / `special_price` / `special_price_with_anchor` (recommended for retail strikethrough pricing).
- **Anchor multiplier** — for `special_price_with_anchor` mode. Default 1.40 (regular price renders 40% higher than special). Optional per-product attribute override.
- **Rounding** — `2dp` / `5p` / `10p` / `99p_ending` / `none`.
- **No-active-supplier fallback** — `out_of_stock` / `disable_product` / `leave_with_warning`.
- **Reverse toggle on restock** — when stock returns, re-activate the slot and reprice from its current cost.

### Architecture

Six service classes + two observers + one plugin + one cron + one CLI:

- `Model/Config.php` — typed config reader (every knob)
- `Model/Slot.php` — immutable struct for one supplier slot
- `Model/SlotResolver.php` — first-active iteration with per-request memoization
- `Model/PricingEngine.php` — `cost × markup`, rounding, output target
- `Model/PriceResult.php` — immutable return value
- `Model/StockTrigger.php` — 5 trigger modes (legacy / 3 MSI variants / disabled)
- `Model/AutoToggleService.php` — flips active flags on stock change
- `Model/RepriceService.php` — walks slots → first-active → reprice → audit → notify NDE
- `Model/NdeIntegration.php` — soft-detect NDE, synchronously call evaluator
- `Model/AuditLogger.php` — writes to `etechflow_supplier_autoflow_log`
- `Observer/AutoToggleOnStockChange.php` — legacy `cataloginventory_stock_item_save_after` hook
- `Observer/RepriceOnProductSave.php` — `catalog_product_save_after` hook (manual merchant edits)
- `Plugin/AutoToggleOnMsiSourceItemsSave.php` — MSI source-items save hook (soft-detect)
- `Cron/RecomputePrices.php` — hourly safety net (15-past every hour)
- `Console/Command/ResyncCommand.php` — `bin/magento etechflow:autoflow:resync [--sku=...]`

### Audit log table

`etechflow_supplier_autoflow_log` via `etc/db_schema.xml`:

| Column | Type | Purpose |
|---|---|---|
| log_id | int | PK |
| product_id | int | Catalog product id |
| sku | varchar(128) | SKU snapshot at time of event |
| event_type | varchar(32) | `auto_toggle` / `reprice` / `no_active_supplier` / `error` |
| trigger_source | varchar(64) | `stock_save` / `msi_source_items_save` / `product_save` / `cron` / `cli_resync` |
| old_active_slot, new_active_slot | varchar(64) | Slot labels before/after |
| old_price, new_price | decimal | Regular price before/after |
| old_special_price, new_special_price | decimal | Special price before/after |
| message | text | Human-readable note |
| created_at | timestamp | When |

Indexed on `product_id`, `created_at`, `event_type`. Admin grid for browsing the log lands in v0.1.1.

### Installation

```bash
composer require etechflow/module-supplier-autoflow
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

**Default `Enable Supplier Autoflow` = No.** A fresh install does nothing until you configure your slots + flip the master enable to Yes. This is deliberate — auto-repricing without proper config could mass-overwrite product prices.

### Companion modules

- **`etechflow/module-next-day-eligibility` v1.6.4+** — soft-detected. When installed, Autoflow's supplier active-flag changes synchronously trigger NDE to recompute `next_day_eligible`. Keeps shipping eligibility in sync with the supplier actually fulfilling the order.

### Roadmap

- **v0.1.1** — admin audit-log grid (browse + filter the log table from the admin UI)
- **v0.1.2** — admin notice when slot mappings diverge from NDE's
- **v0.2.0** — bulk price-change preview / dry-run before applying
- **v1.0.0** — first production-stable release after a few merchant deploys
