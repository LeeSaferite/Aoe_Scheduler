<?php
/* @var Mage_Core_Model_Resource_Setup $this */
$this->startSetup();

$this->getConnection()->dropTable($this->getTable('cron_job'));

$this->endSetup();
