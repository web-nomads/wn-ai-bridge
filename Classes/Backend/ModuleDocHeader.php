<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Backend;

use TYPO3\CMS\Backend\Template\ModuleTemplate;

/**
 * Doc header of the modules: breadcrumb only, no button bar
 */
final class ModuleDocHeader
{
    /**
     * Drops the automatic reload button (TYPO3 v14), the only button; without buttons the bar is not rendered
     */
    public static function withoutButtonBar(ModuleTemplate $moduleTemplate): ModuleTemplate
    {
        $docHeader = $moduleTemplate->getDocHeaderComponent();
        // v13 has no automatic reload button
        if (method_exists($docHeader, 'disableAutomaticReloadButton')) {
            $docHeader->disableAutomaticReloadButton();
        }

        return $moduleTemplate;
    }
}
