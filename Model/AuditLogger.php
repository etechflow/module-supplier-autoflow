<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Records supplier-active flips + price recomputations to the audit table
 * (v0.1.0). Read by the admin grid (v0.1.1+) and useful for finance to
 * trace "why did this product's price change at X time?"
 *
 * Writes go to `etechflow_supplier_autoflow_log` (see etc/db_schema.xml).
 * Failures are caught and logged at error-level rather than thrown — an
 * audit-log write should NEVER break the actual business operation.
 */
class AuditLogger
{
    public const EVENT_AUTO_TOGGLE         = 'auto_toggle';
    public const EVENT_REPRICE             = 'reprice';
    public const EVENT_NO_ACTIVE_SUPPLIER  = 'no_active_supplier';
    public const EVENT_ERROR               = 'error';

    public const TRIGGER_STOCK_SAVE        = 'stock_save';
    public const TRIGGER_MSI_SOURCE_SAVE   = 'msi_source_items_save';
    public const TRIGGER_PRODUCT_SAVE      = 'product_save';
    public const TRIGGER_CRON              = 'cron';
    public const TRIGGER_CLI_RESYNC        = 'cli_resync';

    private const TABLE = 'etechflow_supplier_autoflow_log';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array{
     *     product_id:        int,
     *     sku?:              string|null,
     *     event_type:        string,
     *     trigger_source:    string|null,
     *     old_active_slot?:  string|null,
     *     new_active_slot?:  string|null,
     *     old_price?:        float|null,
     *     new_price?:        float|null,
     *     old_special_price?: float|null,
     *     new_special_price?: float|null,
     *     message?:          string|null
     * } $row
     */
    public function log(array $row): void
    {
        try {
            $connection = $this->resource->getConnection();
            $connection->insert($this->resource->getTableName(self::TABLE), [
                'product_id'        => (int) $row['product_id'],
                'sku'               => $row['sku'] ?? null,
                'event_type'        => (string) $row['event_type'],
                'trigger_source'    => $row['trigger_source'] ?? null,
                'old_active_slot'   => $row['old_active_slot'] ?? null,
                'new_active_slot'   => $row['new_active_slot'] ?? null,
                'old_price'         => $row['old_price'] ?? null,
                'new_price'         => $row['new_price'] ?? null,
                'old_special_price' => $row['old_special_price'] ?? null,
                'new_special_price' => $row['new_special_price'] ?? null,
                'message'           => $row['message'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ETechFlow_SupplierAutoflow: audit log write failed.',
                ['product_id' => $row['product_id'] ?? null, 'exception' => $e->getMessage()]
            );
        }
    }
}
