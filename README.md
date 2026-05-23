# ETechFlow Supplier Autoflow

Auto-toggle supplier active flags based on stock, then reprice products from
the first-active supplier's `cost × markup`. For Magento 2 stores with
multi-supplier products and dynamic supplier-driven pricing.

## What it does

You sell the same product through multiple suppliers in priority order:

```
S1 = Onlyda          (your manufacturer; cheap; only ships from your own stock)
S2 = Auto Remote     (drop-ship; more expensive; ships when Onlyda is out)
S3 = Remkeys         (drop-ship fallback)
```

This module makes that workflow automatic:

1. **Auto-toggle**: when Onlyda stock hits 0, flip S1's `active` flag off.
   The product now fulfills from S2 = Auto Remote.
2. **Reprice**: with S2 now first-active, recompute the customer-facing price
   as `S2.cost × (1 + S2.markup / 100)`. Apply your configured rounding +
   anchor strategy. Write to `price` and/or `special_price` per your output
   target.
3. **Audit**: every flip + reprice is logged to `etechflow_supplier_autoflow_log`
   so finance can trace why a price changed.
4. **NDE integration**: if you also run `etechflow/module-next-day-eligibility`,
   the module synchronously triggers NDE's evaluator after every change.
   `next_day_eligible` stays in sync with the active supplier — no event-bus
   relay required.

## Everything is merchant-configurable

No supplier names, no attribute codes, no markup percentages are hardcoded.
All configuration lives in
`Stores → Configuration → eTechFlow → Supplier Autoflow`.

### Supplier slot definition

One slot per line, in priority order (top = highest priority):

```
S1|s1_active|s1|s1_cost|s1_markup
S2|s2_active|s2|s2_cost|s2_markup
S3|s3_active|s3|s3_cost|s3_markup
```

Format: `label|active_attr|name_attr|cost_attr|markup_attr[|stock_source_or_qty_attr]`.

### Stock-dependent suppliers

Name-based, not slot-position-based. Lists which supplier *names* get the
auto-toggle behaviour:

```
Onlyda
OurOwnWarehouse
```

Other slots (drop-ship suppliers) stay manual-only. The merchant decides
when to disable them.

### Stock trigger source

Pick from: `magento_qty`, `msi_default`, `msi_per_slot`, `per_slot_qty_attr`,
`disabled`. MSI modes soft-detected — module installs and works on non-MSI
builds.

### Price output target

- `price` — write to regular price only.
- `special_price` — write to special price only.
- `special_price_with_anchor` (recommended) — write computed value to
  `special_price`, write `price = special_price × anchor_multiplier`.
  Renders strikethrough on storefront + sale-pricing on Google Shopping.

### Rounding

`2dp`, `5p`, `10p`, `99p_ending`, or `none`.

### No-active-supplier fallback

When every slot is inactive (all suppliers out): set product to
out-of-stock, disable it entirely, or leave unchanged with a warning.

## How it stays accurate

1. **Legacy stock event observer** — `cataloginventory_stock_item_save_after`.
2. **MSI source-items plugin** — `Magento\InventoryApi\Api\SourceItemsSaveInterface`,
   soft-detected so non-MSI installs skip it cleanly.
3. **Product-save observer** — re-runs the pricing engine when a merchant
   manually flips a slot's active flag or changes cost/markup.
4. **Hourly cron** — belt-and-braces safety net for any propagation hole.
5. **CLI** — `bin/magento etechflow:autoflow:resync [--sku=...]` for
   manual full-catalogue evaluation.
6. **FPC tag invalidation** — `cat_p_<id>` clean after every price write,
   so customers see fresh HTML without a manual `cache:flush`.

## Reverse toggle

When stock comes back (Onlyda restocked), the previously auto-toggled slot
flips back to active and the product reprices from THAT slot's current
cost — handles supplier cost changes on restock.

Configurable on/off — some merchants want one-way toggles only.

## Audit log

Every event writes to `etechflow_supplier_autoflow_log` with:

- Product ID + SKU
- Event type: `auto_toggle` / `reprice` / `no_active_supplier` / `error`
- Trigger source: `stock_save` / `msi_source_items_save` / `product_save` / `cron` / `cli_resync`
- Before/after active slot
- Before/after price + special_price
- Human-readable message

Read via the admin grid (v0.1.1+ — coming next) or directly via SQL.

## Companion modules

- **`etechflow/module-next-day-eligibility`** — when installed, Autoflow's
  active-flag changes automatically trigger NDE's eligibility recompute.
  Recommended together for stores running dynamic next-day rules.

## Versioning

v0.1.0 — initial release. Engine complete; admin audit-log grid lands in
v0.1.1.
