<?php

class Aoe_Scheduler_Helper_Process extends Aoe_Scheduler_Helper_Data
{
    /**
     * Check if a record points to a running process and possibly update the record
     *
     * @param Aoe_Scheduler_Model_Schedule $schedule
     * @param bool                         $update
     *
     * @return bool|null
     */
    public function check(Aoe_Scheduler_Model_Schedule $schedule, $update = true)
    {
        // Check is the current schedule record thinks it's running
        if ($schedule->getStatus() !== Aoe_Scheduler_Model_Schedule::STATUS_RUNNING) {
            return false;
        }

        $lastSeenTimeout = max(intval(Mage::getStoreConfig(self::XML_PATH_LASTSEEN_TIMEOUT, Mage_Core_Model_Store::ADMIN_CODE)), 1);
        $lastSeenError = max(intval(Mage::getStoreConfig(self::XML_PATH_MARK_AS_ERROR, Mage_Core_Model_Store::ADMIN_CODE)), $lastSeenTimeout);

        // Convert to seconds
        $lastSeenTimeout *= 60;
        $lastSeenError *= 60;

        // If the last-seen timestamp is recent enough, just assume it's still running
        $lastSeenSeconds = (time() - strtotime($schedule->getLastSeen()));
        if ($lastSeenSeconds < $lastSeenTimeout) {
            return true;
        }

        if ($schedule->getHost() !== gethostname()) {
            // If the job has passed the error threshold and we are updating, and we can get a lock
            if ($update && ($lastSeenSeconds > $lastSeenError) && $this->lock($schedule)) {
                $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_DISAPPEARED);
                $schedule->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()));
                $schedule->addMessages(sprintf('Host "%s" has not been available for a while now to update the status of this task and the task is not reporting back by itself', $schedule->getHost()));
                $schedule->save();
                return false;
            }

            // The schedule is running on another host, return a null to indicate we do not know the status
            return null;
        }

        // Check the schedule's PID is currently running on this host
        $exists = $this->checkPidExists($schedule->getPid());

        // If we are updating the record AND we can obtain a lock, then modify the records
        if ($update && $this->lock($schedule)) {
            // The status in the DB at this point is LOCKED and we'll need to set the correct status to release the lock.
            if ($exists) {
                $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_RUNNING);
                $schedule->setLastSeen(strftime('%Y-%m-%d %H:%M:%S', time()));
                $schedule->save();
            } else {
                $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_DISAPPEARED);
                $schedule->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()));
                $schedule->addMessages(sprintf('Process "%s" on host "%s" cannot be found anymore', $schedule->getPid(), $schedule->getHost()));
                $schedule->save();
            }
        }

        return $exists;
    }

    public function kill(Aoe_Scheduler_Model_Schedule $schedule)
    {
        // We cannot kill the job if it's on another host
        if ($schedule->getHost() !== gethostname()) {
            return null;
        }

        // Try to lock the record before we kill the process. Failure means we are unsure of the status.
        if (!$this->lock($schedule)) {
            return null;
        }

        // Check if process is already gone
        if ($this->checkPidGone($schedule->getPid())) {
            return true;
        }

        // Start kill timer
        $startTime = time();

        // let's be nice first (a.k.a. "Could you please stop running now?")
        if ($this->sendSigInt($schedule->getPid())) {
            $this->log(sprintf('Sending SIGINT to job "%s" (id: %s)', $schedule->getJobCode(), $schedule->getId()));
        } else {
            $this->log(sprintf('Error while sending SIGINT to job "%s" (id: %s)', $schedule->getJobCode(), $schedule->getId()), Zend_Log::ERR);
        }

        $gone = $this->checkPidGone($schedule->getPid(), 30);

        if (!$gone) {
            // What, you're still alive? OK, time to say goodbye now. You had your chance...
            if ($this->sendSigKill($schedule->getPid())) {
                $this->log(sprintf('Sending SIGKILL to job "%s" (id: %s)', $schedule->getJobCode(), $schedule->getId()));
            } else {
                $this->log(sprintf('Error while sending SIGKILL to job "%s" (id: %s)', $schedule->getJobCode(), $schedule->getId()), Zend_Log::ERR);
            }

            $gone = $this->checkPidGone($schedule->getPid(), 5);
        }

        if ($gone) {
            $this->log(sprintf('Killed job "%s" (id: %s). Job terminated after %s second(s)', $schedule->getJobCode(), $schedule->getId(), (time() - $startTime)));
            $schedule->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_KILLED);
            $schedule->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()));
            $schedule->save();
            return true;
        } else {
            $this->log(sprintf('Killed job "%s" (id: %s) is still alive!', $schedule->getJobCode(), $schedule->getId()), Zend_Log::ERR);
            $this->unlock($schedule);
            return false;
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
}
