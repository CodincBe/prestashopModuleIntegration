<?php

namespace Codinc\ModuleUpgrade\Adapter;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\NamingStrategy;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Class ModuleDefinition.
 *
 * Supporting >=php5.3
 *
 * @package Codinc\ModuleUpgrade\Adapter
 */
class ObjectModelDefinition
{
    private $mainTable;
    private $languageTable;
    private $isMultilang;
    private $isMultilangShop;

    /**
     * @param NamingStrategy $namingStrategy
     * @param array $definition
     * @return ObjectModelDefinition
     * @throws DBALException
     */
    public static function fromDefinitionCollection(NamingStrategy $namingStrategy, array $definition)
    {
        if (!isset($definition['table'], $definition['primary'], $definition['fields']) || !is_array($definition['fields'])) {
            throw new InvalidArgumentException('The definition does not have all required fields');
        }
        $instance = new self();
        $instance->isMultilang = isset($definition['multilang']) ? $definition['multilang'] : false;
        $instance->isMultilangShop = isset($definition['multilang_shop']) ? $definition['multilang_shop'] : false;

        $tableName = $namingStrategy->classToTableName($definition['table']);
        $instance->mainTable = new Table($tableName);
        self::addPrimaryKeyColumn($instance->mainTable,  $definition['primary']);
        $instance->mainTable->setPrimaryKey(array($definition['primary']));

        if ($instance->isMultilang) {
            $className = $definition['table'] . '_lang';
            $languageTableName = $namingStrategy->classToTableName($className);
            $instance->languageTable = new Table($languageTableName);
            $primaries = array($definition['primary'], 'id_lang');
            if ($instance->isMultilangShop) {
                $primaries[] = 'id_shop';
            }
            foreach ($primaries as $primary) {
                $instance->languageTable->addColumn($primary, Type::INTEGER, array('unsigned' => true));
            }
            $instance->languageTable->addUniqueIndex($primaries);
        }

        // TODO: Find a way to retrieve foreign keys.. perhaps simple comparison based on name...
        // Walk the column definitions and add them to the table
        foreach ($definition['fields'] as $columnName => $columnDefinition) {
            // Detect which table the column belongs to
            if ($instance->languageTable && isset($columnDefinition['lang']) && $columnDefinition['lang']) {
                $table = $instance->languageTable;
            } else {
                $table = $instance->mainTable;
            }
            if ($table->hasColumn($columnName)) {
                continue;
            }
            $column = $table->addColumn($columnName, self::prestashopTypeToDoctrineType($columnDefinition['type']));
            $column->setNotnull(isset($columnDefinition['required']) ? $columnDefinition['required'] : false);

            if (isset($columnDefinition['size'])) {
                $column->setLength($columnDefinition['size']);
            }
            if (self::supportsUnsigned($column)) {
                if (isset($columnDefinition['validate']) && stripos($columnDefinition['validate'], 'unsigned') !== false) {
                    $column->setUnsigned(true);
                }
            }
        }

        return $instance;
    }

    /**
     * @param int $objectModelType
     * @return string
     * @throws DBALException
     * @throws OutOfBoundsException
     */
    private static function prestashopTypeToDoctrineType($objectModelType)
    {
        switch($objectModelType) {
            case \ObjectModel::TYPE_INT: return Type::INTEGER;
            case \ObjectModel::TYPE_BOOL: return Type::BOOLEAN;
            case \ObjectModel::TYPE_STRING: return Type::STRING;
            case \ObjectModel::TYPE_FLOAT: return Type::FLOAT;
            case \ObjectModel::TYPE_DATE: return Type::DATETIME;
            case \ObjectModel::TYPE_HTML:
            case \ObjectModel::TYPE_SQL:
                return Type::TEXT;
            default:
                throw new OutOfBoundsException("prestashop value type '$objectModelType' is not supported");
        }
    }

    /**
     * @param Table $table
     * @param $columnName
     * @return void
     * @throws DBALException
     */
    private static function addPrimaryKeyColumn(Table $table, $columnName)
    {
        $table->addColumn($columnName, Type::INTEGER, array('autoincrement' => true, 'unsigned' => true));
    }

    /**
     *
     * @param Column $column
     * @return boolean
     */
    private static function supportsUnsigned(Column $column)
    {
        return in_array($column->getType()->getName(), [Type::INTEGER, Type::FLOAT], true);
    }

    /**
     * @return Table
     */
    public function getMainTable()
    {
        return $this->mainTable;
    }

    /**
     * @return Table|null
     */
    public function getLanguageTable()
    {
        return $this->languageTable;
    }

    /**
     * @return bool
     */
    public function isMultilang()
    {
        return $this->isMultilang;
    }

    /**
     * @return bool
     */
    public function isMultilangShop()
    {
        return $this->isMultilangShop;
    }
}
