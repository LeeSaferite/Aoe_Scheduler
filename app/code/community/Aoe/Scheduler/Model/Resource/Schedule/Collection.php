<?php

class Aoe_Scheduler_Model_Resource_Schedule_Collection extends Mage_Cron_Model_Resource_Schedule_Collection
{
    /**
     * Event name prefix for events that are dispatched by this class
     *
     * @var string
     */
    protected $_eventPrefix = 'aoe_scheduler_schedule_collection';

    /**
     * Event parameter name that references this object in an event
     *
     * In an observer method you can use $observer->getData('collection') or $observer->getData('data_object') to get this object
     *
     * @var string
     */
    protected $_eventObject = 'collection';

    /**
     * Redeclared for processing cache tags throw application object
     *
     * @return array
     */
    protected function _getCacheTags()
    {
        $tags = parent::_getCacheTags();
        $tags[] = 'SCHEDULER_SCHEDULES';

        return $tags;
    }

    /**
     * Gets statuses that are currently in the scheduler table
     *
     * @return array
     */
    public function getStatuses()
    {
        $select = clone $this->getSelect();
        $select->group('status');
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns('status');

        $statuses = $this->getConnection()->fetchCol($select);
        sort($statuses);

        return array_combine($statuses, $statuses);
    }

    /**
     * Clean up the history of tasks
     *
     * @param int $maxSuccessAge Minutes to keep a successful task record
     * @param int $maxFailureAge Minutes to keep a failed task record
     * @param int $maxUnknownAge Minutes to keep a task record with an unknown status
     * @param int $maxKeep       Max number of successful task records to keep per-job
     *
     * @throws Exception
     */
    public function cleanup($maxSuccessAge, $maxFailureAge, $maxUnknownAge, $maxKeep)
    {
        $maxSuccessAge = max($maxSuccessAge, 0) * 60;
        $maxFailureAge = max($maxFailureAge, 0) * 60;
        $maxUnknownAge = max($maxUnknownAge, 0) * 60;
        $maxKeep = max($maxKeep, 0);

        $collection = clone $this;
        $collection->_reset();
        $collection->addFieldToFilter(
            'status',
            [
                'nin' => [
                    Aoe_Scheduler_Model_Schedule::STATUS_PENDING,
                    Aoe_Scheduler_Model_Schedule::STATUS_RUNNING,
                ],
            ]
        );

        $collectionLifetimes = [
            Aoe_Scheduler_Model_Schedule::STATUS_KILLED               => $maxSuccessAge,
            Aoe_Scheduler_Model_Schedule::STATUS_DIDNTDOANYTHING      => $maxSuccessAge,
            Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS              => $maxSuccessAge,
            Aoe_Scheduler_Model_Schedule::STATUS_REPEAT               => $maxSuccessAge,
            Aoe_Scheduler_Model_Schedule::STATUS_DISAPPEARED          => $maxFailureAge,
            Aoe_Scheduler_Model_Schedule::STATUS_MISSED               => $maxFailureAge,
            Aoe_Scheduler_Model_Schedule::STATUS_SKIP_PILINGUP        => $maxFailureAge,
            Aoe_Scheduler_Model_Schedule::STATUS_ERROR                => $maxFailureAge,
            Aoe_Scheduler_Model_Schedule::STATUS_DIED                 => $maxFailureAge,
            Aoe_Scheduler_Model_Schedule::STATUS_SKIP_LOCKED          => $maxFailureAge,
            Aoe_Scheduler_Model_Schedule::STATUS_SKIP_OTHERJOBRUNNING => $maxFailureAge,
        ];

        $now = time();
        foreach ($collection as $record) {
            /* @var Aoe_Scheduler_Model_Schedule $record */
            $maxAge = (array_key_exists($record->getStatus(), $collectionLifetimes) ? $collectionLifetimes[$record->getStatus()] : $maxUnknownAge);
            if ($maxAge > 0 && strtotime($record->getExecutedAt()) < $now - $collectionLifetimes[$record->getStatus()]) {
                $record->delete();
            }
        }

        // Delete successful tasks (beyond the configured max number of tasks to keep)
        if ($maxKeep > 0) {
            $collection = clone $this;
            $collection->_reset();
            $collection->addFieldToFilter(
                'status',
                [
                    ['eq' => Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS],
                    ['eq' => Aoe_Scheduler_Model_Schedule::STATUS_REPEAT],
                ]
            );
            $collection->setOrder('finished_at', Zend_Db_Select::SQL_DESC);

            $jobCounters = [];
            foreach ($collection as $record) {
                /* @var Aoe_Scheduler_Model_Schedule $record */
                $jobCode = $record->getJobCode();
                if (!isset($jobCounters[$jobCode])) {
                    $jobCounters[$jobCode] = 0;
                }
                $jobCounters[$jobCode]++;
                if ($jobCounters[$jobCode] > $maxKeep) {
                    $record->delete();
                }
            }
        }
    }
}
