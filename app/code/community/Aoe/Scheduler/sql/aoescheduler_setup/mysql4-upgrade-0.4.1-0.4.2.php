<?php

$installer = $this;
/* @var Mage_Core_Model_Resource_Setup $installer */

$installer->startSetup();

$tableName = $installer->getTable('cron_schedule');

$installer->getConnection()->addColumn(
    $tableName,
    'last_seen',
    "timestamp NULL DEFAULT NULL COMMENT 'Last seen'"
);

$installer->endSetup();
