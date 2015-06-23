<?php

/**
 * Schedule
 *
 * @method string getExecutedAt()
 * @method $this setExecutedAt(string $dateTime)
 * @method string getFinishedAt()
 * @method $this setFinishedAt(string $dateTime)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method string getMessages()
 * @method $this setMessages(string $sessages)
 * @method string getScheduledAt()
 * @method $this setScheduledAt(string $dateTime)
 * @method string getJobCode()
 * @method string setJobCode($jobCode)
 * @method string getCreatedAt()
 * @method $this setCreatedAt(string $dateTime)
 * @methos string getParameters()
 * @method $this setParameters(string $parameters)
 * @method string getEta()
 * @method $this setEta(string $dateTime)
 * @method string getHost()
 * @method $this setHost(string $hostname)
 * @method string getPid()
 * @method $this setPid(int $pid)
 * @method string getProgressMessage()
 * @method $this setProgressMessage(string $message)
 * @method string getLastSeen()
 * @method $this setLastSeen(string $dateTime)
 * @method string getScheduledBy()
 * @method $this setScheduledBy($scheduledBy)
 * @method string getScheduledReason()
 * @method $this setScheduledReason($scheduledReason)
 * @method string getKillRequest()
 * @method $this setKillRequest($killRequest)
 */
class Aoe_Scheduler_Model_Schedule extends Mage_Cron_Model_Schedule
{
    const STATUS_KILLED = 'killed';
    const STATUS_DISAPPEARED = 'gone'; // the status field is limited to 7 characters
    const STATUS_DIDNTDOANYTHING = 'nothing';
    const STATUS_LOCKED = 'locked';

    const REASON_GENERATESCHEDULES = 'generate_schedules';
    const REASON_DISPATCH_ALWAYS = 'dispatch_always';
    const REASON_RUNNOW_WEB = 'run_now_web';
    const REASON_RUNNOW_CLI = 'run_now_cli';
    const REASON_RUNNOW_API = 'run_now_api';
    const REASON_SCHEDULENOW_WEB = 'schedule_now_web';
    const REASON_SCHEDULENOW_CLI = 'schedule_now_cli';
    const REASON_SCHEDULENOW_API = 'schedule_now_api';
    const REASON_DEPENDENCY_ALL = 'dependency_all';
    const REASON_DEPENDENCY_SUCCESS = 'dependency_success';
    const REASON_DEPENDENCY_FAILURE = 'dependency_failure';

    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'aoe_scheduler_schedule';

    /**
     * Parameter name in event
     *
     * In observe method you can use $observer->getEvent()->getObject() in this case
     *
     * @var string
     */
    protected $_eventObject = 'schedule';

    /**
     * @var Aoe_Scheduler_Model_Job
     */
    protected $job;

    /**
     * @var bool
     */
    protected $jobWasLocked = false;

    /**
     * Placeholder to keep track of active redirect buffer.
     *
     * @var bool
     */
    protected $_redirect = false;

    /**
     * The buffer will be flushed after any output call which causes
     * the buffer's length to equal or exceed this value.
     *
     * Prior to PHP 5.4.0, the value 1 set the chunk size to 4096 bytes.
     */
    protected $_redirectOutputHandlerChunkSize = 100; // bytes

    /**
     * Get job configuration
     *
     * @return Aoe_Scheduler_Model_Job
     */
    public function getJob()
    {
        if (is_null($this->job)) {
            $this->job = Mage::getModel('aoe_scheduler/job')->load($this->getJobCode());
        }
        return $this->job;
    }

    /**
     * Get start time (planned or actual)
     *
     * @return string
     */
    public function getStarttime()
    {
        $starttime = $this->getExecutedAt();
        if (empty($starttime) || $starttime == '0000-00-00 00:00:00') {
            $starttime = $this->getScheduledAt();
        }
        return $starttime;
    }

    /**
     * Get job duration.
     *
     * @return int|false time in seconds or false
     */
    public function getDuration()
    {
        $duration = false;

        $executedAt = max(intval(strtotime($this->getExecutedAt())), 0);
        if($executedAt) {
            $finishedAt = max(intval(strtotime($this->getFinishedAt())), 0);
            if($finishedAt) {
                $duration = ($finishedAt - $executedAt);
            } elseif ($this->getStatus() == Aoe_Scheduler_Model_Schedule::STATUS_RUNNING) {
                $duration = (time() - $executedAt);
            }
        }

        return $duration;
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
     * Check if this is an "always" task
     *
     * @return bool
     */
    public function isAlwaysTask()
    {
        try {
            return $this->getJob()->isAlwaysTask();
        } catch (Exception $e) {
            Mage::logException($e);
            return false;
        }
    }

    /**
     * Processing object before save data
     *
     * Check if there are other schedules for the same job at the same time and skip saving in this case.
     *
     * @return Mage_Core_Model_Abstract
     */
    protected function _beforeSave()
    {
        if (!$this->getScheduledBy() && php_sapi_name() !== 'cli' && Mage::getSingleton('admin/session')->isLoggedIn()) {
            $this->setScheduledBy(Mage::getSingleton('admin/session')->getUser()->getId());
        }

        return parent::_beforeSave();
    }

    /**
     * Get parameters (and fallback to job)
     *
     * @return mixed
     */
    public function getParameters()
    {
        if ($this->getData('parameters')) {
            return $this->getData('parameters');
        }

        // fallback to job
        $job = $this->getJob();
        if ($job) {
            return $job->getParameters();
        } else {
            return false;
        }
    }

     /**
     * Append data to the current messages field.
     *
     * @param $messages
     *
     * @return $this
     */
    public function addMessages($messages)
    {
        $this->setMessages($this->getMessages() . $messages);

        return $this;
    }

    /**
     * Save the messages directly to the schedule record.
     *
     * If the `messages` field was not updated in the database,
     * check if this is because of `data truncation` and fix the message length.
     *
     * @return $this
     */
    public function saveMessages()
    {
        if (!$this->getId()) {
            return $this->save();
        }

        $connection = Mage::getSingleton('core/resource')
            ->getConnection('core_write');

        $count = $connection
            ->update(
                $this->getResource()->getMainTable(),
                array('messages' => $this->getMessages()),
                array('schedule_id = ?' => $this->getId())
            );

        if (!$count) {
            /**
             * Check if the row was not updated because of data truncation.
             */
            $warning = $this->_getPdoWarning($connection->getConnection());
            if ($warning && $warning->Code = 1265) {
                $maxLength = strlen($this->getMessages()) - 5000;
                $this->setMessages(
                    $warning->Level . ': ' .
                    str_replace(' at row 1', '.', $warning->Message) . PHP_EOL . PHP_EOL .
                    '...' . substr($this->getMessages(), -$maxLength)
                );
            }
        }

        return $this;
    }

    /**
     * Retrieve the last PDO warning.
     *
     * @param PDO $pdo
     *
     * @return mixed
     */
    protected function _getPdoWarning(PDO $pdo)
    {
        $originalErrorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

        $stm = $pdo->query('SHOW WARNINGS');

        $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode);

        return $stm->fetchObject();
    }

    /**
     * Bypass parent's setCronExpr if the expression is "always"
     * This will break trySchedule, but always tasks will never be tried to scheduled anyway
     *
     * @param $expr
     *
     * @return $this
     * @throws Mage_Core_Exception
     */
    public function setCronExpr($expr)
    {
        if ($expr == 'always') {
            $this->setData('cron_expr', $expr);
        } else {
            parent::setCronExpr($expr);
        }
        return $this;
    }
}
