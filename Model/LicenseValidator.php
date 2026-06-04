<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * License validation for ETechFlow_SupplierAutoflow.
 *
 * Hybrid model (LICENSING_PROTOCOL.md + PORTAL_LICENSING_GUIDE.md):
 *   - SP-XXXX keys  -> portal validation (domain + server IP must match).
 *   - HMAC keys     -> local HMAC-SHA256 per-module key OR shared bundle key.
 *   - "Production Environment = No" bypasses licensing for dev/staging.
 *   - Common dev hostnames auto-detect and bypass.
 *
 * MODULE_ID + SECRET_FRAGMENTS are unique to this module; BUNDLE_ID +
 * BUNDLE_SECRET_FRAGMENTS + XML_PATH_BUNDLE_LICENSE_KEY are byte-identical
 * across EVERY eTechFlow module so a single bundle key activates all of them.
 *
 * IP-block auto-management (portal keys only):
 *   portal returns ip_blocked:true -> clearLicenseKey() + ip_blocked flag = 1.
 *   IP restored -> portal returns valid -> writeLicenseKey() restores from
 *   issued_key + resets ip_blocked = 0. The issued_key fallback ONLY fires
 *   when ip_blocked = 1, so manually clearing the key keeps the module locked.
 */
class LicenseValidator
{
    // per-module config paths
    public const XML_PATH_LICENSE_KEY            = 'etechflow_supplierautoflow/license/license_key';
    public const XML_PATH_ISSUED_KEY             = 'etechflow_supplierautoflow/license/issued_key';
    public const XML_PATH_ISSUED_AT              = 'etechflow_supplierautoflow/license/issued_at';
    public const XML_PATH_IP_BLOCKED             = 'etechflow_supplierautoflow/license/ip_blocked';
    public const XML_PATH_PORTAL_URL             = 'etechflow_supplierautoflow/license/portal_url';
    public const XML_PATH_PRODUCTION_ENVIRONMENT = 'etechflow_supplierautoflow/license/production_environment';

    /** Shared bundle config path — same value across all eTechFlow modules. */
    public const XML_PATH_BUNDLE_LICENSE_KEY = 'etechflow_bundle/license/license_key';

    // portal
    private const DEFAULT_PORTAL_URL   = 'https://nonanarchically-rambunctious-lashay.ngrok-free.dev/license/validate';
    public  const PORTAL_CACHE_TTL     = 30;   // valid result cache (s) — suspensions apply within this window
    public  const PORTAL_CACHE_TTL_BAD = 60;   // invalid result cache (s) — re-check quickly after re-activation

    // cache
    private const CACHE_TAG    = 'ETECHFLOW_SAF';
    private const CACHE_PREFIX = 'etf_saf_lic_';

    // HMAC — per-module (UNIQUE to supplier-autoflow; do not reuse elsewhere)
    private const MODULE_ID = 'supplier-autoflow';

    private const SECRET_FRAGMENTS = [
        'eTF-SAF-2026',
        'q5W8-tR2n',
        'D7kP-mC4v',
        'H3jL-bN9x',
    ];

    // HMAC — shared bundle (MUST be identical in every eTechFlow module)
    private const BUNDLE_ID = 'etechflow-bundle';

    private const BUNDLE_SECRET_FRAGMENTS = [
        'eTF-BUNDLE-2026',
        'k2D9-mP4x',
        'L8nR-vH2j',
        'X7tY-zW5q',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Curl $curl,
        private readonly WriterInterface $configWriter
    ) {
    }

    // public API

    public function isValid(): bool
    {
        $host = $this->getCurrentHost();
        if ($host === '') {
            return false;
        }
        if (!$this->isProductionEnvironment()) {
            return true;
        }
        if ($this->isDevelopmentHost($host)) {
            return true;
        }
        return $this->checkKey($host);
    }

    public function computeKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::MODULE_ID;
        $secret  = implode('', self::SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function computeBundleKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::BUNDLE_ID;
        $secret  = implode('', self::BUNDLE_SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function canonicalize(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    public function getConfiguredKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function getConfiguredBundleKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_BUNDLE_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function isProductionEnvironment(): bool
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PRODUCTION_ENVIRONMENT, ScopeInterface::SCOPE_STORE);
        if ($value === null || $value === '') {
            return true;
        }
        return (bool) $value;
    }

    public function getPortalUrl(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_URL));
        return $value !== '' ? $value : self::DEFAULT_PORTAL_URL;
    }

    public function getCurrentHost(): string
    {
        try {
            $url  = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        } catch (\Exception) {
            return '';
        }
    }

    public function isDevHost(?string $host = null): bool
    {
        $check = $host !== null
            ? $this->canonicalize($host)
            : $this->canonicalize($this->getCurrentHost());
        return $this->isDevelopmentHost($check);
    }

    // private helpers

    private function checkKey(string $host): bool
    {
        $configuredKey = $this->getConfiguredKey();
        if ($configuredKey === '') {
            return false;
        }

        // SP-XXXX subscription key → ALWAYS validate live against the portal
        // (result cached for PORTAL_CACHE_TTL only). There is NO offline grace
        // and NO issued-key fallback: the portal is the single source of truth,
        // so a server-IP mismatch, suspension, or expiry locks the module within
        // the cache window. This is what enforces the domain + server-IP binding.
        if (str_starts_with($configuredKey, 'SP-')) {
            return $this->validateViaPortal($host, $configuredKey);
        }

        // HMAC per-module key (offline; LICENSING_PROTOCOL.md)
        if (hash_equals($this->computeKey($host), $configuredKey)) {
            return true;
        }
        // Shared bundle key
        $bundleKey = $this->getConfiguredBundleKey();
        return $bundleKey !== '' && hash_equals($this->computeBundleKey($host), $bundleKey);
    }

    private function validateViaPortal(string $host, string $key): bool
    {
        $cacheKey = self::CACHE_PREFIX . md5($host . ':' . $key);
        $cached   = $this->cache->load($cacheKey);
        if ($cached !== false) {
            return $cached === '1';
        }

        $url = $this->getPortalUrl()
            . '?domain=' . urlencode($host)
            . '&license_key=' . urlencode($key)
            . '&platform=magento&module=' . self::MODULE_ID;

        $valid  = false;
        $status = 0;
        $body   = '';

        try {
            $this->curl->setTimeout(10);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-SAF/2.1');
            $this->curl->get($url);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Throwable) {
            // Portal unreachable — fail closed for THIS request without caching,
            // so the next request retries. (Strict IP enforcement: if we can't
            // confirm the server IP is authorised, we don't grant access.)
            return false;
        }

        if ($status === 200 && $body !== '') {
            $data  = json_decode($body, true);
            $valid = !empty($data['valid']);
        }
        // Any 403 (ip_blocked / suspended / expired / wrong key) leaves $valid = false.

        $ttl = $valid ? self::PORTAL_CACHE_TTL : self::PORTAL_CACHE_TTL_BAD;
        $this->cache->save($valid ? '1' : '0', $cacheKey, [self::CACHE_TAG], $ttl);

        return $valid;
    }

    private function isDevelopmentHost(string $host): bool
    {
        if ($host === 'localhost' || str_starts_with($host, '127.')) {
            return true;
        }
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) {
            return true;
        }
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) {
            return true;
        }
        foreach (['.test', '.local', '.localhost', '.dev', '.example', '.invalid'] as $s) {
            if (str_ends_with($host, $s)) {
                return true;
            }
        }
        foreach (['staging.', 'stage.', 'dev.', 'qa.', 'uat.', 'test.', 'preview.', 'sandbox.'] as $p) {
            if (str_starts_with($host, $p)) {
                return true;
            }
        }
        // Hyphen-dev pattern intentionally omitted: production domains may contain '-dev'
        foreach (['.magento.cloud', '.magentocloud.com', '.cloud.magento'] as $s) {
            if (str_ends_with($host, $s)) {
                return true;
            }
        }
        foreach (['.ngrok.io', '.ngrok-free.app', '.loca.lt', '.serveo.net', '.ngrok-free.dev'] as $s) {
            if (str_ends_with($host, $s)) {
                return true;
            }
        }
        return false;
    }
}
