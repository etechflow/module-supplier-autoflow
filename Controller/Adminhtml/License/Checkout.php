<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Controller\Adminhtml\License;

use ETechFlow\SupplierAutoflow\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Creates a Stripe Checkout session using the keys entered in
 * Stores -> Config -> eTechFlow -> Supplier Autoflow -> Payment Settings,
 * then redirects the browser to Stripe for card payment.
 */
class Checkout extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_SupplierAutoflow::config';

    private const XML_STRIPE_SECRET = 'etechflow_supplierautoflow/payment/stripe_secret_key';
    private const XML_STRIPE_CURR   = 'etechflow_supplierautoflow/payment/stripe_currency';

    private const MODULE_ID = 'supplier-autoflow';

    public function __construct(
        Context $context,
        private readonly Curl $curl,
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $plan   = trim((string) $this->getRequest()->getPost('plan', ''));
        $name   = trim((string) $this->getRequest()->getPost('name', ''));
        $email  = trim((string) $this->getRequest()->getPost('email', ''));
        $domain = $this->licenseValidator->getCurrentHost();

        if (!$plan) {
            $this->messageManager->addErrorMessage(__('Invalid plan selected.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_saf/license/gate');
        }
        if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->messageManager->addErrorMessage(__('Please enter a valid name and email address.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_saf/license/gate');
        }

        $stripeRaw = trim((string) $this->scopeConfig->getValue(self::XML_STRIPE_SECRET));
        $stripeKey = $stripeRaw !== '' ? trim((string) $this->encryptor->decrypt($stripeRaw)) : '';
        $currency  = strtolower(trim((string) $this->scopeConfig->getValue(self::XML_STRIPE_CURR))) ?: 'usd';

        if (!$stripeKey || str_starts_with($stripeKey, '****')) {
            $this->messageManager->addErrorMessage(
                __('Stripe Secret Key is not configured. Go to Stores -> Config -> eTechFlow -> Supplier Autoflow -> Payment Settings.')
            );
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_saf/license/gate');
        }

        // Price + name come AUTHORITATIVELY from the portal (admin-controlled,
        // recurring or one-time), so the merchant can't tamper with the amount.
        $planInfo = $this->fetchPlanFromPortal($plan, $domain);
        if ($planInfo === null) {
            $this->messageManager->addErrorMessage(__('That plan is not available right now. Please go back and try again.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_saf/license/gate');
        }
        $successUrl = $this->getUrl('etechflow_saf/license/activated')
            . '?session_id={CHECKOUT_SESSION_ID}&plan=' . urlencode($plan)
            . '&domain=' . urlencode($domain) . '&name=' . urlencode($name) . '&email=' . urlencode($email);
        $cancelUrl  = $this->getUrl('etechflow_saf/license/gate');

        $postData = http_build_query([
            'payment_method_types[0]'                              => 'card',
            'line_items[0][price_data][currency]'                  => $currency,
            'line_items[0][price_data][product_data][name]'        => $planInfo['name'] . ' — ETechFlow',
            'line_items[0][price_data][product_data][description]' => 'Supplier Autoflow module for ' . $domain,
            'line_items[0][price_data][unit_amount]'               => $planInfo['amount'],
            'line_items[0][quantity]'                              => 1,
            'mode'                                                 => 'payment',
            'customer_email'                                       => $email,
            'metadata[plan]'                                       => $plan,
            'metadata[domain]'                                     => $domain,
            'metadata[name]'                                       => $name,
            'metadata[email]'                                      => $email,
            'success_url'                                          => $successUrl,
            'cancel_url'                                           => $cancelUrl,
        ]);

        try {
            $this->curl->setTimeout(15);
            $this->curl->addHeader('Authorization', 'Bearer ' . $stripeKey);
            $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            $this->curl->post('https://api.stripe.com/v1/checkout/sessions', $postData);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not connect to Stripe. Please try again.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_saf/license/gate');
        }

        $data = json_decode($body, true);
        if ($status !== 200 || empty($data['url'])) {
            $err = $data['error']['message'] ?? ('Stripe returned status ' . $status);
            $this->messageManager->addErrorMessage(__('Stripe error: %1', $err));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_saf/license/gate');
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl($data['url']);
    }

    /**
     * Look up the chosen plan's name + amount (cents) from the portal's
     * /license/plans endpoint, which reflects the admin's recurring/one-time
     * choice. Returns null if the plan isn't offered for this module/domain.
     *
     * @return array{name:string, amount:int}|null
     */
    private function fetchPlanFromPortal(string $slug, string $domain): ?array
    {
        $portalBase = rtrim(str_replace('/license/validate', '', $this->licenseValidator->getPortalUrl()), '/');
        $url = $portalBase . '/license/plans?module=' . self::MODULE_ID . '&domain=' . urlencode($domain);

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(10);
            $curl->addHeader('Accept', 'application/json');
            $curl->addHeader('ngrok-skip-browser-warning', '1');
            $curl->get($url);
            $status = (int) $curl->getStatus();
            $body   = (string) $curl->getBody();
        } catch (\Throwable) {
            return null;
        }

        if ($status !== 200 || $body === '') {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['plans']) || !is_array($data['plans'])) {
            return null;
        }
        foreach ($data['plans'] as $card) {
            if (($card['slug'] ?? '') === $slug) {
                $amount = (int) ($card['amount_cents'] ?? 0);
                if ($amount <= 0) {
                    return null;
                }
                return ['name' => (string) ($card['name'] ?? 'License'), 'amount' => $amount];
            }
        }
        return null;
    }
}
