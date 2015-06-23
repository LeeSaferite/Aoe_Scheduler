<?php

class Aoe_Scheduler_Test_Model_Schedule_Scheduling extends EcomDev_PHPUnit_Test_Case
{
    public function setup()
    {
        // delete all schedules
        /* @var Mage_Cron_Model_Resource_Schedule_Collection $schedules */
        $schedules = Mage::getModel('cron/schedule')->getCollection();
        $schedules->getConnection()->delete($schedules->getMainTable());
    }

    /**
     * @test
     * @covers generateAll::generateAllSchedules
     */
    public function generateSchedule()
    {
        /* @var Mage_Cron_Model_Resource_Schedule_Collection $schedules */
        $schedules = Mage::getModel('cron/schedule')->getCollection();
        $this->assertCount(0, $schedules);

        // Ensure generate schedules is not skipped
        Mage::app()->cleanCache(array('crontab'));

        /* @var Aoe_Scheduler_Model_ScheduleManager $scheduleManager */
        $scheduleManager = Mage::getModel('aoe_scheduler/scheduleManager');
        $scheduleManager->generateAll();

        /* @var Mage_Cron_Model_Resource_Schedule_Collection $schedules */
        $schedules = Mage::getModel('cron/schedule')->getCollection();
        $this->assertGreaterThan(0, $schedules->count());
    }

    /**
     * @param $runCronCallBack callable
     *
     * @dataProvider runCronProvider
     * @test
     */
    public function scheduleJobAndRunCron($runCronCallBack)
    {
        // fake schedule generation to avoid it to be generated on the next run:
        Mage::app()->saveCache(time(), Mage_Cron_Model_Observer::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT, array('crontab'), null);

        /* @var Aoe_Scheduler_Model_Schedule $schedule */
        $schedule = Mage::getModel('cron/schedule');
        $schedule->setJobCode('aoescheduler_testtask');
        $schedule->setScheduledReason('unittest');

        /* @var Aoe_Scheduler_Model_ScheduleManager $manager */
        $manager = Mage::getModel('aoe_scheduler/scheduleManager');
        $manager->schedule($schedule);

        $scheduleId = $schedule->getId();
        $this->assertGreaterThan(0, intval($scheduleId));

        // check for pending status
        /* @var Aoe_Scheduler_Model_Schedule $loadedSchedule */
        $loadedSchedule = Mage::getModel('cron/schedule')->load($scheduleId);
        $this->assertEquals($scheduleId, $loadedSchedule->getId());
        $this->assertEquals(Aoe_Scheduler_Model_Schedule::STATUS_PENDING, $loadedSchedule->getStatus());

        // run cron
        $runCronCallBack();

        // check for success status
        /* @var Aoe_Scheduler_Model_Schedule $loadedSchedule */
        $loadedSchedule = Mage::getModel('cron/schedule')->load($scheduleId);
        $this->assertEquals($scheduleId, $loadedSchedule->getId());
        $this->assertEquals(Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS, $loadedSchedule->getStatus());
    }

    /**
     * Provider for a callback that executed a cron run
     *
     * @return array
     */
    public function runCronProvider()
    {
        return array(
            array(
                function () {
                    // trigger dispatch
                    /* @var $observer Aoe_Scheduler_Model_Observer */
                    $observer = Mage::getModel('aoe_scheduler/observer');
                    $observer->dispatch(new Varien_Event_Observer());
                }
            ),
//            array(
//                function () {
//                    shell_exec('cd ' . Mage::getBaseDir() . ' && /usr/bin/php ' . Mage::getBaseDir() . '/cron.php');
//                    shell_exec('cd ' . Mage::getBaseDir() . '/shell && /usr/bin/php scheduler.php --action wait');
//                }
//            ),
//            array(
//                function () {
//                    shell_exec('cd ' . Mage::getBaseDir() . ' && /bin/sh ' . Mage::getBaseDir() . '/cron.sh');
//                    shell_exec('cd ' . Mage::getBaseDir() . '/shell && /usr/bin/php scheduler.php --action wait');
//                }
//            ),
//            array(
//                function () {
//                    shell_exec('cd ' . Mage::getBaseDir() . ' && /bin/sh ' . Mage::getBaseDir() . '/cron.sh cron.php -mdefault 1');
//                    shell_exec('cd ' . Mage::getBaseDir() . '/shell && /usr/bin/php scheduler.php --action wait');
//                }
//            ),
//            array(
//                function () {
//                    shell_exec('cd ' . Mage::getBaseDir() . '/shell && /usr/bin/php scheduler.php --action cron --mode default');
//                    shell_exec('cd ' . Mage::getBaseDir() . '/shell && /usr/bin/php scheduler.php --action wait');
//                }
//            ),
//            array(
//                function () {
//                    shell_exec('cd ' . Mage::getBaseDir() . ' &&/bin/bash ' . Mage::getBaseDir() . '/scheduler_cron.sh');
//                    shell_exec('cd ' . Mage::getBaseDir() . '/shell && /usr/bin/php scheduler.php --action wait');
//                }
//            ),
//            array(
//                function () {
//                    shell_exec('cd ' . Mage::getBaseDir() . ' && /bin/bash ' . Mage::getBaseDir() . '/scheduler_cron.sh --mode default');
//                    shell_exec('cd ' . Mage::getBaseDir() . '/shell && /usr/bin/php scheduler.php --action wait');
//                }
//            )
        );
    }
}
