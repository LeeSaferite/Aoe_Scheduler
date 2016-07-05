<?php

/**
 * @group Aoe_Scheduler_Test_Helper_Locking
 */
class Aoe_Scheduler_Test_Helper_Locking extends EcomDev_PHPUnit_Test_Case
{
    /**
     * @test
     */
    public function loadHelper()
    {
        /** @var Aoe_Scheduler_Helper_Locking $helper */
        $helper = Mage::helper('aoe_scheduler/locking');
        $this->assertInstanceOf('Aoe_Scheduler_Helper_Locking', $helper);
    }

    /**
     * Test that a single process can acquire and release a lock
     *
     * @test
     * @depends loadHelper
     */
    public function simpleLock()
    {
        $helper = $this->getFreshHelper();
        $helper->purge();

        $this->assertFalse($helper->isLocked('test'));
        $this->assertTrue($helper->acquireLock('test'));
        $this->assertTrue($helper->isLocked('test'));
        $this->assertTrue($helper->acquireLock('test'));
        $this->assertTrue($helper->isLocked('test'));
        $this->assertTrue($helper->releaseLock('test'));
        $this->assertFalse($helper->isLocked('test'));

        $helper->purge();
    }

    /**
     * Test that a single process can acquire and release multiple nested locks
     *
     * @test
     * @depends loadHelper
     */
    public function nestedLocks()
    {
        $helper = $this->getFreshHelper();
        $helper->purge();

        $this->assertFalse($helper->isLocked('test1'));
        $this->assertTrue($helper->acquireLock('test1'));
        $this->assertTrue($helper->isLocked('test1'));

        $this->assertFalse($helper->isLocked('test2'));
        $this->assertTrue($helper->acquireLock('test2'));
        $this->assertTrue($helper->isLocked('test2'));

        $this->assertTrue($helper->isLocked('test1'));
        $this->assertTrue($helper->acquireLock('test1'));
        $this->assertTrue($helper->isLocked('test1'));

        $this->assertTrue($helper->isLocked('test2'));
        $this->assertTrue($helper->acquireLock('test2'));
        $this->assertTrue($helper->isLocked('test2'));

        $this->assertTrue($helper->releaseLock('test1'));
        $this->assertFalse($helper->isLocked('test1'));

        $this->assertTrue($helper->releaseLock('test2'));
        $this->assertFalse($helper->isLocked('test2'));

        $helper->purge();
    }

    /**
     * Test that multiple processes cannot acquire the same lock simultaneously
     *
     * @test
     * @depends loadHelper
     */
    public function concurrentLock()
    {
        $helper1 = $this->getFreshHelper();
        $helper1->purge();

        $helper2 = $this->getFreshHelper();

        $this->assertFalse($helper1->isLocked('test'));
        $this->assertFalse($helper2->isLocked('test'));

        $this->assertTrue($helper1->acquireLock('test'));
        $this->assertFalse($helper2->acquireLock('test', 1));

        $this->assertTrue($helper1->isLocked('test'));
        $this->assertTrue($helper2->isLocked('test'));

        $this->assertFalse($helper2->releaseLock('test'));
        $this->assertTrue($helper1->releaseLock('test'));

        $this->assertTrue($helper2->acquireLock('test'));
        $this->assertFalse($helper1->acquireLock('test', 1));

        $this->assertTrue($helper2->isLocked('test'));
        $this->assertTrue($helper1->isLocked('test'));

        $this->assertFalse($helper1->releaseLock('test'));
        $this->assertTrue($helper2->releaseLock('test'));

        $this->assertFalse($helper1->isLocked('test'));
        $this->assertFalse($helper2->isLocked('test'));

        $helper1->purge();
    }

    /**
     * Test that lock expiration works as expected for the Aoe_Scheduler_Model_Locking_Mysql_Table provider
     *
     * @test
     * @depends loadHelper
     */
    public function lockExpire()
    {
        $helper = $this->getFreshHelper();

        /** @var Aoe_Scheduler_Model_Locking_Mysql_Table $provider */
        $provider = $helper->getProvider();
        if (!$provider instanceof Aoe_Scheduler_Model_Locking_Mysql_Table) {
            $this->markTestSkipped("Locking provider is not an instance of Aoe_Scheduler_Model_Locking_Mysql_Table");
        }

        $helper->purge();

        // Allow us to modify the lock expiration time
        $setExpires = Closure::bind(
            function ($seconds) {
                $this->expireSeconds = intval($seconds);
            },
            $provider,
            get_class($provider)
        );

        $this->assertFalse($helper->isLocked('test'));
        $this->assertTrue($helper->acquireLock('test'));
        $this->assertTrue($helper->isLocked('test'));
        $this->assertTrue($helper->releaseLock('test'));
        $this->assertFalse($helper->isLocked('test'));

        // Force lock expiration time to 1 second
        $setExpires(1);

        $this->assertFalse($helper->isLocked('test'));
        $this->assertTrue($helper->acquireLock('test'));
        $this->assertTrue($helper->isLocked('test'));

        // Allow lock to expire
        sleep(2);

        $this->assertFalse($helper->isLocked('test'));
        $this->assertTrue($helper->acquireLock('test'));
        $this->assertTrue($helper->isLocked('test'));
        $this->assertTrue($helper->releaseLock('test'));
        $this->assertFalse($helper->isLocked('test'));

        $helper->purge();
    }

    /**
     * Test that acquiring multiple locks in a single method call works as expected
     *
     * @test
     * @depends loadHelper
     */
    public function multiLock()
    {
        $helper1 = $this->getFreshHelper();
        $helper1->purge();

        $locksNeeded = ['test1', 'test2'];

        try {
            $locksAcquired = $helper1->acquireAllLocks($locksNeeded);

            foreach ($locksAcquired as $lock) {
                $this->assertTrue($helper1->isLocked($lock));
            }

            $helper1->releaseAllLocks($locksAcquired);

            foreach ($locksAcquired as $lock) {
                $this->assertFalse($helper1->isLocked($lock));
            }
        } catch (Aoe_Scheduler_CouldNotLockException $e) {
            $this->fail("Could not acquire all locks");
        }

        $helper2 = $this->getFreshHelper();

        $this->assertFalse($helper2->isLocked($locksNeeded[0]));
        $this->assertTrue($helper2->acquireLock($locksNeeded[0]));
        $this->assertTrue($helper2->isLocked($locksNeeded[0]));

        try {
            $helper1->acquireAllLocks($locksNeeded, 2);
            $this->fail("Expected Aoe_Scheduler_CouldNotLockException not thrown.");
        } catch (Aoe_Scheduler_CouldNotLockException $e) {
            // This is just to keep the assertion count correct
            $this->assertTrue(true);
        }

        $this->assertTrue($helper2->isLocked($locksNeeded[0]));
        $this->assertTrue($helper2->releaseLock($locksNeeded[0]));
        $this->assertFalse($helper2->isLocked($locksNeeded[0]));

        $helper1->purge();
    }

    /**
     * @return Aoe_Scheduler_Helper_Locking
     */
    protected function getFreshHelper()
    {
        // Remove any existing lock helper
        Mage::unregister('_helper/aoe_scheduler/locking');

        /** @var Aoe_Scheduler_Helper_Locking $helper */
        $helper = Mage::helper('aoe_scheduler/locking');

        return $helper;
    }
}
