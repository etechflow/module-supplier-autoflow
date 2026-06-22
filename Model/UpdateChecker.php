<?php
declare(strict_types=1);
namespace ETechFlow\SupplierAutoflow\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\HTTP\Client\CurlFactory;

class UpdateChecker
{
    private const PACKAGE     = 'etechflow/module-supplier-autoflow';
    private const LATEST_URL  = 'https://license-service.etechflow.com/composer/latest/etechflow/module-supplier-autoflow.json';
    private const CACHE_KEY   = 'etechflow_saf_update_check';
    private const CACHE_TTL   = 21600;
    private const MODULE_NAME = 'ETechFlow_SupplierAutoflow';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly CacheInterface $cache,
        private readonly ResourceConnection $resource
    ) {}

    public function getAvailableUpdate(): ?array
    {
        try {
            $latest = $this->fetchLatest();
            if (empty($latest['version'])) return null;
            $installed = $this->installedVersion();
            if ($installed === '' || version_compare($installed, $latest['version'], '>=')) return null;
            return ['installed' => $installed, 'latest' => $latest['version'],
                    'notes' => $latest['notes'], 'package' => self::PACKAGE];
        } catch (\Throwable $e) { return null; }
    }

    public function getUpdateCommand(): string { return 'composer update ' . self::PACKAGE; }

    private function fetchLatest(): array
    {
        $raw = $this->cache->load(self::CACHE_KEY);
        if (!$raw) {
            $raw = '{}';
            try {
                $curl = $this->curlFactory->create(); $curl->setTimeout(5);
                $curl->get(self::LATEST_URL);
                if ((int)$curl->getStatus() === 200) $raw = (string)$curl->getBody();
            } catch (\Throwable $e) { $raw = '{}'; }
            $this->cache->save($raw, self::CACHE_KEY, [], self::CACHE_TTL);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['latest_version'])) return ['version' => '', 'notes' => ''];
        return ['version' => (string)$data['latest_version'], 'notes' => (string)($data['release_notes'] ?? '')];
    }

    private function installedVersion(): string
    {
        // Primary: the module's own composer.json version. This always matches
        // the physically-installed package, independent of the setup_module
        // schema_version (which may lag the Composer release numbering).
        try {
            $registrar = new \Magento\Framework\Component\ComponentRegistrar();
            $path = $registrar->getPath(\Magento\Framework\Component\ComponentRegistrar::MODULE, self::MODULE_NAME);
            if ($path) {
                $composerFile = $path . '/composer.json';
                if (is_file($composerFile)) {
                    $data = json_decode((string)file_get_contents($composerFile), true);
                    if (is_array($data) && !empty($data['version'])) {
                        return ltrim((string)$data['version'], 'v');
                    }
                }
            }
        } catch (\Throwable $e) {}
        // Fallback: setup_module schema_version.
        try {
            $v = $this->resource->getConnection()->fetchOne(
                'SELECT schema_version FROM ' . $this->resource->getTableName('setup_module') . ' WHERE module = ?',
                [self::MODULE_NAME]);
            return $v ? (string)$v : '';
        } catch (\Throwable $e) { return ''; }
    }
}
