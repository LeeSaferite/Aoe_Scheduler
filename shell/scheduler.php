<?php

require_once 'abstract.php';

class Aoe_Scheduler_Shell_Scheduler extends Mage_Shell_Abstract
{
    /**
     * Run script
     *
     * @return void
     */
    public function run()
    {
        try {
            $action = $this->getArg('action');
            if (empty($action)) {
                echo $this->usageHelp();
            } else {
                $actionMethodName = $action . 'Action';
                if (method_exists($this, $actionMethodName)) {
                    Mage::app()->setUseSessionInUrl(false);
                    umask(0);
                    Mage::app()->addEventArea(Mage_Core_Model_App_Area::AREA_GLOBAL);
                    Mage::app()->addEventArea('crontab');
                    $this->$actionMethodName();
                } else {
                    echo "Action $action not found!\n";
                    echo $this->usageHelp();
                    exit(1);
                }
            }
        } catch (Exception $e) {
            $fh = fopen('php://stderr', 'w');
            fputs($fh, $e->__toString());
            fclose($fh);
            exit(255);
        }
    }

    /**
     * Retrieve Usage Help Message
     *
     * @return string
     */
    public function usageHelp()
    {
        $help = 'Available actions: ' . "\n";
        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (substr($method, -6) == 'Action') {
                $help .= '    --action ' . substr($method, 0, -6);
                $helpMethod = $method . 'Help';
                if (method_exists($this, $helpMethod)) {
                    $help .= ' ' . $this->$helpMethod();
                }
                $help .= "\n";
            }
        }
        return $help;
    }

    /**
     * List all availables codes / jobs
     *
     * @return void
     */
    public function listAllCodesAction()
    {
        /** @var Aoe_Scheduler_Model_Resource_Job_Collection $jobs */
        $jobs = Mage::getSingleton('aoe_scheduler/job')->getCollection();
        foreach ($jobs as $job) {
            /* @var Aoe_Scheduler_Model_Job $job */
            echo sprintf(
                "%-50s %-20s %s\n",
                $job->getJobCode(),
                $job->getCronExpression(),
                $job->getIsActive() ? 'Enabled' : 'Disabled'
            );
        }
    }

    /**
     * Returns the timestamp of the last run of a given job
     *
     * @return void
     */
    public function lastRunAction()
    {
        $code = $this->getArg('code');
        if (empty($code)) {
            echo "\nNo code found!\n\n";
            echo $this->usageHelp();
            exit(1);
        }

        /* @var Mage_Cron_Model_Resource_Schedule_Collection $collection */
        $collection = Mage::getModel('cron/schedule')->getCollection();
        $collection->addFieldToFilter('job_code', $code);
        $collection->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS);
        $collection->addOrder('finished_at', Varien_Data_Collection_Db::SORT_ORDER_DESC);
        $collection->setPageSize(1);

        /* @var Aoe_Scheduler_Model_Schedule $schedule */
        $schedule = $collection->getFirstItem();
        if (!$schedule->getId()) {
            echo "\nJob has never been run.\n\n";
            exit(1);
        }

        $time = strtotime($schedule->getFinishedAt());

        if ($this->getArg('secondsFromNow')) {
            $time = time() - $time;
        }

        echo $time . PHP_EOL;
    }

    /**
     * Display extra help
     *
     * @return string
     */
    public function lastRunActionHelp()
    {
        return "--code <code> [--secondsFromNow]	Get the timestamp of the last successful run of a job for a given code";
    }

    /**
     * Schedule a job now
     *
     * @return void
     */
    public function scheduleNowAction()
    {
        $code = $this->getArg('code');
        if (empty($code)) {
            echo "\nNo code found!\n\n";
            echo $this->usageHelp();
            exit(1);
        }

        $allowedCodes = Mage::getSingleton('aoe_scheduler/job')->getResource()->getJobCodes();
        if (!in_array($code, $allowedCodes)) {
            echo "\nNo valid job found!\n\n";
            echo $this->usageHelp();
            exit(1);
        }

        /* @var $schedule Aoe_Scheduler_Model_Schedule */
        $schedule = Mage::getModel('cron/schedule');
        $schedule->setJobCode($code);
        $schedule->setScheduledReason(Aoe_Scheduler_Model_Schedule::REASON_SCHEDULENOW_CLI);

        /* @var Aoe_Scheduler_Model_ScheduleManager $manager */
        $manager = Mage::getModel('aoe_scheduler/scheduleManager');
        $manager->schedule($schedule);
    }

    /**
     * Display extra help
     *
     * @return string
     */
    public function scheduleNowActionHelp()
    {
        return "--code <code>	Schedule a job to be executed as soon as possible";
    }

    /**
     * Run a job now
     *
     * @return void
     */
    public function runNowAction()
    {
        $code = $this->getArg('code');
        if (empty($code)) {
            echo "\nNo code found!\n\n";
            echo $this->usageHelp();
            exit(1);
        }

        $allowedCodes = Mage::getSingleton('aoe_scheduler/job')->getResource()->getJobCodes();
        if (!in_array($code, $allowedCodes)) {
            echo "\nNo valid job found!\n\n";
            echo $this->usageHelp();
            exit(1);
        }

        /* @var Aoe_Scheduler_Model_Schedule $schedule */
        $schedule = Mage::getModel('cron/schedule');
        $schedule->setJobCode($code);
        $schedule->setScheduledReason(Aoe_Scheduler_Model_Schedule::REASON_RUNNOW_CLI);

        /* @var Aoe_Scheduler_Model_ScheduleManager $manager */
        $manager = Mage::getModel('aoe_scheduler/scheduleManager');
        $manager->runNow($schedule);

        echo "Status: " . $schedule->getStatus() . "\nMessages:\n" . trim($schedule->getMessages(), "\n") . "\n";
    }

    /**
     * Display extra help
     *
     * @return string
     */
    public function runNowActionHelp()
    {
        return "--code <code>	        Run a job directly";
    }

    /**
     * Active wait until no schedules are running
     */
    public function waitAction()
    {
        $timeout = $this->getArg('timeout') ? $this->getArg('timeout') : 60;
        $startTime = time();
        $sleepBetweenPolls = 2;

        /* @var Aoe_Scheduler_Model_ProcessManager $processManager */
        $processManager = Mage::getModel('aoe_scheduler/processManager');

        do {
            $aliveSchedules = 0;
            echo "Currently running schedules:\n";

            foreach ($processManager->getAllRunningSchedules() as $schedule) {
                /* @var Aoe_Scheduler_Model_Schedule $schedule */
                $status = $processManager->check($schedule);
                if($status === true) {
                    $status = 'alive';
                    $aliveSchedules++;
                } elseif($status === false) {
                    $status = 'dead';
                } else {
                    $status = '?';
                }

                echo sprintf(
                    "%-30s %-10s %-10s %-10s %-10s\n",
                    $schedule->getJobCode(),
                    $schedule->getHost() ? $schedule->getHost() : '(no host)',
                    $schedule->getPid() ? $schedule->getPid() : '(no pid)',
                    $schedule->getLastSeen() ? $schedule->getLastSeen() : '(never)',
                    $status
                );
            }
            if ($aliveSchedules == 0) {
                echo "No schedules found\n";
                return;
            }

            sleep($sleepBetweenPolls);

        } while (time() - $startTime < $timeout);

        echo "Timeout reached\n";
        exit(1);
    }

    /**
     * Display extra help
     *
     * @return string
     */
    public function waitActionHelp()
    {
        return "[--timout <timeout=60>]	        Active wait until no schedules are running.";
    }

    /**
     * Flush schedules
     */
    public function flushSchedulesAction()
    {
        switch ($this->getArg('mode')) {
            case 'future':
                /* @var Aoe_Scheduler_Model_ScheduleManager $scheduleManager */
                $scheduleManager = Mage::getModel('aoe_scheduler/scheduleManager');
                $scheduleManager->flushPending();
                break;
            case 'all':
                /* @var Mage_Cron_Model_Resource_Schedule_Collection $schedules */
                $schedules = Mage::getModel('cron/schedule')->getCollection();
                $schedules->getConnection()->delete($schedules->getMainTable());
                break;
            default:
                echo "\nInvalid mode!\n\n";
                echo $this->usageHelp();
                exit(1);
        }
    }

    /**
     * Display extra help
     *
     * @return string
     */
    public function flushSchedulesActionHelp()
    {
        return "--mode (future|all)	        Flush schedules.";
    }

    /**
     * Print all running schedules
     *
     * @return void
     */
    public function listAllRunningSchedulesAction()
    {
        /* @var Aoe_Scheduler_Model_ProcessManager $processManager */
        $processManager = Mage::getModel('aoe_scheduler/processManager');
        foreach ($processManager->getAllRunningSchedules() as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            $status = $processManager->check($schedule);
            if($status === true) {
                $status = 'alive';
            } elseif($status === false) {
                $status = 'dead';
            } else {
                $status = '?';
            }

            echo sprintf(
                "%-30s %-10s %-10s %-10s %-10s\n",
                $schedule->getJobCode(),
                $schedule->getHost() ? $schedule->getHost() : '(no host)',
                $schedule->getPid() ? $schedule->getPid() : '(no pid)',
                $schedule->getLastSeen() ? $schedule->getLastSeen() : '(never)',
                $status
            );
        }
    }

    /**
     * Kill all
     *
     * @return void
     */
    public function killAllAction()
    {
        /* @var Aoe_Scheduler_Model_ProcessManager $processManager */
        $processManager = Mage::getModel('aoe_scheduler/processManager');
        foreach ($processManager->getAllRunningSchedules(gethostname()) as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            if($processManager->check($schedule) === true) {
                $processManager->kill($schedule);
                echo sprintf(
                    "%-30s %-10s %-10s: Killed\n",
                    $schedule->getJobCode(),
                    $schedule->getHost(),
                    $schedule->getPid()
                );
            }
        }
    }

    /**
     * Runs watchdog
     */
    public function watchdogAction()
    {
        /* @var Aoe_Scheduler_Model_ProcessManager $processManager */
        $processManager = Mage::getModel('aoe_scheduler/processManager');
        $processManager->watchdog();
    }

    /**
     * Cron action
     *
     *
     */
    public function cronAction()
    {
        $mode = $this->getArg('mode');
        switch ($mode) {
            case 'always':
            case 'default':
                $includeGroups = array_filter(array_map('trim', explode(',', $this->getArg('include'))));
                $excludeGroups = array_filter(array_map('trim', explode(',', $this->getArg('exclude'))));
                Mage::dispatchEvent($mode, array('include' => $includeGroups, 'exclude' => $excludeGroups));
                break;
            default:
                echo "\nInvalid mode!\n\n";
                echo $this->usageHelp();
                exit(1);
        }
    }

    /**
     * Display extra help
     *
     * @return string
     */
    public function cronActionHelp()
    {
        return "--mode (always|default) [--exclude <comma separated list of groups>] [--include <comma separated list of groups>]";
    }

    protected function _applyPhpVariables()
    {
        // Disable this feature as cron jobs should run with CLI settings only
    }
}

$shell = new Aoe_Scheduler_Shell_Scheduler();
$shell->run();
