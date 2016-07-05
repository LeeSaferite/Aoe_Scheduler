<?php

class Aoe_Scheduler_Helper_Locking extends Mage_Core_Helper_Abstract implements Aoe_Scheduler_Model_Locking_Provider
{
    const LOCK_GET_TIMEOUT = 10;

    /** @var Aoe_Scheduler_Model_Locking_Provider */
    protected $provider;

    public function __construct()
    {
        $providers = explode(',', str_replace("\n", ",", Mage::getStoreConfig('system/cron/locking_providers', Mage_Core_Model_Store::ADMIN_CODE)));
        $providers = array_filter(array_map('trim', $providers));

        foreach ($providers as $providerModel) {
            try {
                /** @var Aoe_Scheduler_Model_Locking_Provider $provider */
                $provider = Mage::getModel($providerModel);
                if ($provider instanceof Aoe_Scheduler_Model_Locking_Provider && $provider->canUse()) {
                    $this->provider = $provider;
                    break;
                }
                Mage::log(sprintf("Cannot use %s (%s) as a locking provider", $providerModel, get_class($provider)), Zend_Log::DEBUG);
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        if (!$this->provider instanceof Aoe_Scheduler_Model_Locking_Provider) {
            throw new InvalidArgumentException('No valid locking provider found');
        }
    }

    /**
     * Return an array of lock keys that must be acquired before the job can be processed
     *
     * @param Aoe_Scheduler_Model_Job $job
     *
     * @return string[]
     */
    public function getLockKeys(Aoe_Scheduler_Model_Job $job)
    {
        // TODO: Allow a job to be locked with groups and/or nodes as well

        return [$job->getJobCode()];
    }

    /**
     * @return bool
     */
    public function canUse()
    {
        return $this->provider->canUse();
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function isLocked($key)
    {
        return $this->provider->isLocked($key);
    }

    /**
     * @param string $key
     * @param int    $timeout
     *
     * @return bool
     */
    public function acquireLock($key, $timeout = self::LOCK_GET_TIMEOUT)
    {
        return $this->provider->acquireLock($key, $timeout);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function releaseLock($key)
    {
        return $this->provider->releaseLock($key);
    }

    /**
     * Perform standard maintenance
     *
     * @return bool
     */
    public function cleanup()
    {
        return $this->provider->cleanup();
    }

    /**
     * Attempt to purge all outstanding locks
     *
     * @return bool
     */
    public function purge()
    {
        return $this->provider->purge();
    }

    /**
     * @return Aoe_Scheduler_Model_Locking_Provider
     */
    public function getProvider()
    {
        return $this->provider;
    }

    /**
     * @param string[] $needed
     * @param int      $timeout
     *
     * @return string[]
     *
     * @throws Aoe_Scheduler_CouldNotLockException
     */
    public function acquireAllLocks(array $needed, $timeout = self::LOCK_GET_TIMEOUT)
    {
        $needed = array_filter(array_unique($needed));
        if (count($needed) === 0) {
            throw new Aoe_Scheduler_CouldNotLockException();
        }

        // Acquire locks
        $acquired = [];
        try {
            foreach ($needed as $key) {
                if (!$this->acquireLock($key, $timeout)) {
                    break;
                }
                $acquired[] = $key;
            }
        } catch (Exception $e) {
            // This is unexpected, so log it but continue normal operation which SHOULD release all the locks
            Mage::logException($e);
        }

        // Check that we acquired all needed locks
        if (count(array_diff($needed, $acquired)) > 0) {
            foreach ($acquired as $key) {
                try {
                    $this->releaseLock($key);
                } catch (Exception $e) {
                    // This is unexpected, so log it and continue trying to release locks
                    Mage::logException($e);
                }
            }

            // Since we failed to acquire all the locks, throw an exception
            throw new Aoe_Scheduler_CouldNotLockException();
        }

        return $acquired;
    }

    /**
     * @param string[] $acquired
     */
    public function releaseAllLocks(array $acquired)
    {
        $acquired = array_filter(array_unique($acquired));

        // Release locks
        foreach ($acquired as $key) {
            try {
                $this->releaseLock($key);
            } catch (Exception $e) {
                // This is unexpected, so log it and continue trying to release locks
                Mage::logException($e);
            }
        }
    }
}
