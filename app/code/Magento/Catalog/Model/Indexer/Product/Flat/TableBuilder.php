<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Magento
 * @package     Magento_Catalog
 * @copyright   Copyright (c) 2014 X.commerce, Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
namespace Magento\Catalog\Model\Indexer\Product\Flat;

/**
 * Class TableBuilder
 * @package Magento\Catalog\Model\Indexer\Product\Flat
 */
class TableBuilder
{
    /**
     * @var \Magento\Catalog\Helper\Product\Flat\Indexer
     */
    protected $_productIndexerHelper;

    /**
     * @var \Magento\DB\Adapter\AdapterInterface
     */
    protected $_connection;

    /**
     * Check whether builder was executed
     *
     * @var bool
     */
    protected $_isExecuted = false;

    /**
     * @param \Magento\Catalog\Helper\Product\Flat\Indexer $productIndexerHelper
     * @param \Magento\App\Resource $resource
     */
    public function __construct(
        \Magento\Catalog\Helper\Product\Flat\Indexer $productIndexerHelper,
        \Magento\App\Resource $resource
    ) {
        $this->_productIndexerHelper = $productIndexerHelper;
        $this->_connection = $resource->getConnection('write');
    }

    /**
     * Prepare temporary tables only for first call of reindex all
     *
     * @param $storeId
     * @param $changedIds
     * @param $valueFieldSuffix
     */
    public function build($storeId, $changedIds, $valueFieldSuffix)
    {
        if ($this->_isExecuted) {
            return;
        }
        $entityTableName    = $this->_productIndexerHelper->getTable('catalog_product_entity');
        $attributes         = $this->_productIndexerHelper->getAttributes();
        $eavAttributes      = $this->_productIndexerHelper->getTablesStructure($attributes);
        $entityTableColumns = $eavAttributes[$entityTableName];

        $temporaryEavAttributes = $eavAttributes;

        //add status global value to the base table
        /* @var $status \Magento\Eav\Model\Entity\Attribute */
        $status = $this->_productIndexerHelper->getAttribute('status');
        $temporaryEavAttributes[$status->getBackendTable()]['status'] = $status;
        //Create list of temporary tables based on available attributes attributes
        $valueTables = array();
        foreach ($temporaryEavAttributes as $tableName => $columns) {
            $valueTables = array_merge(
                $valueTables,
                $this->_createTemporaryTable($this->_getTemporaryTableName($tableName), $columns, $valueFieldSuffix)
            );
        }

        //Fill "base" table which contains all available products
        $this->_fillTemporaryEntityTable($entityTableName, $entityTableColumns, $changedIds);

        //Add primary key to "base" temporary table for increase speed of joins in future
        $this->_addPrimaryKeyToTable($this->_getTemporaryTableName($entityTableName));
        unset($temporaryEavAttributes[$entityTableName]);

        foreach ($temporaryEavAttributes as $tableName => $columns) {
            $temporaryTableName = $this->_getTemporaryTableName($tableName);

            //Add primary key to temporary table for increase speed of joins in future
            $this->_addPrimaryKeyToTable($temporaryTableName);

            //Create temporary table for composite attributes
            if (isset($valueTables[$temporaryTableName . $valueFieldSuffix])) {
                $this->_addPrimaryKeyToTable($temporaryTableName . $valueFieldSuffix);
            }

            //Fill temporary tables with attributes grouped by it type
            $this->_fillTemporaryTable($tableName, $columns, $changedIds, $valueFieldSuffix, $storeId);
        }
        $this->_isExecuted = true;
    }

    /**
     * Create empty temporary table with given columns list
     *
     * @param string $tableName  Table name
     * @param array $columns array('columnName' => \Magento\Catalog\Model\Resource\Eav\Attribute, ...)
     * @param string $valueFieldSuffix
     *
     * @return array
     */
    protected function _createTemporaryTable($tableName, array $columns, $valueFieldSuffix)
    {
        $valueTables = array();
        if (!empty($columns)) {
            $valueTableName      = $tableName . $valueFieldSuffix;
            $temporaryTable      = $this->_connection->newTable($tableName);
            $valueTemporaryTable = $this->_connection->newTable($valueTableName);
            $flatColumns         = $this->_productIndexerHelper->getFlatColumns();

            $temporaryTable->addColumn(
                'entity_id',
                \Magento\DB\Ddl\Table::TYPE_INTEGER
            );

            $temporaryTable->addColumn(
                'type_id',
                \Magento\DB\Ddl\Table::TYPE_TEXT
            );

            $temporaryTable->addColumn(
                'attribute_set_id',
                \Magento\DB\Ddl\Table::TYPE_INTEGER
            );

            $valueTemporaryTable->addColumn(
                'entity_id',
                \Magento\DB\Ddl\Table::TYPE_INTEGER
            );

            /** @var $attribute \Magento\Catalog\Model\Resource\Eav\Attribute */
            foreach ($columns as $columnName => $attribute) {
                $attributeCode = $attribute->getAttributeCode();
                if (isset($flatColumns[$attributeCode])) {
                    $column = $flatColumns[$attributeCode];
                } else {
                    $column = $attribute->_getFlatColumnsDdlDefinition();
                    $column = $column[$attributeCode];
                }

                $temporaryTable->addColumn(
                    $columnName,
                    $column['type'],
                    isset($column['length']) ? $column['length'] : null
                );

                $columnValueName = $attributeCode . $valueFieldSuffix;
                if (isset($flatColumns[$columnValueName])) {
                    $columnValue = $flatColumns[$columnValueName];
                    $valueTemporaryTable->addColumn(
                        $columnValueName,
                        $columnValue['type'],
                        isset($columnValue['length']) ? $columnValue['length'] : null
                    );
                }
            }
            $this->_connection->dropTemporaryTable($tableName);
            $this->_connection->createTemporaryTable($temporaryTable);

            if (count($valueTemporaryTable->getColumns()) > 1) {
                $this->_connection->dropTemporaryTable($valueTableName);
                $this->_connection->createTemporaryTable($valueTemporaryTable);
                $valueTables[$valueTableName] = $valueTableName;
            }
        }
        return $valueTables;
    }

    /**
     * Retrieve temporary table name by regular table name
     *
     * @param string $tableName
     * @return string
     */
    protected function _getTemporaryTableName($tableName)
    {
        return sprintf('%s_tmp_indexer', $tableName);
    }

    /**
     * Fill temporary entity table
     *
     * @param string $tableName
     * @param array  $columns
     * @param array  $changedIds
     */
    protected function _fillTemporaryEntityTable($tableName, array $columns, array $changedIds = array())
    {
        if (!empty($columns)) {
            $select = $this->_connection->select();
            $temporaryEntityTable = $this->_getTemporaryTableName($tableName);
            $idsColumns = array(
                'entity_id',
                'type_id',
                'attribute_set_id',
            );

            $columns = array_merge($idsColumns, array_keys($columns));

            $select->from(array('e' => $tableName), $columns);
            $onDuplicate = false;
            if (!empty($changedIds)) {
                $select->where(
                    $this->_connection->quoteInto('e.entity_id IN (?)', $changedIds)
                );
                $onDuplicate = true;
            }
            $sql = $select->insertFromSelect($temporaryEntityTable, $columns, $onDuplicate);
            $this->_connection->query($sql);
        }
    }

    /**
     * Add primary key to table by it name
     *
     * @param string $tableName
     * @param string $columnName
     */
    protected function _addPrimaryKeyToTable($tableName, $columnName = 'entity_id')
    {
        $this->_connection->addIndex(
            $tableName,
            'entity_id',
            array($columnName),
            \Magento\DB\Adapter\AdapterInterface::INDEX_TYPE_PRIMARY
        );
    }

    /**
     * Fill temporary table by data from products EAV attributes by type
     *
     * @param string $tableName
     * @param array  $tableColumns
     * @param array  $changedIds
     * @param string $valueFieldSuffix
     * @param int $storeId
     */
    protected function _fillTemporaryTable(
        $tableName, array $tableColumns, array $changedIds, $valueFieldSuffix, $storeId
    ) {
        if (!empty($tableColumns)) {

            $columnsChunks = array_chunk(
                $tableColumns, \Magento\Catalog\Model\Indexer\Product\Flat\AbstractAction::ATTRIBUTES_CHUNK_SIZE, true
            );
            foreach ($columnsChunks as $columnsList) {
                $select                  = $this->_connection->select();
                $selectValue             = $this->_connection->select();
                $entityTableName         = $this->_getTemporaryTableName(
                    $this->_productIndexerHelper->getTable('catalog_product_entity')
                );
                $temporaryTableName      = $this->_getTemporaryTableName($tableName);
                $temporaryValueTableName = $temporaryTableName . $valueFieldSuffix;
                $keyColumn               = array('entity_id');
                $columns                 = array_merge($keyColumn, array_keys($columnsList));
                $valueColumns            = $keyColumn;
                $flatColumns             = $this->_productIndexerHelper->getFlatColumns();
                $iterationNum            = 1;

                $select->from(
                    array('e' => $entityTableName),
                    $keyColumn
                );

                $selectValue->from(
                    array('e' => $temporaryTableName),
                    $keyColumn
                );

                /** @var $attribute \Magento\Catalog\Model\Resource\Eav\Attribute */
                foreach ($columnsList as $columnName => $attribute) {
                    $countTableName = 't' . $iterationNum++;
                    $joinCondition  = sprintf(
                        'e.entity_id = %1$s.entity_id AND %1$s.attribute_id = %2$d AND %1$s.store_id = 0',
                        $countTableName,
                        $attribute->getId()
                    );

                    $select->joinLeft(
                        array($countTableName => $tableName),
                        $joinCondition,
                        array($columnName => 'value')
                    );

                    if ($attribute->getFlatUpdateSelect($storeId) instanceof \Magento\DB\Select) {
                        $attributeCode   = $attribute->getAttributeCode();
                        $columnValueName = $attributeCode . $valueFieldSuffix;
                        if (isset($flatColumns[$columnValueName])) {
                            $valueJoinCondition = sprintf(
                                'e.%1$s = %2$s.option_id AND %2$s.store_id = 0',
                                $attributeCode,
                                $countTableName
                            );
                            $selectValue->joinLeft(
                                array($countTableName => $this->_productIndexerHelper
                                        ->getTable('eav_attribute_option_value')
                                ),
                                $valueJoinCondition,
                                array($columnValueName => $countTableName . '.value')
                            );
                            $valueColumns[] = $columnValueName;
                        }
                    }
                }

                if (!empty($changedIds)) {
                    $select->where(
                        $this->_connection->quoteInto('e.entity_id IN (?)', $changedIds)
                    );
                }

                $sql = $select->insertFromSelect($temporaryTableName, $columns, true);
                $this->_connection->query($sql);

                if (count($valueColumns) > 1) {
                    if (!empty($changedIds)) {
                        $selectValue->where(
                            $this->_connection->quoteInto('e.entity_id IN (?)', $changedIds)
                        );
                    }
                    $sql = $selectValue->insertFromSelect($temporaryValueTableName, $valueColumns, true);
                    $this->_connection->query($sql);
                }
            }
        }
    }
}