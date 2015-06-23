<?php

class Aoe_Scheduler_Helper_Data extends Mage_Core_Helper_Abstract
{
    const XML_PATH_MAX_RUNNING_TIME = 'system/cron/max_running_time';
    const XML_PATH_EMAIL_TEMPLATE = 'system/cron/error_email_template';
    const XML_PATH_EMAIL_IDENTITY = 'system/cron/error_email_identity';
    const XML_PATH_EMAIL_RECIPIENT = 'system/cron/error_email';
    const XML_PATH_MARK_AS_ERROR = 'system/cron/mark_as_error_after';
    const XML_PATH_LASTSEEN_TIMEOUT = 'system/cron/lastseen_timeout';
    const XML_PATH_MAX_JOB_RUNTIME = 'system/cron/max_job_runtime';


    /**
     * @var null|array
     */
    protected $groupsToJobsMap = null;

    /**
     * @var bool
     */
    protected $buffering = false;

    /**
     * @var int
     */
    protected $bufferChunkSize = 100; // bytes

    public function lock(Aoe_Scheduler_Model_Schedule $schedule, $expectedStatus = Aoe_Scheduler_Model_Schedule::STATUS_RUNNING)
    {
        return $schedule->getResource()->trySetJobStatusAtomic($schedule->getId(), Aoe_Scheduler_Model_Schedule::STATUS_LOCKED, $expectedStatus);
    }

    public function unlock(Aoe_Scheduler_Model_Schedule $schedule, $desiredStatus = Aoe_Scheduler_Model_Schedule::STATUS_RUNNING)
    {
        return $schedule->getResource()->trySetJobStatusAtomic($schedule->getId(), $desiredStatus, Aoe_Scheduler_Model_Schedule::STATUS_LOCKED);
    }

    /**
     * Log message to configured log file (or skip)
     *
     * @param mixed $message
     * @param null  $level
     * s
     * @return $this
     */
    public function log($message, $level = null)
    {
        if ($logFile = Mage::getStoreConfig('system/cron/logFile')) {
            Mage::log($message, $level, $logFile);
        }

        return $this;
    }

    /**
     * Explodes a string and trims all values for whitespace in the ends.
     * If $onlyNonEmptyValues is set, then all blank ('') values are removed.
     *
     * @see t3lib_div::trimExplode() in TYPO3
     *
     * @param        $delim
     * @param string $string
     * @param bool   $removeEmptyValues If set, all empty values will be removed in output
     *
     * @return array Exploded values
     */
    public function trimExplode($delim, $string, $removeEmptyValues = false)
    {
        $explodedValues = explode($delim, $string);

        $result = array_map('trim', $explodedValues);

        if ($removeEmptyValues) {
            $temp = array();
            foreach ($result as $value) {
                if ($value !== '') {
                    $temp[] = $value;
                }
            }
            $result = $temp;
        }

        return $result;
    }

    /**
     * Decorate status values
     *
     * @param $status
     *
     * @return string
     */
    public function decorateStatus($status)
    {
        switch ($status) {
            case Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS:
            case Aoe_Scheduler_Model_Schedule::STATUS_DIDNTDOANYTHING:
                $result = '<span class="bar-green"><span>' . $status . '</span></span>';
                break;
            case Aoe_Scheduler_Model_Schedule::STATUS_PENDING:
                $result = '<span class="bar-lightgray"><span>' . $status . '</span></span>';
                break;
            case Aoe_Scheduler_Model_Schedule::STATUS_RUNNING:
                $result = '<span class="bar-yellow"><span>' . $status . '</span></span>';
                break;
            case Aoe_Scheduler_Model_Schedule::STATUS_MISSED:
                $result = '<span class="bar-orange"><span>' . $status . '</span></span>';
                break;
            case Aoe_Scheduler_Model_Schedule::STATUS_ERROR:
            case Aoe_Scheduler_Model_Schedule::STATUS_DISAPPEARED:
            case Aoe_Scheduler_Model_Schedule::STATUS_KILLED:
            case Aoe_Scheduler_Model_Schedule::STATUS_LOCKED:
                $result = '<span class="bar-red"><span>' . $status . '</span></span>';
                break;
            default:
                $result = $status;
                break;
        }
        return $result;
    }

    /**
     * Wrapper for decorateTime to be used a frame_callback to avoid that additional parameters
     * conflict with the method's optional ones
     *
     * @param string $value
     *
     * @return string
     */
    public function decorateTimeFrameCallBack($value)
    {
        return $this->decorateTime($value, false, null);
    }

    /**
     * Decorate time values
     *
     * @param string $value
     * @param bool   $echoToday  if true "Today" will be added
     * @param string $dateFormat make sure Y-m-d is in it, if you want to have it replaced
     *
     * @return string
     */
    public function decorateTime($value, $echoToday = false, $dateFormat = null)
    {
        if (empty($value) || $value == '0000-00-00 00:00:00') {
            $value = '';
        } else {
            $value = Mage::getModel('core/date')->date($dateFormat, $value);
            $replace = array(
                Mage::getModel('core/date')->date('Y-m-d ', time())              => $echoToday ? Mage::helper('aoe_scheduler')->__('Today') . ', ' : '', // today
                Mage::getModel('core/date')->date('Y-m-d ', strtotime('+1 day')) => Mage::helper('aoe_scheduler')->__('Tomorrow') . ', ',
                Mage::getModel('core/date')->date('Y-m-d ', strtotime('-1 day')) => Mage::helper('aoe_scheduler')->__('Yesterday') . ', ',
            );
            $value = str_replace(array_keys($replace), array_values($replace), $value);
        }
        return $value;
    }

    /**
     * Get last heartbeat
     */
    public function getLastHeartbeat()
    {
        return $this->getLastExecutionTime('aoescheduler_heartbeat');
    }

    /**
     * Get last execution time
     *
     * @param $jobCode
     *
     * @return bool
     */
    public function getLastExecutionTime($jobCode)
    {
        if ($this->isDisabled($jobCode)) {
            return false;
        }
        $schedules = Mage::getModel('cron/schedule')->getCollection();
        /* @var Aoe_Scheduler_Model_Resource_Schedule_Collection $schedules */
        $schedules->getSelect()->limit(1)->order('executed_at DESC');
        $schedules->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS);
        $schedules->addFieldToFilter('job_code', $jobCode);
        $schedules->setPageSize(1);
        $schedules->load();
        if (count($schedules) == 0) {
            return false;
        }
        $executedAt = $schedules->getFirstItem()->getExecutedAt();
        $value = Mage::getModel('core/date')->date(null, $executedAt);
        return $value;
    }

    /**
     * Diff between to times;
     *
     * @param $time1
     * @param $time2
     *
     * @return int
     */
    public function dateDiff($time1, $time2 = null)
    {
        if (is_null($time2)) {
            $time2 = Mage::getModel('core/date')->date();
        }
        $time1 = strtotime($time1);
        $time2 = strtotime($time2);
        return $time2 - $time1;
    }

    /**
     * Check if job code is disabled in configuration
     *
     * @param $jobCode
     *
     * @return bool
     */
    public function isDisabled($jobCode)
    {
        /* @var $job Aoe_Scheduler_Model_Job */
        $job = Mage::getModel('aoe_scheduler/job')->load($jobCode);
        return ($job->getJobCode() && !$job->getIsActive());
    }

    /**
     * Check if a job matches the group include/exclude lists
     *
     * @param       $jobCode
     * @param array $include
     * @param array $exclude
     *
     * @return mixed
     */
    public function matchesIncludeExclude($jobCode, array $include, array $exclude)
    {
        $include = array_filter(array_map('strtolower', array_map('trim', $include)));
        $exclude = array_filter(array_map('strtolower', array_map('trim', $exclude)));

        sort($include);
        sort($exclude);

        $key = $jobCode . '|' . implode(',', $include) . '|' . implode(',', $exclude);
        static $cache = array();
        if (!isset($cache[$key])) {
            if (count($include) == 0 && count($exclude) == 0) {
                $cache[$key] = true;
            } else {
                $cache[$key] = true;
                /* @var $job Aoe_Scheduler_Model_Job */
                $job = Mage::getModel('aoe_scheduler/job')->load($jobCode);
                $groups = array_filter(array_map('strtolower', array_map('trim', explode(',', $job->getGroups()))));
                if (count($include) > 0) {
                    $cache[$key] = (count(array_intersect($groups, $include)) > 0);
                }
                if (count($exclude) > 0) {
                    if (count(array_intersect($groups, $exclude)) > 0) {
                        $cache[$key] = false;
                    }
                }
            }
        }
        return $cache[$key];
    }

    public function getGroupsToJobsMap($forceRebuild = false)
    {
        if ($this->groupsToJobsMap === null || $forceRebuild) {
            $map = array();

            /* @var $jobs Aoe_Scheduler_Model_Resource_Job_Collection */
            $jobs = Mage::getSingleton('aoe_scheduler/job')->getCollection();
            foreach ($jobs as $job) {
                /* @var Aoe_Scheduler_Model_Job $job */
                $groups = array_filter(array_map('strtolower', array_map('trim', explode(',', $job->getGroups()))));
                foreach ($groups as $group) {
                    $map[$group][] = $job->getJobCode();
                }
            }

            $this->groupsToJobsMap = $map;
        }

        return $this->groupsToJobsMap;
    }

    public function addGroupJobs(array $jobs, array $groups)
    {
        $map = $this->getGroupsToJobsMap();

        foreach ($groups as $group) {
            if (isset($map[$group])) {
                foreach ($map[$group] as $jobCode) {
                    $jobs[] = $jobCode;
                }
            }
        }

        return $jobs;
    }

    /**
     * Send error mail
     *
     * @param Aoe_Scheduler_Model_Schedule $schedule
     * @param                              $error
     *
     * @return void
     */
    public function sendErrorMail(Aoe_Scheduler_Model_Schedule $schedule, $error)
    {
        if (!Mage::getStoreConfig(self::XML_PATH_EMAIL_RECIPIENT)) {
            return;
        }

        $translate = Mage::getSingleton('core/translate');
        /* @var $translate Mage_Core_Model_Translate */
        $translate->setTranslateInline(false);

        $emailTemplate = Mage::getModel('core/email_template');
        /* @var $emailTemplate Mage_Core_Model_Email_Template */
        $emailTemplate->setDesignConfig(array('area' => 'backend'));
        $emailTemplate->sendTransactional(
            Mage::getStoreConfig(self::XML_PATH_EMAIL_TEMPLATE),
            Mage::getStoreConfig(self::XML_PATH_EMAIL_IDENTITY),
            Mage::getStoreConfig(self::XML_PATH_EMAIL_RECIPIENT),
            null,
            array('error' => $error, 'schedule' => $schedule)
        );

        $translate->setTranslateInline(true);
    }

    /**
     * Get callback from runModel
     *
     * @param $runModel
     *
     * @return array
     */
    public function getCallBack($runModel)
    {
        if (!preg_match(Mage_Cron_Model_Observer::REGEX_RUN_MODEL, (string)$runModel, $run)) {
            Mage::throwException(Mage::helper('cron')->__('Invalid model/method definition, expecting "model/class::method".'));
        }
        if (!($model = Mage::getModel($run[1])) || !method_exists($model, $run[2])) {
            Mage::throwException(Mage::helper('cron')->__('Invalid callback: %s::%s does not exist', $run[1], $run[2]));
        }
        $callback = array($model, $run[2]);
        return $callback;
    }

    /**
     * Validate cron expression
     *
     * @param $cronExpression
     *
     * @return bool
     */
    public function validateCronExpression($cronExpression)
    {
        try {
            $this->checkCronExpression($cronExpression, null);
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    public function startBuffering(Aoe_Scheduler_Model_Schedule $schedule)
    {
        if ($this->buffering || !Mage::getStoreConfigFlag('system/cron/enableJobOutputBuffer')) {
            return $this;
        }

        $callback = function ($buffer) use ($schedule) {
            $schedule->addMessages($buffer);
            $schedule->saveMessages();
            return $buffer;
        };

        ob_start($callback, $this->bufferChunkSize);

        $this->buffering = true;
    }

    public function stopBuffering()
    {
        if (!$this->buffering || !Mage::getStoreConfigFlag('system/cron/enableJobOutputBuffer')) {
            return $this;
        }

        ob_end_flush();

        $this->buffering = false;
    }

    /**
     * Checks if the time is valid for the cron expression
     *
     * Supports '* 0-5,10-59/5 2-10,15-25 january-june/2 mon-fri'
     *
     * @param string     $cronExpression
     * @param string|int $time
     *
     * @return bool
     * @throws Mage_Core_Exception
     */
    public function checkCronExpression($cronExpression, $time)
    {
        $e = preg_split('#\s+#', $cronExpression, null, PREG_SPLIT_NO_EMPTY);
        if (sizeof($e) < 5 || sizeof($e) > 6) {
            throw Mage::exception('Mage_Cron', 'Invalid cron expression: ' . $cronExpression);
        }

        if (!$time) {
            return false;
        }

        if (!is_numeric($time)) {
            $time = strtotime($time);
        }

        $d = getdate(Mage::getSingleton('core/date')->timestamp($time));

        $match = $this->matchCronExpression($e[0], $d['minutes'])
            && $this->matchCronExpression($e[1], $d['hours'])
            && $this->matchCronExpression($e[2], $d['mday'])
            && $this->matchCronExpression($e[3], $d['mon'])
            && $this->matchCronExpression($e[4], $d['wday']);

        return $match;
    }

    protected function matchCronExpression($expr, $num)
    {
        // handle ALL match
        if ($expr === '*') {
            return true;
        }

        if (strpos($expr, ',') !== false) {
            // handle multiple options
            foreach (explode(',', $expr) as $e) {
                if ($this->matchCronExpression($e, $num)) {
                    return true;
                }
            }
            return false;
        }

        $mod = 1;
        if (strpos($expr, '/') !== false) {
            // handle modulus
            $e = explode('/', $expr);
            if (sizeof($e) !== 2) {
                throw Mage::exception('Mage_Cron', "Invalid cron expression, expecting 'match/modulus': " . $expr);
            }
            if (!is_numeric($e[1])) {
                throw Mage::exception('Mage_Cron', "Invalid cron expression, expecting numeric modulus: " . $expr);
            }
            $expr = $e[0];
            $mod = $e[1];
        }

        if ($expr === '*') {
            // handle modulus only check
            return ($num % $mod === 0);
        }

        if (strpos($expr, '-') !== false) {
            // handle range
            $e = explode('-', $expr);
            if (sizeof($e) !== 2) {
                throw Mage::exception('Mage_Cron', "Invalid cron expression, expecting 'from-to' structure: " . $expr);
            }
            $from = $this->getNumeric($e[0]);
            $to = $this->getNumeric($e[1]);
        } else {
            // handle regular token
            $from = $this->getNumeric($expr);
            $to = $from;
        }

        if ($from === false || $to === false) {
            throw Mage::exception('Mage_Cron', "Invalid cron expression: " . $expr);
        }

        return ($num >= $from) && ($num <= $to) && ($num % $mod === 0);
    }

    protected function getNumeric($value)
    {
        static $data = array(
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
            'sun' => 0,
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
        );

        if (is_numeric($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(substr($value, 0, 3));
            if (isset($data[$value])) {
                return $data[$value];
            }
        }

        return false;
    }
}
