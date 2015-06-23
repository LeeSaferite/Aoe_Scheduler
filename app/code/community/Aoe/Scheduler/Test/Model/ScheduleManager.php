<?php

/**
 * @see Aoe_Scheduler_Model_ScheduleManager
 */
class Aoe_Scheduler_Test_Model_ScheduleManager extends EcomDev_PHPUnit_Test_Case
{
    /**
     * @test
     * @coversNothing
     *
     * @return Aoe_Scheduler_Model_ScheduleManager
     */
    public function checkModel()
    {
        /** @var Aoe_Scheduler_Model_ScheduleManager $manager */
        $manager = Mage::getModel('aoe_scheduler/scheduleManager');
        $this->assertInstanceOf('Aoe_Scheduler_Model_ScheduleManager', $manager);

        return $manager;
    }

    /**
     * @test
     * @covers markMissedSchedules::cleanMissedSchedules
     */
    public function cleanSchedules()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers Aoe_Scheduler_Model_ScheduleManager::handleDuplicates
     */
    public function deleteDuplicateSchedules()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers Aoe_Scheduler_Model_ScheduleManager::getPending
     */
    public function getPendingSchedules()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers generateAll::generateAllSchedules
     */
    public function generateSchedules()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers markMissedSchedules::cleanMissedSchedules
     */
    public function flushSchedules()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers generateForJob::generateJobSchedules
     */
    public function generateSchedulesForJob()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers deleteOldSchedules::deleteOld
     */
    public function cleanup()
    {
        $this->markTestIncomplete('TODO');
    }

    /**
     * @test
     * @covers Aoe_Scheduler_Model_ScheduleManager::logRun
     */
    public function logRun()
    {
        $this->markTestIncomplete('TODO');
    }
}
