<?php

/**
 * Crontab observer.
 *
 * @author Fabrizio Branca
 */
class Aoe_Scheduler_Model_Observer /* extends Mage_Cron_Model_Observer */
{
    /**
     * Process cron queue
     * Generate tasks schedule
     * Cleanup tasks schedule
     *
     * @param Varien_Event_Observer $observer
     */
    public function dispatch(Varien_Event_Observer $observer)
    {
        if (!Mage::getStoreConfigFlag('system/cron/enable')) {
            return;
        }

        /* @var Aoe_Scheduler_Helper_Data $helper */
        $helper = Mage::helper('aoe_scheduler');
        $includeJobs = $helper->addGroupJobs((array)$observer->getIncludeJobs(), (array)$observer->getIncludeGroups());
        $excludeJobs = $helper->addGroupJobs((array)$observer->getExcludeJobs(), (array)$observer->getExcludeGroups());

        /* @var Aoe_Scheduler_Model_ScheduleManager $scheduleManager */
        $scheduleManager = Mage::getModel('aoe_scheduler/scheduleManager');

        // Log this run in memory
        $scheduleManager->logRun();

        // Generate kill requests for runaway tasks
        $scheduleManager->handleRunawayTasks();

        // Process kill requests
        $scheduleManager->handleKills();

        // Change status on missed entries
        $scheduleManager->handleMissed();

        // Iterate over all pending jobs
        foreach ($scheduleManager->getPending($includeJobs, $excludeJobs) as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */

            // Execute a job
            $scheduleManager->runNow($schedule);
        }

        // Generate new schedules
        $scheduleManager->generateAll();

        // Clean up any duplicate entries
        $scheduleManager->handleDuplicates();

        // Clean up schedule history
        $scheduleManager->cleanup();
    }

    /**
     * Process cron queue for tasks marked as 'always'
     *
     * @param Varien_Event_Observer $observer
     */
    public function dispatchAlways(Varien_Event_Observer $observer)
    {
        if (!Mage::getStoreConfigFlag('system/cron/enable')) {
            return;
        }

        /* @var Aoe_Scheduler_Model_ProcessManager $processManager */
        $processManager = Mage::getModel('aoe_scheduler/processManager');

        /* @var Aoe_Scheduler_Helper_Data $helper */
        $helper = Mage::helper('aoe_scheduler');
        $includeJobs = $helper->addGroupJobs((array)$observer->getIncludeJobs(), (array)$observer->getIncludeGroups());
        $excludeJobs = $helper->addGroupJobs((array)$observer->getExcludeJobs(), (array)$observer->getExcludeGroups());

        /* @var Aoe_Scheduler_Model_ScheduleManager $scheduleManager */
        $scheduleManager = Mage::getModel('aoe_scheduler/scheduleManager');

        // Generate kill requests for runaway tasks
        $scheduleManager->handleRunawayTasks();

        // Process kill requests
        $scheduleManager->handleKills();

        /* @var Aoe_Scheduler_Model_Resource_Job_Collection $jobs */
        $jobs = Mage::getSingleton('aoe_scheduler/job')->getCollection();
        $jobs->setWhiteList($includeJobs);
        $jobs->setBlackList($excludeJobs);
        $jobs->setActiveOnly(true);
        foreach ($jobs as $job) {
            /* @var Aoe_Scheduler_Model_Job $job */
            if ($job->isAlwaysTask() && !$processManager->isJobCodeRunning($job->getJobCode())) {
                /* @var Aoe_Scheduler_Model_Schedule $schedule */
                $schedule = Mage::getModel('cron/schedule');
                $schedule->setJobCode($job->getJobCode());
                $schedule->setScheduledReason(Aoe_Scheduler_Model_Schedule::REASON_DISPATCH_ALWAYS);

                // Execute a job
                $scheduleManager->runNow($schedule);
            }
        }
    }
}
