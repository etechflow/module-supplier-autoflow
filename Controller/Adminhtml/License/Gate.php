<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Controller\Adminhtml\License;

use ETechFlow\SupplierAutoflow\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * License-required gate page. Shows plan cards + "Enter License Key".
 * Redirects to the Stores grid when the license is already valid.
 */
class Gate extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_SupplierAutoflow::config';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->licenseValidator->isValid()) {
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $redirect->setPath('adminhtml/system_config/edit/section/etechflow_supplierautoflow');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Supplier Autoflow — License Required'));
        $portalBase = rtrim(str_replace('/license/validate', '', $this->licenseValidator->getPortalUrl()), '/');
        $domain     = $this->licenseValidator->getCurrentHost();
        $plansUrl   = $portalBase . '/license/plans?module=supplier-autoflow&domain=' . urlencode($domain);
        $block = $page->getLayout()->getBlock('etechflow.saf.license.gate');
        if ($block) {
            $block->setData('plans_url', $plansUrl);
        }
        return $page;
    }
}
