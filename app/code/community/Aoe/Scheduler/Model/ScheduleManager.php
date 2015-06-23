<?php

class Aoe_Scheduler_Model_ScheduleManager
{
    const XML_PATH_HISTORY_MAXNO = 'system/cron/maxNoOfSuccessfulTasks';
    const CACHE_KEY_SCHEDULER_LASTRUNS = 'cron_lastruns';

    public function isJobRunning(Aoe_Scheduler_Model_Job $job, $ignoreId = null)
    {
        $helper = $this->getHelper();

        $schedules = $this->getRunning();
        $schedules->addFieldToFilter('job_code', $job->getJobCode());
        if (!is_null($ignoreId)) {
            $schedules->addFieldToFilter('schedule_id', array('neq' => $ignoreId));
        }

        foreach ($schedules as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            if($helper->check($schedule) !== false)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Schedule this task to be executed at a given time
     *
     * @param Aoe_Scheduler_Model_Schedule $schedule
     * @param int                          $time
     *
     * @return $this
     */
    public function schedule(Aoe_Scheduler_Model_Schedule $schedule, $time = null)
    {
        $now = time();
        if (is_null($time) || $time < $now) {
            $time = $now;
        }

        $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_PENDING);
        $schedule->setCreatedAt(strftime('%Y-%m-%d %H:%M:%S', $now));
        $schedule->setScheduledAt(strftime('%Y-%m-%d %H:%M:00', $time));
        $schedule->save();

        return $this;
    }

    /**
     * Run this task now
     *
     * @param bool $tryLockJob
     *
     * @return Aoe_Scheduler_Model_Schedule
     */
    public function runNow(Aoe_Scheduler_Model_Schedule $schedule)
    {
        // if this schedule doesn't exist yet, create it
        if (!$schedule->getCreatedAt()) {
            $this->schedule($schedule);
        }

        // lock job (see below) prevents the exact same schedule from being executed from more than one process (or server)
        // the following check will prevent multiple schedules of the same type to be run in parallel
        $processManager = Mage::getModel('aoe_scheduler/processManager');
        /* @var $processManager Aoe_Scheduler_Model_ProcessManager */
        if ($processManager->isJobCodeRunning($schedule->getJobCode(), $schedule->getId())) {
            $this->log(sprintf('Job "%s" (id: %s) will not be executed because there is already another process with the same job code running. Skipping.', $schedule->getJobCode(), $schedule->getId()));
            return $schedule;
        }

        if (!$schedule->tryLockJob()) {
            // Another cron process already started this job
            $this->log(sprintf('Job "%s" (id: %s) is locked. Skipping.', $schedule->getJobCode(), $schedule->getId()));
            return $schedule;
        }

        try {
            $job = $schedule->getJob();
            if (!$job) {
                Mage::throwException(sprintf("Could not load job '%s'", $schedule->getJobCode()));
            }

            $callback = $job->getCallback();

            $startTime = time();
            $schedule
                ->setExecutedAt(strftime('%Y-%m-%d %H:%M:%S', $startTime))
                ->setLastSeen(strftime('%Y-%m-%d %H:%M:%S', $startTime))
                ->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_RUNNING)
                ->setHost(gethostname())
                ->setPid(getmypid())
                ->save();

            Mage::dispatchEvent('cron_' . $schedule->getJobCode() . '_before', array('schedule' => $schedule));
            Mage::dispatchEvent('cron_before', array('schedule' => $schedule));

            Mage::unregister('current_cron_task');
            Mage::register('current_cron_task', $schedule);

            $this->log('Start: ' . $schedule->getJobCode());

            $this->getHelper()->startBuffering($schedule);
            try {
                $messages = call_user_func_array($callback, array($schedule));
                $this->getHelper()->stopBuffering();
            } catch (Exception $e) {
                $this->getHelper()->stopBuffering();
                throw $e;
            }

            $this->log('Stop: ' . $schedule->getJobCode());

            if (!empty($messages)) {
                if (is_object($messages)) {
                    $messages = get_class($messages);
                } elseif (!is_scalar($messages)) {
                    $messages = var_export($messages, 1);
                }
                $schedule->addMessages(PHP_EOL . '---RETURN_VALUE---' . PHP_EOL . $messages);
            }

            // schedules can report an error state by returning a string that starts with "ERROR:" or "NOTHING"
            if ($schedule->getStatus() === Aoe_Scheduler_Model_Schedule::STATUS_RUNNING && is_string($messages)) {
                $prefix = strtoupper(substr($messages, 0, 20));
                if (strpos($prefix, 'ERROR:') === 0) {
                    $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_ERROR);
                } elseif (strpos($prefix, 'NOTHING') === 0) {
                    $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_DIDNTDOANYTHING);
                }
            }

            if ($schedule->getStatus() === Aoe_Scheduler_Model_Schedule::STATUS_ERROR) {
                Mage::dispatchEvent('cron_' . $schedule->getJobCode() . '_after_error', array('schedule' => $schedule));
                Mage::dispatchEvent('cron_after_error', array('schedule' => $schedule));
                Mage::helper('aoe_scheduler')->sendErrorMail($schedule, $messages);
            } elseif ($schedule->getStatus() === Aoe_Scheduler_Model_Schedule::STATUS_DIDNTDOANYTHING) {
                Mage::dispatchEvent('cron_' . $schedule->getJobCode() . '_after_nothing', array('schedule' => $schedule));
                Mage::dispatchEvent('cron_after_nothing', array('schedule' => $schedule));
            } else {
                $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS);
                Mage::dispatchEvent('cron_' . $schedule->getJobCode() . '_after_success', array('schedule' => $schedule));
                Mage::dispatchEvent('cron_after_success', array('schedule' => $schedule));
            }
        } catch (Exception $e) {
            $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_ERROR);
            $schedule->addMessages(PHP_EOL . '---EXCEPTION---' . PHP_EOL . $e->__toString());
            Mage::dispatchEvent('cron_' . $schedule->getJobCode() . '_exception', array('schedule' => $schedule, 'exception' => $e));
            Mage::dispatchEvent('cron_exception', array('schedule' => $schedule, 'exception' => $e));
            Mage::helper('aoe_scheduler')->sendErrorMail($schedule, $e->__toString());
        }

        $schedule->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()));
        Mage::dispatchEvent('cron_' . $schedule->getJobCode() . '_after', array('schedule' => $schedule));
        Mage::dispatchEvent('cron_after', array('schedule' => $schedule));

        $schedule->save();

        return $schedule;
    }

    /**
     * Get pending schedules
     *
     * @param array $whitelist
     * @param array $blacklist
     *
     * @return Aoe_Scheduler_Model_Resource_Schedule_Collection
     */
    public function getPending(array $whitelist = array(), array $blacklist = array())
    {
        /** @var Aoe_Scheduler_Model_Resource_Schedule_Collection $schedules */
        $schedules = Mage::getModel('cron/schedule')->getCollection();
        $schedules->setWhitelist($whitelist);
        $schedules->setBlacklist($blacklist);
        $schedules->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_PENDING);
        //$schedules->addFieldToFilter('scheduled_at', array('lt' => strftime('%Y-%m-%d %H:%M:%S', time())));
        $schedules->addOrder('scheduled_at', 'ASC');

        return $schedules;
    }

    /**
     * Get all schedules running on this server
     *
     * @param null|string $host
     *
     * @return Aoe_Scheduler_Model_Resource_Schedule_Collection
     */
    public function getRunning($host = null)
    {
        /** @var Aoe_Scheduler_Model_Resource_Schedule_Collection $schedules */
        $schedules = Mage::getModel('cron/schedule')->getCollection();
        $schedules->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_RUNNING);

        if (!is_null($host)) {
            $schedules->addFieldToFilter('host', $host);
        }

        return $schedules;
    }

    /**
     * Generate cron schedule.
     * Rewrites the original method to remove duplicates afterwards (that exists because of a bug)
     *
     * @return $this
     */
    public function generateAll()
    {
        /**
         * check if schedule generation is needed
         */
        $lastRun = Mage::app()->loadCache(Mage_Cron_Model_Observer::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT);
        if ($lastRun > time() - Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_SCHEDULE_GENERATE_EVERY) * 60) {
            return $this;
        }

        $startTime = microtime(true);

        /* @var Aoe_Scheduler_Model_Resource_Job_Collection $jobs */
        $jobs = Mage::getSingleton('aoe_scheduler/job')->getCollection();
        $jobs->setActiveOnly(true);
        foreach ($jobs as $job) {
            /* @var Aoe_Scheduler_Model_Job $job */
            $this->generateForJob($job);
        }

        /**
         * save time schedules generation was ran with no expiration
         */
        Mage::app()->saveCache(time(), Mage_Cron_Model_Observer::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT, array('crontab'), null);

        /* @var Aoe_Scheduler_Model_Schedule $newestSchedule */
        $newestSchedule = Mage::getModel('cron/schedule')->getCollection()
            ->setOrder('scheduled_at', 'DESC')
            ->setPageSize(1)
            ->getFirstItem();

        $duration = microtime(true) - $startTime;
        $this->log('Generated schedule. Newest task is scheduled at "' . $newestSchedule->getScheduledAt() . '". (Duration: ' . round($duration, 2) . ' sec)');

        return $this;
    }

    /**
     * Generate jobs for config information
     *
     * @param Aoe_Scheduler_Model_Job $job
     *
     * @return $this
     */
    public function generateForJob(Aoe_Scheduler_Model_Job $job)
    {
        if (!$job->getIsActive() || !$job->getCronExpression() || $job->isAlwaysTask()) {
            return $this;
        }

        $exists = array();
        foreach ($this->getPending(array($job->getJobCode()), array()) as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            $exists[$this->getScheduleKey($schedule->getJobCode(), strtotime($schedule->getScheduledAt()))] = true;
        }

        // These numbers are in minutes
        $scheduleTime = ceil(floatval(time()) / 60.0);
        $scheduleAheadFor = max(intval(Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_SCHEDULE_AHEAD_FOR)), 1);
        $scheduleTimeMax = max($scheduleTime + $scheduleAheadFor, $scheduleTime);

        $helper = $this->getHelper();
        while ($scheduleTime < $scheduleTimeMax) {
            $timestamp = $scheduleTime * 60;
            $scheduleTime++;

            $key = $this->getScheduleKey($job->getJobCode(), $timestamp);
            if (isset($exists[$key])) {
                continue;
            }

            if ($helper->checkCronExpression($job->getCronExpression(), $timestamp)) {
                /* @var Aoe_Scheduler_Model_Schedule $schedule */
                $schedule = Mage::getModel('cron/schedule');
                $schedule->setJobCode($job->getJobCode());
                $schedule->setScheduledReason(Aoe_Scheduler_Model_Schedule::REASON_GENERATESCHEDULES);
                $this->schedule($schedule, $timestamp);
                $exists[$key] = true;
            }
        }

        return $this;
    }

    /**
     * Clean schedule records
     *
     * Remove old and duplicate jobs
     *
     * @return $this
     */
    public function handleMissed()
    {
        $schedules = $this->getPending();
        $schedules->addOrder('scheduled_at', 'DESC');

        $scheduleLifetime = (intval(Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_SCHEDULE_LIFETIME)) * 60);
        $now = time();

        $seen = array();
        foreach ($schedules as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            // Check for expired jobs
            $scheduledAt = max(intval(strtotime($schedule->getScheduledAt())), 0);
            if ($scheduledAt < ($now - $scheduleLifetime)) {
                $result = $schedule->getResource()->trySetJobStatusAtomic(
                    $schedule->getId(),
                    Aoe_Scheduler_Model_Schedule::STATUS_MISSED,
                    Aoe_Scheduler_Model_Schedule::STATUS_PENDING
                );
                if ($result) {
                    $schedule->setMessages('Too late for the schedule.');
                    $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_MISSED);
                    $schedule->save();
                    continue;
                }
            }

            // Check for duplicate jobs
            if (isset($seen[$schedule->getJobCode()])) {
                $result = $schedule->getResource()->trySetJobStatusAtomic(
                    $schedule->getId(),
                    Aoe_Scheduler_Model_Schedule::STATUS_MISSED,
                    Aoe_Scheduler_Model_Schedule::STATUS_PENDING
                );
                if ($result) {
                    $schedule->setMessages('Multiple tasks with the same job code were piling up. Skipping execution of duplicates.');
                    $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_MISSED);
                    $schedule->save();
                }
                continue;
            }

            // Log job code as seen
            $seen[$schedule->getJobCode()] = 1;
        }

        return $this;
    }

    /**
     * Delete duplicate schedules
     *
     * The maximum run frequency is in minutes. Find any job with multiple schedules at the same minute and keep only one.
     *
     * @return int Number of deleted entries
     */
    public function handleDuplicates()
    {
        /** @var Aoe_Scheduler_Model_Resource_Schedule_Collection $schedules */
        $schedules = Mage::getModel('cron/schedule')->getCollection()
            ->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_PENDING);

        $seen = array();
        $count = 0;
        foreach ($schedules as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            $key = $this->getScheduleKey($schedule->getJobCode(), strtotime($schedule->getScheduledAt()));
            if (isset($seen[$key])) {
                // Lock the schedule before deleting to prevent deleting a job in progress
                $result = $schedule->getResource()->trySetJobStatusAtomic(
                    $schedule->getId(),
                    Aoe_Scheduler_Model_Schedule::STATUS_LOCKED,
                    Aoe_Scheduler_Model_Schedule::STATUS_PENDING
                );
                if ($result) {
                    $schedule->delete();
                    $count++;
                }
            } else {
                $seen[$key] = true;
            }
        }

        return $count;
    }

    /**
     * Flushed all pending schedules.
     *
     * @param string $jobCode
     *
     * @return $this
     */
    public function flushPending($jobCode = null)
    {
        $helper = $this->getHelper();

        /* @var $schedules Mage_Cron_Model_Resource_Schedule_Collection */
        $schedules = Mage::getModel('cron/schedule')->getCollection()
            ->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_PENDING)
            ->addOrder('scheduled_at', 'ASC');

        if (!empty($jobCode)) {
            $schedules->addFieldToFilter('job_code', $jobCode);
        }

        foreach ($schedules as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            // Lock the schedule before deleting to prevent deleting a job in progress
            if ($helper->lock($schedule, Aoe_Scheduler_Model_Schedule::STATUS_PENDING)) {
                $schedule->delete();
            }
        }

        Mage::app()->saveCache(0, Mage_Cron_Model_Observer::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT, array('crontab'), null);

        return $this;
    }

    /**
     * Clean up the history of tasks
     * This override deals with custom states added in Aoe_Scheduler
     *
     * @return Mage_Cron_Model_Observer
     */
    public function cleanup()
    {
        // check if cleanup is needed
        $lastCleanup = Mage::app()->loadCache(Mage_Cron_Model_Observer::CACHE_KEY_LAST_HISTORY_CLEANUP_AT);
        if ($lastCleanup > time() - Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_CLEANUP_EVERY) * 60) {
            return $this;
        }

        $startTime = microtime(true);

        /** @var Aoe_Scheduler_Model_Resource_Schedule_Collection $schedules */
        $schedules = Mage::getModel('cron/schedule')->getCollection();
        $schedules->addFieldToFilter('status', array('nin' => array(Aoe_Scheduler_Model_Schedule::STATUS_PENDING, Aoe_Scheduler_Model_Schedule::STATUS_RUNNING)));
        $schedules->setOrder('finished_at', 'desc');

        $lifetimes = array(
            Aoe_Scheduler_Model_Schedule::STATUS_KILLED          => Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_SUCCESS) * 60,
            Aoe_Scheduler_Model_Schedule::STATUS_DISAPPEARED     => Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE) * 60,
            Aoe_Scheduler_Model_Schedule::STATUS_DIDNTDOANYTHING => Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_SUCCESS) * 60,
            Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS         => Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_SUCCESS) * 60,
            Aoe_Scheduler_Model_Schedule::STATUS_MISSED          => Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE) * 60,
            Aoe_Scheduler_Model_Schedule::STATUS_ERROR           => Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE) * 60,
            Aoe_Scheduler_Model_Schedule::STATUS_LOCKED          => Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE) * 60,
        );

        $maxEntries = array(
            Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS => max(intval(Mage::getStoreConfig(self::XML_PATH_HISTORY_MAXNO)), 0),
        );

        $entryCount = array();

        $now = time();
        foreach ($schedules as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */

            // Delete schedule entries that are expired
            if (isset($lifetimes[$schedule->getStatus()])) {
                if (strtotime($schedule->getExecutedAt()) < ($now - $lifetimes[$schedule->getStatus()])) {
                    $schedule->delete();
                    continue;
                }
            }

            // Delete schedule entries beyond the configured max number of entries to keep
            if (isset($maxEntries[$schedule->getStatus()])) {
                if (!isset($entryCount[$schedule->getStatus()])) {
                    $entryCount[$schedule->getStatus()] = 0;
                }
                $entryCount[$schedule->getStatus()]++;
                if ($entryCount[$schedule->getStatus()] > $maxEntries[$schedule->getStatus()]) {
                    $schedule->delete();
                    continue;
                }
            }
        }

        // save time history cleanup was ran with no expiration
        Mage::app()->saveCache(time(), Mage_Cron_Model_Observer::CACHE_KEY_LAST_HISTORY_CLEANUP_AT, array('crontab'), null);

        $this->log('History cleanup (Duration: ' . round(microtime(true) - $startTime, 2) . ' sec)');

        return $this;
    }

    /**
     * Process kill requests
     *
     * @return void
     */
    public function handleKills()
    {
        $helper = $this->getHelper();

        $schedules = $this->getRunning(gethostname());
        $schedules->addFieldToFilter('kill_request', array('lt' => strftime('%Y-%m-%d %H:%M:00', time())));
        foreach ($schedules as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            $helper->kill($schedule);
        }
    }

    /**
     * Check running jobs
     *
     * @return void
     */
    public function handleRunawayTasks()
    {
        $helper = $this->getHelper();

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
     * Log run
     */
    public function logRun()
    {
        $lastRuns = Mage::app()->loadCache(self::CACHE_KEY_SCHEDULER_LASTRUNS);
        $lastRuns = explode(',', $lastRuns);
        $lastRuns[] = time();
        $lastRuns = array_slice($lastRuns, -100);
        Mage::app()->saveCache(implode(',', $lastRuns), self::CACHE_KEY_SCHEDULER_LASTRUNS, array('crontab'), null);
    }

    /**
     * @param string $jobCode
     * @param int    $scheduledAtTimestamp
     *
     * @return string
     */
    protected function getScheduleKey($jobCode, $scheduledAtTimestamp)
    {
        return $jobCode . '/' . date('YmdHi', $scheduledAtTimestamp);
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
     * @return Aoe_Scheduler_Helper_Process
     */
    protected function getHelper()
    {
        return Mage::helper('aoe_scheduler/process');
    }
}
