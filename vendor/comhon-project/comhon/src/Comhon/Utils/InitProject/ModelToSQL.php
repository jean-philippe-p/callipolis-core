<?php

/*
 * This file is part of the Comhon package.
 *
 * (c) Jean-Philippe <jeanphilippe.perrotton@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Comhon\Utils\InitProject;

use Comhon\Object\Object;
use Comhon\Object\Config\Config;
use Comhon\Model\Singleton\ModelManager;
use Comhon\Exception\SerializationManifestIdException;
use Comhon\Serialization\SqlTable;
use Comhon\Model\Property\MultipleForeignProperty;
use Comhon\Model\Property\Property;
use Comhon\Model\Property\ForeignProperty;

class ModelToSQL {
	
	/**
	 *
	 * @param string $output
	 * @param string $config
	 * @return string
	 */
	private function initialize($output, $config) {
		Config::setLoadPath($config);
		
		if (file_exists($output)) {
			exec("rm -r $output");
		}
		mkdir($output);
		
		$table_ad = $output. '/table';
		mkdir($table_ad);
		
		return $table_ad;
	}
	
	/**
	 *
	 * @param Property $property
	 * @return string[]
	 */
	private function getColumnDescription(Property $property) {
		return [
				'name' => $property->getSerializationName(),
				'type' => $this->getColumnType($property)
		];
	}
	
	private function getForeignColumnsDescriptions($table, $property, array &$foreignConstraints) {
		$foreignTable = $property->getModel()->getSerializationSettings()->getValue('name');
		$foreignConstraint = [
				'local_table' => $table,
				'foreign_table' => $foreignTable,
				'local_columns' => [],
				'foreign_columns' => []
		];
		$foreignConstraints[] =& $foreignConstraint;
		
		return ($property instanceof MultipleForeignProperty)
		? $this->getMultipleForeignColumnDescription($property, $foreignConstraint)
		: $this->getUniqueForeignColumnDescription($property, $foreignConstraint);
	}
	
	/**
	 *
	 * @param MultipleForeignProperty $property
	 * @param array $foreignConstraint
	 * @return string[][]
	 */
	private function getMultipleForeignColumnDescription(MultipleForeignProperty $property, array &$foreignConstraint) {
		$columns = [];
		$foreignTable = $property->getModel()->getSerializationSettings()->getValue('name');
		
		foreach ($property->getMultipleIdProperties()as $column => $foreignIdProperty) {
			
			$columns[] = [
					'name' => $column,
					'type' => $this->getColumnType($foreignIdProperty),
			];
			$foreignConstraint['local_columns'][] = $column;
			$foreignConstraint['foreign_columns'][] = $foreignIdProperty->getSerializationName();
			
			$columnProperties[] = $tempProperty;
		}
		return $columns;
	}
	
	/**
	 *
	 * @param ForeignProperty $property
	 * @param array $foreignConstraint
	 * @return string[][]
	 */
	private function getUniqueForeignColumnDescription(ForeignProperty $property, array &$foreignConstraint) {
		$foreignTable = $property->getModel()->getSerializationSettings()->getValue('name');
		$idProperties = $property->getModel()->getIdProperties();
		$foreignIdProperty = current($idProperties);
		
		$foreignConstraint['local_columns'][] = $property->getSerializationName();
		$foreignConstraint['foreign_columns'][] = $foreignIdProperty->getSerializationName();
		
		return [
				[
						'name' => $property->getSerializationName(),
						'type' => $this->getColumnType($foreignIdProperty),
						
				]
		];
	}
	
	/**
	 *
	 * @param Property $property
	 * @throws \Exception
	 * @return string
	 */
	private function getColumnType(Property $property) {
		switch ($property->getModel()->getName()) {
			case 'string':
				$type = 'text';
				break;
			case 'integer':
				$type = 'int';
				break;
			default:
				throw new \Exception('type not handled : ' . $columnProperty->getModel()->getName());
				break;
		}
		return $type;
	}
	
	/**
	 * get model only if model has sql serialization or doesn't have serialisation
	 *
	 * @param string $modelName
	 * @param string $table_ad
	 * @param string $sqlDatabase
	 * @return \Comhon\Model\Model|null
	 *     return null if model has serialization different than sql serialisation
	 */
	private function getModel($modelName, $table_ad, $sqlDatabase) {
		$model = null;
		try {
			$model = ModelManager::getInstance()->getInstanceModel($modelName);
		} catch(SerializationManifestIdException $e) {
			if ($e->getType() == 'sqlTable') {
				$settings = ModelManager::getInstance()->getInstanceModel('sqlTable')->getSerializationSettings();
				$origin_table_ad = $settings->getValue('saticPath');
				$settings->setValue('saticPath', $table_ad);
				
				$sqlTable = ModelManager::getInstance()->getInstanceModel('sqlTable')->getObjectInstance();
				$sqlTable->setId($e->getId());
				$sqlTable->setValue('database', $sqlDatabase);
				$sqlTable->save();
				
				$model = ModelManager::getInstance()->getInstanceModel($modelName);
				$settings->setValue('saticPath', $origin_table_ad);
			}
		}
		if (!is_null($model) && $model->hasSerialization() && !($model->getSerialization() instanceof SqlTable)) {
			$model = null;
		}
		return $model;
	}
	
	/**
	 * permit to define a table to serialize model
	 *
	 * @param string $modelName
	 * @return string|null
	 */
	private function defineTable($modelName) {
		$instruction = "Model $modelName doesn't have serialization." . PHP_EOL
		."Would you like to save it in sql database ? [y/n]" . PHP_EOL;
		do {
			echo $instruction;
			$response = trim(fgets(STDIN));
			$instruction = "Invalid response. Again, would you like to save it in sql database ? [y/n]" . PHP_EOL;
		} while ($response !== 'y' && $response !== 'n');
		
		if ($response == 'y') {
			echo "Enter a table name (default name : $modelName) : " . PHP_EOL;
			$response = trim(fgets(STDIN));
			$table = empty($response) ? $modelName : $response;
		}
		else {
			$table = null;
		}
		return $table;
	}
	
	/**
	 *
	 * @param string $table
	 * @param string[] $tableColumns
	 * @param string[] $primaryKey
	 * @return string
	 */
	private function getTableDefinition($table, $tableColumns, $primaryKey = null) {
		$tableDescription = [];
		
		foreach ($tableColumns as $column) {
			$tableDescription[] = "    {$column['name']} {$column['type']}";
		}
		
		if (!empty($primaryKey)) {
			$tableDescription[] = '    PRIMARY KEY (' . implode(', ', $primaryKey) . ')';
		}
		
		return "\nCREATE TABLE $table (" . PHP_EOL . "    "
				. implode("," . PHP_EOL . "    ", $tableDescription) . PHP_EOL
				.');' . PHP_EOL;
	}
	
	/**
	 *
	 * @param array $foreignConstraint
	 * @return string
	 */
	private function getForeignConstraint($foreignConstraint) {
		return sprintf(
				PHP_EOL . "ALTER TABLE %s" . PHP_EOL
				."ADD CONSTRAINT fk_%s_%s" . PHP_EOL
				."FOREIGN KEY (%s) REFERENCES %s(%s);" . PHP_EOL,
				$foreignConstraint['local_table'],
				$foreignConstraint['local_table'],
				$foreignConstraint['foreign_table'],
				implode(', ', $foreignConstraint['local_columns']),
				$foreignConstraint['foreign_table'],
				implode(', ', $foreignConstraint['foreign_columns'])
				);
	}
	
	
	private function exec_transformation($output, $config) {
		$table_ad = $this->initialize($output, $config);
		
		$sqlDatabase = ModelManager::getInstance()->getInstanceModel('sqlDatabase')->getObjectInstance();
		$sqlDatabase->setId('1');
		
		$databaseQuery = '';
		$sqlTables = [];
		$foreignConstraints = [];
		
		$manifest_ad = realpath(dirname(Config::getInstance()->getManifestListPath()));
		$objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($manifest_ad), \RecursiveIteratorIterator::SELF_FIRST);
		
		/**
		 * @var SplFileInfo $object
		 */
		foreach($objects as $name => $object) {
			if (!is_dir($name) || $object->getBasename() === '.' || $object->getBasename() === '..') {
				continue;
			}
			$modelName = substr(str_replace('/', '\\', str_replace($manifest_ad, '', $name)), 1);
			$model = $this->getModel($modelName, $table_ad, $sqlDatabase);
			
			if (!is_null($model)) {
				$table = $model->hasSerialization()
				? $model->getSerializationSettings()->getValue('name')
				: $this->defineTable($modelName);
				
				if (!is_null($table)) {
					$sqlTables[] = [$table, $model];
				}
			}
		}
		
		// now models are fully loaded so we can process them
		foreach($sqlTables as $array) {
			$table = $array[0];
			$model = $array[1];
			$primaryKey = [];
			$tableColumns = [];
			
			foreach ($model->getProperties() as $property) {
				if (!$property->isSerializable() || $property->isAggregation()) {
					continue;
				}
				$propertyColumns = $property->isForeign()
				? $this->getForeignColumnsDescriptions($table, $property, $foreignConstraints)
				: [$this->getColumnDescription($property)];
				
				foreach ($propertyColumns as $column) {
					$tableColumns[] = $column;
				}
				if ($property->isId()) {
					$primaryKey[] = $property->getSerializationName();
				}
			}
			$databaseQuery.= $this->getTableDefinition($table, $tableColumns, $primaryKey);
		}
		
		
		foreach ($foreignConstraints as $foreignConstraint) {
			$databaseQuery .= $this->getForeignConstraint($foreignConstraint);
		}
		
		file_put_contents($output.'/database.sql', $databaseQuery);
	}
	
	public static function exec($output, $config) {
		$modelToSQL = new self();
		$modelToSQL->exec_transformation($output, $config);
	}
    
}
