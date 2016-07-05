<?php
/* @var Mage_Core_Model_Resource_Setup $this */

$this->startSetup();

$table = $this->getConnection()->newTable($this->getTable('aoe_scheduler/locking_mysql_table'));

$table->addColumn(
    'key',
    Varien_Db_Ddl_Table::TYPE_TEXT,
    40,
    ['primary' => true, 'nullable' => false],
    'Lock Key'
);
$table->addColumn(
    'owner',
    Varien_Db_Ddl_Table::TYPE_TEXT,
    40,
    ['nullable' => false],
    'Lock Owner'
);
$table->addColumn(
    'created_at',
    Varien_Db_Ddl_Table::TYPE_DATETIME,
    null,
    ['nullable' => false],
    'When the lock was created'
);
$table->addColumn(
    'expires_at',
    Varien_Db_Ddl_Table::TYPE_DATETIME,
    null,
    ['nullable' => false],
    'When the lock will expire'
);

$table->addIndex(
    $this->getIdxName('aoe_scheduler/locking_mysql_table', ['key', 'owner']),
    ['key', 'owner']
);

$this->getConnection()->createTable($table);

$this->endSetup();
