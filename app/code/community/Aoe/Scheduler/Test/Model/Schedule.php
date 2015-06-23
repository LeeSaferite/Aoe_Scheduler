<?php

/**
 * @see Aoe_Scheduler_Model_Schedule
 */
class Aoe_Scheduler_Test_Model_Schedule extends EcomDev_PHPUnit_Test_Case
{
    /**
     * @test
     * @coversNothing
     *
     * @return Aoe_Scheduler_Model_Schedule
     */
    public function checkModel()
    {
        $this->assertInstanceOf('Aoe_Scheduler_Model_Schedule', Mage::getModel('cron/schedule'));

        /** @var Aoe_Scheduler_Model_Schedule $schedule */
        $schedule = Mage::getModel('aoe_scheduler/schedule');
        $this->assertInstanceOf('Aoe_Scheduler_Model_Schedule', $schedule);

        return $schedule;
    }

    /**
     * @test
     * @depends checkModel
     * @coversNothing
     *
     * @param Aoe_Scheduler_Model_Schedule $schedule
     */
    public function checkResource(Aoe_Scheduler_Model_Schedule $schedule)
    {
        $this->assertInstanceOf('Aoe_Scheduler_Model_Resource_Schedule', $schedule->getResource());
        $this->assertSame($schedule->getResource(), Mage::getResourceSingleton('cron/schedule'));
    }

    /**
     * @test
     * @depends checkModel
     * @coversNothing
     *
     * @param Aoe_Scheduler_Model_Schedule $schedule
     */
    public function checkCollection(Aoe_Scheduler_Model_Schedule $schedule)
    {
        $this->assertInstanceOf('Aoe_Scheduler_Model_Resource_Schedule_Collection', $schedule->getCollection());
        $this->assertInstanceOf('Aoe_Scheduler_Model_Resource_Schedule_Collection', $schedule->getResourceCollection());
        $this->assertInstanceOf('Aoe_Scheduler_Model_Resource_Schedule_Collection', Mage::getResourceModel('aoe_scheduler/schedule_collection'));
    }
}
