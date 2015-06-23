<?php

/**
 * Process Manager
 */
class Aoe_Scheduler_Model_ProcessManager
{
    const XML_PATH_MARK_AS_ERROR = 'system/cron/mark_as_error_after';
    const XML_PATH_LASTSEEN_TIMEOUT = 'system/cron/lastseen_timeout';
    const XML_PATH_MAX_JOB_RUNTIME = 'system/cron/max_job_runtime';

    /**
     * Get all schedules running on this server
     *
     * @param null|string $host
     *
     * @return Mage_Cron_Model_Mysql4_Schedule_Collection
     */
    public function getAllRunningSchedules($host = null)
    {
        $collection = Mage::getModel('cron/schedule')->getCollection();
        $collection->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_RUNNING);
        if (!is_null($host)) {
            $collection->addFieldToFilter('host', $host);
        }
        return $collection;
    }

    /**
     * Check if there's already a job running with the given code
     *
     * @param string $jobCode
     * @param int    $ignoreId
     *
     * @return bool
     */
    public function isJobCodeRunning($jobCode, $ignoreId = null)
    {
        $schedules = $this->getAllRunningSchedules();
        $schedules->addFieldToFilter('job_code', $jobCode);
        if (!is_null($ignoreId)) {
            $schedules->addFieldToFilter('schedule_id', array('neq' => $ignoreId));
        }

        foreach ($schedules as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            if($this->check($schedule) !== false)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Run maintenance
     */
    public function watchdog()
    {
        $this->checkRunningJobs();
        $this->processKillRequests();
    }

    /**
     * Check running jobs
     *
     * @return void
     */
    public function checkRunningJobs()
    {
        $maxJobRuntime = max(intval(Mage::getStoreConfig(self::XML_PATH_MAX_JOB_RUNTIME)), 0) * 60;

        $schedules = $this->getAllRunningSchedules();
        foreach ($schedules as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            if ($this->check($schedule) !== false && $maxJobRuntime && ($schedule->getDuration() > $maxJobRuntime)) {
                $schedule->setKillRequest(strftime('%Y-%m-%d %H:%M:%S', time()));
                $schedule->save();
            }
        }
    }

    /**
     * Process kill requests
     *
     * @return void
     */
    public function processKillRequests()
    {
        $schedules = $this->getAllRunningSchedules(gethostname());
        $schedules->addFieldToFilter('kill_request', array('lt' => strftime('%Y-%m-%d %H:%M:00', time())));
        foreach ($schedules as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            $this->kill($schedule);
        }
    }

    /**
     * Log message to configured log file (or skip)
     *
     * @param      $message
     * @param null $level
     */
    protected function log($message, $level = null)
    {
        if ($logFile = Mage::getStoreConfig('system/cron/logFile')) {
            Mage::log($message, $level, $logFile);
        }
    }

    /**
     * Check if process is running (linux only)
     *
     * @param int $pid
     *
     * @return bool
     */
    protected function checkPidExists($pid)
    {
        $pid = max(intval($pid), 0);
        return $pid && file_exists('/proc/' . $pid);
    }

    /**
     * Check for a PID to be gone
     *
     * @param int $pid
     * @param int $timeout
     *
     * @return bool true is the PID is gone, false if it is still around
     */
    protected function checkPidGone($pid, $timeout = 0)
    {
        $timeout = min(max(intval($timeout), 0), 60);

        while ($timeout && $this->checkPidExists($pid)) {
            $timeout--;
            sleep(1);
        }

        return !$this->checkPidExists($pid);
    }

    /**
     * Terminate a PID via SIGINT
     *
     * This is the 'nice' way
     *
     * @param int $pid
     *
     * @return bool
     */
    protected function sendSigInt($pid)
    {
        $pid = max(intval($pid), 0);
        return $pid && function_exists('posix_kill') && posix_kill($pid, SIGINT);
    }

    /**
     * Terminate a PID via SIGKILL
     *
     * This is the 'hard' way
     *
     * @param int $pid
     *
     * @return bool
     */
    protected function sendSigKill($pid)
    {
        $pid = max(intval($pid), 0);
        return $pid && function_exists('posix_kill') && posix_kill($pid, SIGKILL);
    }

    protected function lock(Aoe_Scheduler_Model_Schedule $schedule, $expectedStatus = Aoe_Scheduler_Model_Schedule::STATUS_RUNNING)
    {
        return $schedule->getResource()->trySetJobStatusAtomic($schedule->getId(), Aoe_Scheduler_Model_Schedule::STATUS_LOCKED, $expectedStatus);
    }

    protected function unlock(Aoe_Scheduler_Model_Schedule $schedule, $desiredStatus = Aoe_Scheduler_Model_Schedule::STATUS_RUNNING)
    {
        return $schedule->getResource()->trySetJobStatusAtomic($schedule->getId(), $desiredStatus, Aoe_Scheduler_Model_Schedule::STATUS_LOCKED);
    }
}
