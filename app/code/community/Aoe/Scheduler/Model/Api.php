<?php

/**
 * Scheduler API
 *
 * @author Fabrizio Branca
 */
class Aoe_Scheduler_Model_Api extends Mage_Api_Model_Resource_Abstract
{
    /**
     * Run task
     *
     * @param $code
     * @return array
     */
    public function runNow($code)
    {
        if (!Mage::getStoreConfig('system/cron/enableRunNow')) {
            Mage::throwException("'Run now' disabled by configuration (system/cron/enableRunNow)");
        }

        /* @var $schedule Aoe_Scheduler_Model_Schedule */
        $schedule = Mage::getModel('cron/schedule');
        $schedule->setJobCode($code);
        $schedule->setScheduledReason(Aoe_Scheduler_Model_Schedule::REASON_RUNNOW_API);

        /* @var Aoe_Scheduler_Model_ScheduleManager $manager */
        $manager = Mage::getModel('aoe_scheduler/scheduleManager');
        $manager->runNow($schedule);

        return $schedule->getData();
    }

    /**
     * Schedule task
     *
     * @param $code
     * @param null $time
     * @return array
     */
    public function schedule($code, $time = null)
    {
        /* @var $schedule Aoe_Scheduler_Model_Schedule */
        $schedule = Mage::getModel('cron/schedule');
        $schedule->setJobCode($code);
        $schedule->setScheduledReason(Aoe_Scheduler_Model_Schedule::REASON_SCHEDULENOW_API);

        /* @var Aoe_Scheduler_Model_ScheduleManager $manager */
        $manager = Mage::getModel('aoe_scheduler/scheduleManager');
        $manager->schedule($schedule, $time);

        return $schedule->getData();
    }

    /**
     * Get info
     *
     * @param $id
     * @return string
     */
    public function info($id)
    {
        $schedule = Mage::getModel('cron/schedule')->load($id); /* @var $schedule Aoe_Scheduler_Model_Schedule */
        return $schedule->getData();
    }
}
