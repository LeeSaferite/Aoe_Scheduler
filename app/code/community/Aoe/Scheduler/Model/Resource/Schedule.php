<?php

class Aoe_Scheduler_Model_Resource_Schedule extends Mage_Cron_Model_Resource_Schedule
{
    /**
     * Save the messages directly to the schedule record.
     *
     * If the `messages` field was not updated in the database,
     * check if this is because of `data truncation` and fix the message length.
     * This WILL NOT attempt to resave the messages after truncation.
     *
     * @param Aoe_Scheduler_Model_Schedule $schedule
     *
     * @return Aoe_Scheduler_Model_Schedule $schedule
     * @throws Exception
     */
    public function saveMessages(Aoe_Scheduler_Model_Schedule $schedule)
    {
        if (!$schedule->getId()) {
            return $schedule->save();
        }

        /* @var Zend_Db_Adapter_Abstract $connection */
        $connection = $this->_getWriteAdapter();

        $count = $connection->update(
            $this->getMainTable(),
            ['messages' => $schedule->getMessages()],
            ['schedule_id = ?' => $schedule->getId()]
        );

        if (!$count && $connection instanceof Zend_Db_Adapter_Pdo_Mysql) {
            /**
             * Check if the row was not updated because of data truncation.
             */
            $warning = $this->_getPdoWarning($connection);
            if ($warning && $warning->Code = 1265) {
                $maxLength = strlen($schedule->getMessages()) - 5000;
                $schedule->setMessages(
                    $warning->Level . ': ' .
                    str_replace(' at row 1', '.', $warning->Message) . PHP_EOL . PHP_EOL .
                    '...' . substr($schedule->getMessages(), -$maxLength)
                );
            }
        }

        return $schedule;
    }

    /**
     * Retrieve the last PDO warning.
     *
     * @param Zend_Db_Adapter_Pdo_Abstract $connection
     *
     * @return mixed
     */
    protected function _getPdoWarning(Zend_Db_Adapter_Pdo_Abstract $connection)
    {
        /** @var PDO $pdo */
        $pdo = $connection->getConnection();
        $originalErrorMode = $pdo->getAttribute($pdo::ATTR_ERRMODE);
        $pdo->setAttribute($pdo::ATTR_ERRMODE, $pdo::ERRMODE_WARNING);
        $stm = $pdo->query('SHOW WARNINGS');
        $pdo->setAttribute($pdo::ATTR_ERRMODE, $originalErrorMode);

        return $stm->fetchObject();
    }
}
