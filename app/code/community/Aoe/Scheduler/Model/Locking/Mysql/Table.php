<?php

class Aoe_Scheduler_Model_Locking_Mysql_Table implements Aoe_Scheduler_Model_Locking_Provider
{
    /** @var Varien_Db_Adapter_Interface */
    protected $connection;

    /** @var string */
    protected $table;

    /** @var string */
    protected $owner;

    /** @var int */
    protected $expireSeconds = 600;

    public function __construct()
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getModel('core/resource');

        $this->connection = $resource->getConnection('core_write');
        $this->table = $resource->getTableName('aoe_scheduler/locking_mysql_table');
        $this->owner = sha1(uniqid('', true));
        $this->expireSeconds = min(max(intval(Mage::getStoreConfig('system/cron/locking_provider/mysql_table/expire_seconds', Mage_Core_Model_Store::ADMIN_CODE)), 60), 3600);
    }

    /**
     * @return bool
     */
    public function canUse()
    {
        return $this->connection->isTableExists($this->table);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function isLocked($key)
    {
        $this->cleanup();

        $select = $this->connection->select()->from($this->table)->where('`key` = ?', $this->getLockKey($key));
        $rows = $this->connection->query($select)->rowCount();

        return ($rows > 0);
    }

    /**
     * Aquire a lock for this process
     *
     * @param string $key
     * @param int    $timeout
     *
     * @return bool
     */
    public function acquireLock($key, $timeout)
    {
        $timeout = max(intval($timeout), 0);
        $start = time();
        $now = $start;
        $rows = 0;
        $loop = 0;
        while (($now - $start) <= $timeout) {
            // Expire locks
            $this->cleanup();

            // Increment loop counter - Used when updating expires_at time to ensure we get an updated row
            $loop++;

            // Try to insert a new lock
            $rows = $this->connection->insertIgnore(
                $this->table,
                [
                    'key'        => $this->getLockKey($key),
                    'owner'      => $this->owner,
                    'created_at' => new Zend_Db_Expr('NOW()'),
                    'expires_at' => new Zend_Db_Expr("DATE_ADD(NOW(), INTERVAL {$this->expireSeconds} SECOND)"),
                ]
            );

            if (!$rows) {
                // Try to update the lock if it's ours
                $expire = $this->expireSeconds + $loop;
                $rows = $this->connection->update(
                    $this->table,
                    [
                        'expires_at' => new Zend_Db_Expr("DATE_ADD(NOW(), INTERVAL {$expire} SECOND)"),
                    ],
                    [
                        '`key` = ?' => $this->getLockKey($key),
                        'owner = ?' => $this->owner,
                    ]
                );
            }

            // Break the loop early if we have updated rows
            if ($rows) {
                break;
            }

            // Update the current timestamp
            $now = time();

            // Delay if we were unable to get a lock
            if (!$rows && ($now - $start) <= $timeout) {
                // 100ms delay
                usleep(250000);
            }
        }

        return (bool)$rows;
    }

    /**
     * Release a lock owned by this process
     *
     * @param string $key
     *
     * @return bool
     */
    public function releaseLock($key)
    {
        $rows = $this->connection->delete($this->table, ['`key` = ?' => $this->getLockKey($key), 'owner = ?' => $this->owner]);

        $this->cleanup();

        return ($rows > 0);
    }

    /**
     * Delete expired locks
     *
     * @return bool
     */
    public function cleanup()
    {
        $this->connection->delete($this->table, new Zend_Db_Expr('expires_at < NOW()'));

        return true;
    }

    /**
     * Purge entire lock table
     *
     * @return bool
     */
    public function purge()
    {
        $this->connection->truncateTable($this->table);

        return true;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    protected function getLockKey($key)
    {
        return sha1(trim($key));
    }
}
