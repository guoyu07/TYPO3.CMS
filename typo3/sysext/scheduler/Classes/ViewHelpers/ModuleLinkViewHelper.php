<?php
namespace TYPO3\CMS\Scheduler\ViewHelpers;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * Create internal link within backend app
 * @internal
 */
class ModuleLinkViewHelper extends AbstractViewHelper
{
    /**
     * Initializes the arguments
     */
    public function initializeArguments()
    {
        parent::initializeArguments();
        $this->registerArgument('controller', 'string', 'The "controller" of scheduler. Possible values are "scheduler", "check", "info"', true);
        $this->registerArgument('action', 'string', 'The action to be called within each controller', true);
        $this->registerArgument('arguments', 'array', '', false, []);
    }

    /**
     * Render module link with command and arguments
     *
     * @return string
     */
    public function render()
    {
        return static::renderStatic(
            $this->arguments,
            $this->buildRenderChildrenClosure(),
            $this->renderingContext
        );
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     *
     * @return string
     */
    public static function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext)
    {
        $moduleArguments = [];
        $moduleArguments['SET']['function'] = $arguments['controller'];
        $moduleArguments['CMD'] = $arguments['action'];
        if (!empty($arguments['arguments'])) {
            $moduleArguments['tx_scheduler'] = $arguments['arguments'];
        }

        return BackendUtility::getModuleUrl('system_txschedulerM1', $moduleArguments);
    }
}
