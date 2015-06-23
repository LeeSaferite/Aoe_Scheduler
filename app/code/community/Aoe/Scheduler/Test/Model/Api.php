<?php

/**
 * @see Aoe_Scheduler_Model_Api
 */
class Aoe_Scheduler_Test_Model_Api extends EcomDev_PHPUnit_Test_Case
{
    /**
     * @test
     * @coversNothing
     *
     * @return Aoe_Scheduler_Model_Api
     */
    public function getModel()
    {
        // Make sure we get the expected model
        /** @var Aoe_Scheduler_Model_Api $api */
        $api = Mage::getModel('aoe_scheduler/api');
        $this->assertInstanceOf('Aoe_Scheduler_Model_Api', $api);

        return $api;
    }

    /**
     * @test
     * @covers  Aoe_Scheduler_Model_Api::runNow
     * @depends getModel
     *
     * @param Aoe_Scheduler_Model_Api $api
     */
    public function runNow(Aoe_Scheduler_Model_Api $api)
    {
        // Enable the runNow feature
        $this->app()->getStore()->setConfig('system/cron/enableRunNow', true);

        // Code to pass to the API call
        $testCode = uniqid();
        // Response object (this is an object so we check that the result is EXACTLY what was expected)
        $testResponse = new stdClass();

        $mock = $this->mockModel('cron/schedule', array('setJobCode', 'setScheduledReason', 'runNow', 'getData'));
        // Expected minimal setup
        $mock->expects($this->once())->method('setJobCode')->with($testCode)->will($this->returnSelf());
        $mock->expects($this->once())->method('setScheduledReason')->with(Aoe_Scheduler_Model_Schedule::REASON_RUNNOW_API)->will($this->returnSelf());
        // Expected execution
        $mock->expects($this->once())->method('runNow')->with(false)->will($this->returnSelf());
        // Expected result generation
        $mock->expects($this->once())->method('getData')->with()->will($this->returnValue($testResponse));

        // Make sure that the call gets this mock
        $this->replaceByMock('model', 'cron/schedule', $mock);

        // Call the API method
        $response = $api->runNow($testCode);

        // Ensure that the EXACT response returned is what was setup in the mock
        $this->assertSame($testResponse, $response);
    }

    /**
     * @test
     * @covers  Aoe_Scheduler_Model_Api::runNow
     * @depends getModel
     *
     * @param Aoe_Scheduler_Model_Api $api
     */
    public function runNowDisabled(Aoe_Scheduler_Model_Api $api)
    {
        // Disable the runNow feature
        $this->app()->getStore()->setConfig('system/cron/enableRunNow', false);

        // Setup the expectation of an exception due to the feature being disabled
        $this->setExpectedException('Mage_Core_Exception', "'Run now' disabled by configuration (system/cron/enableRunNow)", 0);

        // Call the API method
        $api->runNow('test');
    }

    /**
     * @test
     * @covers  Aoe_Scheduler_Model_Api::schedule
     * @depends getModel
     *
     * @param Aoe_Scheduler_Model_Api $api
     */
    public function schedule(Aoe_Scheduler_Model_Api $api)
    {
        // Code to pass to the API call
        $testCode = uniqid();
        // Time to pass to the API call
        $testTime = rand(0, PHP_INT_MAX);

        // Response object (this is an object so we check that the result is EXACTLY what was expected)
        $testResponse = new stdClass();

        // Test with a time
        $mock = $this->mockModel('cron/schedule', array('setJobCode', 'setScheduledReason', 'schedule', 'getData'));
        // Expected minimal setup
        $mock->expects($this->once())->method('setJobCode')->with($testCode)->will($this->returnSelf());
        $mock->expects($this->once())->method('setScheduledReason')->with(Aoe_Scheduler_Model_Schedule::REASON_SCHEDULENOW_API)->will($this->returnSelf());
        // Expected execution
        $mock->expects($this->once())->method('schedule')->with($testTime)->will($this->returnSelf());
        // Expected result generation
        $mock->expects($this->once())->method('getData')->with()->will($this->returnValue($testResponse));

        // Make sure that the call gets this mock
        $this->replaceByMock('model', 'cron/schedule', $mock);

        // Call the API method
        $response = $api->schedule($testCode, $testTime);

        // Ensure that the EXACT response returned is what was setup in the mock
        $this->assertSame($testResponse, $response);

        // Test without a time
        $mock = $this->mockModel('cron/schedule', array('setJobCode', 'setScheduledReason', 'schedule', 'getData'));
        // Expected minimal setup
        $mock->expects($this->once())->method('setJobCode')->with($testCode)->will($this->returnSelf());
        $mock->expects($this->once())->method('setScheduledReason')->with(Aoe_Scheduler_Model_Schedule::REASON_SCHEDULENOW_API)->will($this->returnSelf());
        // Expected execution
        $mock->expects($this->once())->method('schedule')->with(null)->will($this->returnSelf());
        // Expected result generation
        $mock->expects($this->once())->method('getData')->with()->will($this->returnValue($testResponse));

        // Make sure that the call gets this mock
        $this->replaceByMock('model', 'cron/schedule', $mock);

        // Call the API method
        $response = $api->schedule($testCode);

        // Ensure that the EXACT response returned is what was setup in the mock
        $this->assertSame($testResponse, $response);
    }

    /**
     * @test
     * @covers  Aoe_Scheduler_Model_Api::info
     * @depends getModel
     *
     * @param Aoe_Scheduler_Model_Api $api
     */
    public function info(Aoe_Scheduler_Model_Api $api)
    {
        // ID to pass to the API call
        $testId = rand(0, PHP_INT_MAX);

        // Response object (this is an object so we check that the result is EXACTLY what was expected)
        $testResponse = new stdClass();

        $mock = $this->mockModel('cron/schedule', array('load', 'getData'));
        // Expected execution
        $mock->expects($this->once())->method('load')->with($testId)->will($this->returnSelf());
        // Expected result generation
        $mock->expects($this->once())->method('getData')->with()->will($this->returnValue($testResponse));

        // Make sure that the call gets this mock
        $this->replaceByMock('model', 'cron/schedule', $mock);

        // Call the API method
        $response = $api->info($testId);

        // Ensure that the EXACT response returned is what was setup in the mock
        $this->assertSame($testResponse, $response);
    }
}
