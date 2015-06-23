<?php

/**
 * @method Aoe_Scheduler_Model_Schedule getFirstItem()
 */
class Aoe_Scheduler_Model_Resource_Schedule_Collection extends Mage_Cron_Model_Resource_Schedule_Collection
{
    /**
     * Name prefix of events that are dispatched by model
     *
     * @var string
     */
    protected $_eventPrefix = 'aoe_scheduler_schedule_collection';

    /**
     * Name of event parameter
     *
     * @var string
     */
    protected $_eventObject = 'collection';

    /**
     * Apply a list of job codes as a whitelist
     *
     * @param string[] $list
     *
     * @return $this
     */
    public function setWhitelist(array $list)
    {
        $list = array_filter(array_map('trim', $list));

        if (!empty($list)) {
            $this->addFieldToFilter('job_code', array('in' => $list));
        }

        return $this;
    }

    /**
     * Apply a list of job codes as a blacklist
     *
     * @param string[] $list
     *
     * @return $this
     */
    public function setBlacklist(array $list)
    {
        $list = array_filter(array_map('trim', $list));

        if (!empty($list)) {
            $this->addFieldToFilter('job_code', array('nin' => $list));
        }

        return $this;
    }
}
