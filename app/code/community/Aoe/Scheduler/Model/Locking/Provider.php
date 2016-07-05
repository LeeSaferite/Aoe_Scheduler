<?php

interface Aoe_Scheduler_Model_Locking_Provider
{
    /**
     * Check if this provider is able to provide locks
     *
     * @return bool
     */
    public function canUse();

    /**
     * Check if a key is locked by any process already
     *
     * @param string $key
     *
     * @return bool
     */
    public function isLocked($key);

    /**
     * Attempt to acquire a lock for this processes
     *
     * If this process already owns the lock then it will be renewed
     * If the lock belongs to another process then this process will attempts to wait for the lock
     *
     * @param string $key
     * @param int    $timeout
     *
     * @return bool
     */
    public function acquireLock($key, $timeout);

    /**
     * Release a lock owned by this process
     *
     * @param string $key
     *
     * @return bool
     */
    public function releaseLock($key);

    /**
     * Perform standard maintenance
     *
     * @return bool
     */
    public function cleanup();

    /**
     * Attempt to purge all outstanding locks
     *
     * @return bool
     */
    public function purge();
}
