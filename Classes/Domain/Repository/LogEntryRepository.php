<?php
namespace GeorgRinger\Logging\Domain\Repository;

use GeorgRinger\Logging\Domain\Model\Dto\ClearDemand;
use GeorgRinger\Logging\Domain\Model\Dto\ListDemand;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
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
class LogEntryRepository extends Repository
{
    const TABLE = 'sys_log2';

    /** @var \TYPO3\CMS\Core\Database\DatabaseConnection */
    protected $databaseConnection = null;

    /**
     * @var array Default order is by datetime descending
     */
    protected $defaultOrderings = [
        'datetime' => QueryInterface::ORDER_DESCENDING
    ];

    /**
     * @param ListDemand $demand
     * @return \TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     */
    public function findByDemand(ListDemand $demand = null)
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);

        if (!is_null($demand)) {
            $constraints = $this->createConstraintsFromDemand($query, $demand);
            if (!empty($constraints)) {
                $query->matching(
                    $query->logicalAnd($constraints)
                );
            }
        }

        return $query->execute();
    }

    /**
     * @return array
     */
    public function getAllChannels()
    {
        $out = [];
        $rows = $this->getDatabaseConnection()->exec_SELECTgetRows('channel', self::TABLE, '', 'channel', 'channel ASC');
        foreach ($rows as $row) {
            $out[$row['channel']] = $row['channel'];
        }
        return $out;
    }

    public function getAllUsers()
    {
        $out = ['' => ''];
        $tmp = [];

        $rows = $this->getDatabaseConnection()->exec_SELECTgetRows('user_id,mode', self::TABLE, '(mode="BE" OR mode="FE") AND user_id > 0', 'user_id,mode');
        foreach ($rows as $row) {
            $tmp[$row['mode']][] = $row['user_id'];
        }

        foreach ($tmp as $mode => $users) {
            $table = $mode === 'BE' ? 'be_users' : 'fe_users';
            $rows = $this->getDatabaseConnection()->exec_SELECTgetRows('*', $table, 'uid in (' . implode(',', $users) . ')');
            foreach ($rows as $row) {
                $out[$mode . '_' . $row['uid']] = sprintf('%s: %s (%s)', $mode, $row['username'], $row['uid']);
            }
        }

        return $out;
    }

    /**
     * @param ClearDemand $clear
     * @return bool returns TRUE if any records should have been removed
     */
    public function clearByDemand(ClearDemand $clear)
    {
        $processed = false;

        if ($clear->getAll()) {
            $this->truncateLogTable();
            $processed = true;
        }

        return $processed;
    }

    /**
     * @param QueryInterface $query
     * @param ListDemand $demand
     * @return array
     */
    protected function createConstraintsFromDemand(QueryInterface $query, ListDemand $demand)
    {
        $constraints = [];

        if ($demand->getLevels()) {
            $constraints[] = $query->in('level', $demand->getLevels());
        }
        if ($demand->getModes()) {
            $constraints[] = $query->in('mode', $demand->getModes());
        }
        if ($demand->getChannels()) {
            $constraints[] = $query->in('channel', $demand->getChannels());
        }
        if ($demand->getRequestId()) {
            $constraints[] = $query->equals('requestId', $demand->getRequestId());
        }
        if ($demand->getUser()) {
            $userInformation = explode('_', $demand->getUser());
            if (count($userInformation) === 2) {
                $constraints[] = $query->equals('mode', $userInformation[0]);
                $constraints[] = $query->equals('userId', $userInformation[1]);
            }
        }

        if ($demand->getDateRange()) {
            $dateConstraints = $this->setTimeConstraints($query, $demand);
            if (!empty($dateConstraints)) {
                $constraints = array_merge($constraints, $dateConstraints);
            }
        }

        return $constraints;
    }

    protected function setTimeConstraints(QueryInterface $query, ListDemand $demand)
    {
        $constraints = [];
        $startTime = 0;
        $endTime = $GLOBALS['EXEC_TIME'];
        switch ($demand->getDateRange()) {
            case 1:
                // This week
                $week = (date('w') ?: 7) - 1;
                $startTime = mktime(0, 0, 0) - $week * 3600 * 24;
                break;
            case 2:
                // Last week
                $week = (date('w') ?: 7) - 1;
                $startTime = mktime(0, 0, 0) - ($week + 7) * 3600 * 24;
                $endTime = mktime(0, 0, 0) - $week * 3600 * 24;
                break;
            case 3:
                // Last 7 days
                $startTime = mktime(0, 0, 0) - 7 * 3600 * 24;
                break;
            case 4:
                // This month
                $startTime = mktime(0, 0, 0, date('m'), 1);
                break;
            case 5:
                // Last month
                $startTime = mktime(0, 0, 0, date('m') - 1, 1);
                $endTime = mktime(0, 0, 0, date('m'), 1);
                break;
            case 6:
                // Last 31 days
                $startTime = mktime(0, 0, 0) - 31 * 3600 * 24;
                break;
            case 7:
                if ($demand->getDateStart()) {
                    $startTime = strtotime($demand->getDateStart());
                }
                if ($demand->getDateEnd()) {
                    $endTime = strtotime($demand->getDateEnd());
                }
        }

        if ($startTime) {
            $startTime = $this->getTime($startTime);
            $constraints[] = $query->greaterThanOrEqual('datetime', $startTime);
        }
        if ($endTime) {
            $endTime = $this->getTime($endTime);
            $constraints[] = $query->lessThanOrEqual('datetime', $endTime);
        }

        return $constraints;
    }

    /**
     * Truncate the log table
     *
     * @return void
     */
    protected function truncateLogTable()
    {
        $this->getDatabaseConnection()->exec_TRUNCATEquery(self::TABLE);
    }

    /**
     * @param int $time
     * @return string
     */
    protected function getTime($time)
    {
        $time = strftime('%Y-%m-%d %H:%M:%S', $time);
        return $time;
    }

    /**
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        if (is_null($this->databaseConnection)) {
            $this->databaseConnection = $GLOBALS['TYPO3_DB'];
        }
        return $this->databaseConnection;
    }
}
