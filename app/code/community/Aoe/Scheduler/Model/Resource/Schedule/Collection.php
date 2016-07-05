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
}
