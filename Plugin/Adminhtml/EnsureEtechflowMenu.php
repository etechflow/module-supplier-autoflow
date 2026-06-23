<?php
/**
 * Self-contained provider for the shared eTechFlow top-level admin menu node.
 *
 * The eTechFlow suite shares ONE top-level admin menu node, `eTechFlow::root`.
 * Magento merges every module's adminhtml/menu.xml and enforces a UNIQUE
 * add/@id (menu.xsd uniqueAddItemId), so two modules cannot each
 * <add id="eTechFlow::root"> -- that is the duplicate-id admin-menu fatal.
 *
 * Permanent, dependency-free pattern: NO module declares the node in menu.xml
 * (they only parent="eTechFlow::root" reference it); each module injects the
 * node here, programmatically and idempotently. The first eTechFlow module
 * whose plugin runs adds the command; any later module makes
 * Builder::processCommand() call Add::chain(), which throws -- caught and
 * ignored below. Net: EXACTLY ONE eTechFlow::root regardless of how many
 * eTechFlow modules are installed, every module works standalone, no module
 * depends on any other.
 */
declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Plugin\Adminhtml;

use Magento\Backend\Model\Menu;
use Magento\Backend\Model\Menu\Builder;
use Magento\Backend\Model\Menu\Builder\Command\Add;

class EnsureEtechflowMenu
{
    public function beforeGetResult(Builder $subject, Menu $menu): array
    {
        try {
            $subject->processCommand(new Add([
                'id'        => 'eTechFlow::root',
                'title'     => 'eTechFlow',
                'module'    => 'ETechFlow_SupplierAutoflow',
                'sortOrder' => 68,
                'resource'  => 'Magento_Backend::admin',
            ]));
        } catch (\InvalidArgumentException $e) {
            // eTechFlow::root was already added by another eTechFlow module
            // (or a legacy menu.xml declaration). Exactly one is required -- ignore.
        }
        return [$menu];
    }
}
