<?php

/**
 * @see Aoe_Scheduler_Model_ProcessManager
 * @group Aoe_Scheduler_Model_ProcessManager
 */
class Aoe_Scheduler_Test_Model_ProcessManager extends EcomDev_PHPUnit_Test_Case
{
    /**
     * @test
     * @coversNothing
     *
     * @return Aoe_Scheduler_Model_ProcessManager
     */
    public function checkModel()
    {
        /** @var Aoe_Scheduler_Model_ProcessManager $manager */
        $manager = Mage::getModel('aoe_scheduler/processManager');
        $this->assertInstanceOf('Aoe_Scheduler_Model_ProcessManager', $manager);

        return $manager;
    }

    /**
     * @test
     * @covers Aoe_Scheduler_Model_ProcessManager::getAllRunningSchedules
     */
    public function getAllRunningSchedules()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers Aoe_Scheduler_Model_ProcessManager::check
     */
    public function check()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers Aoe_Scheduler_Model_ProcessManager::kill
     */
    public function kill()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers Aoe_Scheduler_Model_ProcessManager::isJobCodeRunning
     */
    public function isJobCodeRunning()
    {
        $jobCode = uniqid();

        // Check with no schedules
        /** @var EcomDev_PHPUnit_Mock_Proxy|Aoe_Scheduler_Model_Resource_Schedule_Collection $schedules */
        $schedules = $this->mockClassAlias('resource_model', 'cron/schedule_collection', array('addFieldToFilter', 'getIterator'));
        $schedules->expects($this->once())->method('addFieldToFilter')->with('job_code', $jobCode)->will($this->returnSelf());
        $schedules->expects($this->once())->method('getIterator')->with()->will($this->returnValue(new ArrayIterator(array())));
        // This is MANDATORY as the proxy fails the needed checks to work with foreach
        $schedules = $schedules->getMockInstance();

        /** @var EcomDev_PHPUnit_Mock_Proxy|Aoe_Scheduler_Model_ProcessManager $manager */
        $manager = $this->mockModel('aoe_scheduler/processManager', array('getAllRunningSchedules', 'check'));
        $manager->expects($this->once())->method('getAllRunningSchedules')->with()->will($this->returnValue($schedules));
        $manager->expects($this->never())->method('check');

        $this->assertFalse($manager->isJobCodeRunning($jobCode));

        // Check a single schedule with 'true' from check()
        /** @var Aoe_Scheduler_Model_Schedule $schedule */
        $schedule = Mage::getModel('cron/schedule');

        /** @var EcomDev_PHPUnit_Mock_Proxy|Aoe_Scheduler_Model_Resource_Schedule_Collection $schedules */
        $schedules = $this->mockClassAlias('resource_model', 'cron/schedule_collection', array('addFieldToFilter', 'getIterator'));
        $schedules->expects($this->once())->method('addFieldToFilter')->with('job_code', $jobCode)->will($this->returnSelf());
        $schedules->expects($this->once())->method('getIterator')->will($this->returnValue(new ArrayIterator(array($schedule))));
        // This is MANDATORY as the proxy fails the needed checks to work with foreach
        $schedules = $schedules->getMockInstance();

        /** @var EcomDev_PHPUnit_Mock_Proxy|Aoe_Scheduler_Model_ProcessManager $manager */
        $manager = $this->mockModel('aoe_scheduler/processManager', array('getAllRunningSchedules', 'check'));
        $manager->expects($this->once())->method('getAllRunningSchedules')->with()->will($this->returnValue($schedules));
        $manager->expects($this->once())->method('check')->with($schedule)->will($this->returnValue(true));

        $this->assertTrue($manager->isJobCodeRunning($jobCode));

        // Check a single schedule with 'null' from check()
        /** @var Aoe_Scheduler_Model_Schedule $schedule */
        $schedule = Mage::getModel('cron/schedule');

        /** @var EcomDev_PHPUnit_Mock_Proxy|Aoe_Scheduler_Model_Resource_Schedule_Collection $schedules */
        $schedules = $this->mockClassAlias('resource_model', 'cron/schedule_collection', array('addFieldToFilter', 'getIterator'));
        $schedules->expects($this->once())->method('addFieldToFilter')->with('job_code', $jobCode)->will($this->returnSelf());
        $schedules->expects($this->once())->method('getIterator')->will($this->returnValue(new ArrayIterator(array($schedule))));
        // This is MANDATORY as the proxy fails the needed checks to work with foreach
        $schedules = $schedules->getMockInstance();

        /** @var EcomDev_PHPUnit_Mock_Proxy|Aoe_Scheduler_Model_ProcessManager $manager */
        $manager = $this->mockModel('aoe_scheduler/processManager', array('getAllRunningSchedules', 'check'));
        $manager->expects($this->once())->method('getAllRunningSchedules')->with()->will($this->returnValue($schedules));
        $manager->expects($this->once())->method('check')->with($schedule)->will($this->returnValue(null));

        $this->assertTrue($manager->isJobCodeRunning($jobCode));

        // Check a single schedule with 'false' from check()
        /** @var Aoe_Scheduler_Model_Schedule $schedule */
        $schedule = Mage::getModel('cron/schedule');

        /** @var EcomDev_PHPUnit_Mock_Proxy|Aoe_Scheduler_Model_Resource_Schedule_Collection $schedules */
        $schedules = $this->mockClassAlias('resource_model', 'cron/schedule_collection', array('addFieldToFilter', 'getIterator'));
        $schedules->expects($this->once())->method('addFieldToFilter')->with('job_code', $jobCode)->will($this->returnSelf());
        $schedules->expects($this->once())->method('getIterator')->will($this->returnValue(new ArrayIterator(array($schedule))));
        // This is MANDATORY as the proxy fails the needed checks to work with foreach
        $schedules = $schedules->getMockInstance();

        /** @var EcomDev_PHPUnit_Mock_Proxy|Aoe_Scheduler_Model_ProcessManager $manager */
        $manager = $this->mockModel('aoe_scheduler/processManager', array('getAllRunningSchedules', 'check'));
        $manager->expects($this->once())->method('getAllRunningSchedules')->with()->will($this->returnValue($schedules));
        $manager->expects($this->once())->method('check')->with($schedule)->will($this->returnValue(false));

        $this->assertFalse($manager->isJobCodeRunning($jobCode));
    }

    /**
     * @test
     * @covers Aoe_Scheduler_Model_ProcessManager::watchdog
     * @depends checkModel
     */
    public function watchdog()
    {
        /** @var EcomDev_PHPUnit_Mock_Proxy|Aoe_Scheduler_Model_ProcessManager $mock */
        $mock = $this->mockModel('aoe_scheduler/processManager', array('checkRunningJobs', 'processKillRequests'));
        $mock->expects($this->once())->method('checkRunningJobs')->with();
        $mock->expects($this->once())->method('processKillRequests')->with();

        $mock->watchdog();
    }

    /**
     * @test
     * @covers Aoe_Scheduler_Model_ProcessManager::checkRunningJobs
     */
    public function checkRunningJobs()
    {
        $this->markTestIncomplete('TODO');
    }

}
