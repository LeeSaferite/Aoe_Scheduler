<?php

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
}
