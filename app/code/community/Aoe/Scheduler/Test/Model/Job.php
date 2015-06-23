<?php

/**
 * @see Aoe_Scheduler_Model_Job
 */
class Aoe_Scheduler_Test_Model_Job extends EcomDev_PHPUnit_Test_Case
{
    /**
     * @test
     * @coversNothing
     *
     * @return Aoe_Scheduler_Model_Job
     */
    public function checkModel()
    {
        /** @var Aoe_Scheduler_Model_Job $job */
        $job = Mage::getModel('aoe_scheduler/job');
        $this->assertInstanceOf('Aoe_Scheduler_Model_Job', $job);

        return $job;
    }

    /**
     * @test
     * @depends checkModel
     * @coversNothing
     *
     * @param Aoe_Scheduler_Model_Job $job
     */
    public function checkResource(Aoe_Scheduler_Model_Job $job)
    {
        $this->assertInstanceOf('Aoe_Scheduler_Model_Resource_Job', $job->getResource());
        $this->assertSame($job->getResource(), Mage::getResourceSingleton('aoe_scheduler/job'));
    }

    /**
     * @test
     * @depends checkModel
     * @coversNothing
     *
     * @param Aoe_Scheduler_Model_Job $job
     */
    public function checkCollection(Aoe_Scheduler_Model_Job $job)
    {
        $this->assertInstanceOf('Aoe_Scheduler_Model_Resource_Job_Collection', $job->getCollection());
        $this->assertInstanceOf('Aoe_Scheduler_Model_Resource_Job_Collection', $job->getResourceCollection());
        $this->assertInstanceOf('Aoe_Scheduler_Model_Resource_Job_Collection', Mage::getResourceModel('aoe_scheduler/job_collection'));
    }
}
