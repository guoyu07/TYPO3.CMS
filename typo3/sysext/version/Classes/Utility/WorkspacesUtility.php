<?php
namespace TYPO3\CMS\Version\Utility;

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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\RootLevelRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Library with Workspace related functionality
 */
class WorkspacesUtility
{
    /**
     * Building tcemain CMD-array for swapping all versions in a workspace.
     *
     * @param int $wsid Real workspace ID, cannot be ONLINE (zero).
     * @param bool $doSwap If set, then the currently online versions are swapped into the workspace in exchange for the offline versions. Otherwise the workspace is emptied.
     * @param int $pageId
     * @return array Command array for tcemain
     */
    public function getCmdArrayForPublishWS($wsid, $doSwap, $pageId = 0)
    {
        $wsid = (int)$wsid;
        $cmd = [];
        if ($wsid >= -1 && $wsid !== 0) {
            // Define stage to select:
            $stage = -99;
            if ($wsid > 0) {
                $workspaceRec = BackendUtility::getRecord('sys_workspace', $wsid);
                if ($workspaceRec['publish_access'] & 1) {
                    $stage = 10;
                }
            }
            // Select all versions to swap:
            $versions = $this->selectVersionsInWorkspace($wsid, 0, $stage, $pageId ? $pageId : -1);
            // Traverse the selection to build CMD array:
            foreach ($versions as $table => $records) {
                foreach ($records as $rec) {
                    // Build the cmd Array:
                    $cmd[$table][$rec['t3ver_oid']]['version'] = [
                        'action' => 'swap',
                        'swapWith' => $rec['uid'],
                        'swapIntoWS' => $doSwap ? 1 : 0
                    ];
                }
            }
        }
        return $cmd;
    }

    /**
     * Select all records from workspace pending for publishing
     * Used from backend to display workspace overview
     * User for auto-publishing for selecting versions for publication
     *
     * @param int $wsid Workspace ID. If -99, will select ALL versions from ANY workspace. If -98 will select all but ONLINE. >=-1 will select from the actual workspace
     * @param int $filter Lifecycle filter: 1 = select all drafts (never-published), 2 = select all published one or more times (archive/multiple), anything else selects all.
     * @param int $stage Stage filter: -99 means no filtering, otherwise it will be used to select only elements with that stage. For publishing, that would be "10
     * @param int $pageId Page id: Live page for which to find versions in workspace!
     * @return array Array of all records uids etc. First key is table name, second key incremental integer. Records are associative arrays with uid and t3ver_oid fields. The REAL pid of the online record is found as "realpid
     */
    public function selectVersionsInWorkspace($wsid, $filter = 0, $stage = -99, $pageId = -1)
    {
        $wsid = (int)$wsid;
        $filter = (int)$filter;
        $pageId = (int)$pageId;
        $stage = (int)$stage;
        $output = [];
        // Traversing all tables supporting versioning:
        foreach ($GLOBALS['TCA'] as $table => $cfg) {
            if ($GLOBALS['TCA'][$table]['ctrl']['versioningWS']) {
                // Select all records from this table in the database from the workspace
                // This joins the online version with the offline version as tables A and B
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable($table);
                $queryBuilder->getRestrictions()
                    ->removeAll()
                    ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                $queryBuilder
                    ->select('A.uid', 'A.t3ver_oid', 'B.pid AS realpid')
                    ->from($table, 'A')
                    ->from($table, 'B')
                    ->where(
                        $queryBuilder->expr()->eq('A.pid', -1),
                        $queryBuilder->expr()->gt('B.pid', 0),
                        $queryBuilder->expr()->eq('A.t3ver_oid', $queryBuilder->quoteIdentifier('B.uid'))
                    );

                if ($pageId !== -1) {
                    if ($table === 'pages') {
                        $queryBuilder->andWhere($queryBuilder->expr()->eq('B.uid', $pageId));
                    } else {
                        $queryBuilder->andWhere($queryBuilder->expr()->eq('B.pid', $pageId));
                    }
                }

                if ($wsid > -98) {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq('A.t3ver_wsid', $wsid));
                } elseif ($wsid === -98) {
                    $queryBuilder->andWhere($queryBuilder->expr()->neq('A.t3ver_wsid', 0));
                }

                if ($stage !== -99) {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq('A.t3ver_stage', $stage));
                }

                if ($filter === 1) {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq('A.t3ver_count', 0));
                } elseif ($filter === 2) {
                    $queryBuilder->andWhere($queryBuilder->expr()->gt('A.t3ver_count', 0));
                }

                $rows = $queryBuilder->execute()->fetchAll();
                if (!empty($rows)) {
                    $output[$table] = $rows;
                }
            }
        }
        return $output;
    }

    /****************************
     *
     * Scheduler methods
     *
     ****************************/
    /**
     * This method is called by the Scheduler task that triggers
     * the autopublication process
     * It searches for workspaces whose publication date is in the past
     * and publishes them
     *
     * @return void
     */
    public function autoPublishWorkspaces()
    {
        // Temporarily set admin rights
        // @todo once workspaces are cleaned up a better solution should be implemented
        $currentAdminStatus = $GLOBALS['BE_USER']->user['admin'];
        $GLOBALS['BE_USER']->user['admin'] = 1;
        // Select all workspaces that needs to be published / unpublished:
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(RootLevelRestriction::class));

        $result = $queryBuilder
            ->select('uid', 'swap_modes', 'publish_time', 'unpublish_time')
            ->from('sys_workspace')
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->andX(
                        $queryBuilder->expr()->neq('publish_time', 0),
                        $queryBuilder->expr()->lte('publish_time', (int)$GLOBALS['EXEC_TIME'])

                    ),
                    $queryBuilder->expr()->andX(
                        $queryBuilder->expr()->eq('publish_time', 0),
                        $queryBuilder->expr()->neq('unpublish_time', 0),
                        $queryBuilder->expr()->lte('unpublish_time', (int)$GLOBALS['EXEC_TIME'])
                    )
                )
            )
            ->execute();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_workspace');
        while ($rec = $result->fetch()) {
            // First, clear start/end time so it doesn't get select once again:
            $fieldArray = $rec['publish_time'] != 0 ? ['publish_time' => 0] : ['unpublish_time' => 0];

            $connection->update(
                'sys_workspace',
                $fieldArray,
                ['uid' => (int)$rec['uid']]
            );

            // Get CMD array:
            $cmd = $this->getCmdArrayForPublishWS($rec['uid'], $rec['swap_modes'] == 1);
            // $rec['swap_modes']==1 means that auto-publishing will swap versions, not just publish and empty the workspace.
            // Execute CMD array:
            $tce = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\DataHandling\DataHandler::class);
            $tce->start([], $cmd);
            $tce->process_cmdmap();
        }
        // Restore admin status
        $GLOBALS['BE_USER']->user['admin'] = $currentAdminStatus;
    }
}
