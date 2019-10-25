<?php

namespace helena\services\backoffice;

use minga\framework\Params;
use minga\framework\ErrorException;
use minga\framework\Profiling;

use helena\classes\App;
use helena\caches\DatasetColumnCache;
use helena\services\backoffice\cloning\DatasetClone;
use helena\entities\backoffice as entities;
use helena\services\backoffice\publish\PublishDataTables;
use helena\services\backoffice\publish\WorkFlags;
use helena\services\backoffice\import\DatasetTable;
use helena\caches\BackofficeDownloadCache;

class DatasetService extends DbSession
{
	public function Create($workId, $caption = '')
	{
		Profiling::BeginTimer();

		$dataset = new entities\DraftDataset();

		$dataset->setCaption($caption);
		$work = App::Orm()->find(entities\DraftWork::class, $workId);
		$dataset->setWork($work);
		$dataset->setType('L');
		$dataset->setExportable(true);
		$dataset->setGeoreferenceStatus(0);
		$dataset->setGeocoded(false);
		App::Orm()->Save($dataset);
		// Marca work
		DatasetService::DatasetChanged($dataset);
		Profiling::EndTimer();
		return $dataset;
	}
	public static function DatasetChangedById($datasetId, $onlyLabels = false)
	{
		$dataset = App::Orm()->find(entities\DraftDataset::class, $datasetId);
		self::DatasetChanged($dataset, $onlyLabels);
	}
	public static function DatasetChanged($dataset, $onlyLabels = false)
	{
		BackofficeDownloadCache::Clear($dataset->getId());
		if ($onlyLabels)
			WorkFlags::SetDatasetLabelsChanged($dataset->getWork()->getId());
		else
			WorkFlags::SetDatasetDataChanged($dataset->getWork()->getId());
	}
	public function GetDataset($datasetId)
	{
		Profiling::BeginTimer();
		$ret = App::Orm()->find(entities\DraftDataset::class, $datasetId);
		return $ret;
	}

	public function UpdateDataset($dataset)
	{
		Profiling::BeginTimer();
		$this->Save(entities\DraftDataset::class, $dataset);
		// Marca work
		DatasetService::DatasetChanged($dataset, true);
		Profiling::EndTimer();
		return self::OK;
	}

	public function OmmitDatasetRows($datasetId, $ids)
	{
		Profiling::BeginTimer();
		$dataset = $this->GetDataset($datasetId);
		$table = $dataset->getTable();
		// Lo graba
		$ommit = "UPDATE " . $table . " SET ommit = 1 WHERE Id IN (" . join(',', $ids) . ")";
		App::Db()->exec($ommit);
		$ret = array('completed' => true, 'affected' => App::Db()->lastRowsAffected());
		$this->DeleteFromErrors($table, $ids);
		Profiling::EndTimer();
		return $ret;
	}

	public function DeleteDatasetRows($datasetId, $ids)
	{
		Profiling::BeginTimer();
		$dataset = $this->GetDataset($datasetId);
		$table = $dataset->getTable();
		// Lo graba
		$delete = "DELETE FROM " . $table . " WHERE Id IN (" . join(',', $ids) . ")";
		App::Db()->exec($delete);
		$ret = array('completed' => true, 'affected' => App::Db()->lastRowsAffected());
		$this->DeleteFromErrors($table, $ids);
		DatasetColumnCache::Cache()->Clear($datasetId);
		// Marca work
		DatasetService::DatasetChanged($dataset, true);
		Profiling::EndTimer();
		return $ret;
	}
	private function DeleteFromErrors($table, $ids)
	{
		$tableErrors = $table . "_errors";
		if (App::Db()->tableExists($tableErrors) == false)
			return;

		$errors = "DELETE FROM " . $tableErrors . " WHERE row_id IN (" . join(',', $ids) . ")";
		App::Db()->exec($errors);
	}
	public function UpdateRowValues($datasetId, $id, $values)
	{
		Profiling::BeginTimer();
		$dataset = $this->GetDataset($datasetId);
		$table = $dataset->getTable();

		// Lo graba
		$fields = "modified = 1";
		$params = array();
		foreach($values as $fieldInfo)
		{
				$fields .= ", ";
				$field = $this->GetFieldFromColumn($datasetId, $fieldInfo->columnId);
				$fields .= $field . " = ? ";
				$params[] = $fieldInfo->value;
		}
		$update = "UPDATE " . $table . " SET " . $fields . " WHERE Id = ?";
		$params[] = $id;
		App::Db()->exec($update, $params);

		DatasetColumnCache::Cache()->Clear($datasetId);
		// Marca work
		DatasetService::DatasetChanged($dataset, true);
		Profiling::EndTimer();
		return self::OK;
	}

	public function UpdateMultilevelMatrix($dataset1Id, $matrix1, $dataset2Id, $matrix2)
	{
		$sql = "UPDATE draft_dataset SET dat_multilevel_matrix = ? WHERE dat_id = ?";
		App::Db()->exec($sql, array(($matrix1 == 0 ? null : $matrix1), $dataset1Id));
		App::Db()->exec($sql, array(($matrix2 == 0 ? null : $matrix2), $dataset2Id));
	}
	public function GetDatasetData($datasetId, $from, $rows)
	{
		return $this->GetDatasetRows($datasetId, $from, $rows);
	}

	public function GetDatasetErrors($datasetId, $from, $rows)
	{
		return $this->GetDatasetRows($datasetId, $from, $rows, true);
	}

	private function GetDatasetRows($datasetId, $from, $rows, $showErrors = false)
	{
		Profiling::BeginTimer();
		// Trae metadatos
		$dataset = $this->GetDataset($datasetId);
		$cols = new DatasetColumnService();
		$columns = $cols->GetDatasetColumns($datasetId);
		if ( $dataset->getTable() == "" || sizeof($columns) === 0)
		{
				return array(
				 'TotalRows' => 0,
				 'Data' => array());
		}
		$orderby = $this->resolveJqxGridOrderBy($datasetId);
		$filters = $this->resolveJqxGridFilters($datasetId);

		$ret = array();
		$cols = "";
		foreach($columns as $column)
			$cols .= ',' . $column->getField() . ' `' . $column->getVariable() . '`';
		$cols .= ',Id as internal__Id';

		// Si es la vista de errores desde georreferenciación, hace join con la tabla de errors
		$joinErrors = '';
		$whereErrors = '';
		$colsErrors = '';
		if ($showErrors) {
			$colsErrors = "GeoreferenceErrorWithCode(error_code) as internal__Err,";
			$joinErrors = " JOIN " . $dataset->getTable() . "_errors ON row_id = id ";
			$whereErrors = " AND ommit = 0 ";
		}

		// Trae los datos
		$query = "SELECT SQL_CALC_FOUND_ROWS " . $colsErrors . substr($cols, 1) . " FROM " . $dataset->getTable() . $joinErrors .
								" WHERE 1 " . $whereErrors . $filters . $orderby . " LIMIT " . $from . ", " . $rows;
		$data = App::Db()->fetchAllByPos($query);
		$sql = "SELECT FOUND_ROWS()";
		$rowcount = App::Db()->fetchScalarInt($sql);

		// Listo
		$ret = array(
       'TotalRows' => $rowcount,
		   'Data' => $data
		);
		Profiling::EndTimer();

		return $ret;
	}

	private function resolveJqxGridFilters($datasetId)
	{
		$where = "";
		$filterscount = intval(Params::GetInt('filterscount', 0));
		if ($filterscount === 0) return '';

		$where = "";
		$tmpdatafield = "";
		$tmpfilteroperator = "";
		for ($i=0; $i < $filterscount; $i++)
		{
			$filtervalue = Params::Get("filtervalue" . $i);
			$filtercondition = Params::Get("filtercondition" . $i);
			$filtervariable = Params::Get("filterdatafield" . $i);
			$filteroperator = Params::Get("filteroperator" . $i);
			if ($filtervariable === 'internal__Err')
			{
				$filterdatafield = 'GeoreferenceErrorWithCode(error_code)';
			}
			else
				$filterdatafield = $this->GetFieldFromVariable($datasetId, $filtervariable);

			if ($tmpdatafield === '')
			{
				$tmpdatafield = $filterdatafield;
				$where .= "(";
			}
			else if ($tmpdatafield !== $filterdatafield)
			{
				$where .= ") AND (";
			}
			else if ($tmpdatafield === $filterdatafield)
			{
				if ($tmpfilteroperator === "0")
				{
					$where .= " AND ";
				}
				else $where .= " OR ";
			}
			else $where .= "(";

			// build the "WHERE" clause depending on the filter's condition, value and datafield.
			switch($filtercondition)
			{
				case "CONTAINS":
					$where .= " " . $filterdatafield . " LIKE '%" . $filtervalue . "%'";
					break;
				case "CONTAINS_CASE_SENSITIVE":
					$where .= " " . $filterdatafield . " LIKE BINARY '%" . $filtervalue . "%'";
					break;
				case "DOES_NOT_CONTAIN":
					$where .= " " . $filterdatafield . " NOT LIKE '%" . $filtervalue . "%'";
					break;
				case "DOES_NOT_CONTAIN_CASE_SENSITIVE":
					$where .= " " . $filterdatafield . " NOT LIKE BINARY '%" . $filtervalue . "%'";
					break;
				case "EQUAL":
					$where .= " " . $filterdatafield . " = '" . $filtervalue . "'";
					break;
				case "EQUAL_CASE_SENSITIVE":
					$where .= " " . $filterdatafield . " LIKE BINARY '" . $filtervalue . "'";
					break;
				case "NOT_EQUAL":
					$where .= " " . $filterdatafield . " NOT LIKE '" . $filtervalue . "'";
					break;
				case "NOT_EQUAL_CASE_SENSITIVE":
					$where .= " " . $filterdatafield . " NOT LIKE BINARY '" . $filtervalue . "'";
					break;
				case "GREATER_THAN":
					$where .= " " . $filterdatafield . " > '" . $filtervalue . "'";
					break;
				case "LESS_THAN":
					$where .= " " . $filterdatafield . " < '" . $filtervalue . "'";
					break;
				case "GREATER_THAN_OR_EQUAL":
					$where .= " " . $filterdatafield . " >= '" . $filtervalue . "'";
					break;
				case "LESS_THAN_OR_EQUAL":
					$where .= " " . $filterdatafield . " <= '" . $filtervalue . "'";
					break;
				case "STARTS_WITH":
					$where .= " " . $filterdatafield . " LIKE '" . $filtervalue . "%'";
					break;
				case "STARTS_WITH_CASE_SENSITIVE":
					$where .= " " . $filterdatafield . " LIKE BINARY '" . $filtervalue . "%'";
					break;
				case "ENDS_WITH":
					$where .= " " . $filterdatafield . " LIKE '%" . $filtervalue . "'";
					break;
				case "ENDS_WITH_CASE_SENSITIVE":
					$where .= " " . $filterdatafield . " LIKE BINARY '%" . $filtervalue . "'";
					break;
				case "NULL":
					$where .= " " . $filterdatafield . " IS NULL";
					break;
				case "NOT_NULL":
					$where .= " " . $filterdatafield . " IS NOT NULL";
					break;
			}
			if ($i === $filterscount - 1)
			{
				$where .= ")";
			}
			$tmpfilteroperator = $filteroperator;
			$tmpdatafield = $filterdatafield;
		}

		if ($where === "()")
			$where = "";
		else if ($where !== "")
			$where = "AND (" . $where . ")";

		return $where;
	}

	private function resolveJqxGridOrderBy($datasetId)
	{
		$orderby = "";
		$sortvariable = Params::Get("sortdatafield");
		if ($sortvariable === null) return '';
		if ($sortvariable === 'internal__Err')
		{
			$sortdatafield = 'error_code';
		} else {
			$sortdatafield = $this->GetFieldFromVariable($datasetId, $sortvariable);
		}
		$sortorder = Params::Get("sortorder", 'asc');
		if ($sortorder !== "asc" && $sortorder !== "desc")
			$sortorder = 'asc';
		return " ORDER BY " . $sortdatafield . " " . $sortorder;
	}

	private function GetFieldFromVariable($datasetId, $variable)
	{
		if ($variable === 'internal__Id')
			return 'Id';
		Profiling::BeginTimer();
		// Obtiene el campo para la variable
		$params = array($datasetId, $variable);
		$sql = "SELECT dco_field FROM draft_dataset_column where dco_dataset_id = ? and dco_variable = ? LIMIT 1";
		$ret = App::Db()->fetchScalar($sql, $params);
		Profiling::EndTimer();
		return $ret;
	}

	private function GetFieldFromColumn($datasetId, $columnId)
	{
		Profiling::BeginTimer();
		// Obtiene el campo para la variable
		$params = array($datasetId, $columnId);
		$sql = "SELECT dco_field FROM draft_dataset_column where dco_dataset_id = ? and dco_id = ? LIMIT 1";
		$ret = App::Db()->fetchScalar($sql, $params);
		Profiling::EndTimer();
		return $ret;
	}

	public function CloneDataset($workId, $newName, $datasetId)
	{
		Profiling::BeginTimer();
		// Marca work
		WorkFlags::SetDatasetDataChanged($workId);

		$cloner = new DatasetClone($workId, $newName, $datasetId);
		$ret = $cloner->CloneDataset();
		Profiling::EndTimer();
		return $ret;
	}
	public function DeleteDataset($workId, $datasetId)
	{
		Profiling::BeginTimer();
		// Marca work
		$dataset = App::Orm()->find(entities\DraftDataset::class, $datasetId);
		if ($dataset === null)
			throw new ErrorException("Dataset no encontrado.");
		DatasetService::DatasetChanged($dataset);

		$multilevelMatrix = $dataset->getMultilevelMatrix();
		$matrixCount = $this->resolveMatrixMembersCount($workId, $dataset);

		$columnsReferences = new PublishDataTables();
		$columnsReferences->UnlockColumns($workId, true, $datasetId);
		// Borra indicadores
		$this->DeleteMetricVersionLevels($datasetId);
		// Borra valueLabels
		$deleteLabels = "DELETE FROM draft_dataset_label WHERE dla_dataset_column_id IN (SELECT dco_id FROM draft_dataset_column WHERE dco_dataset_id = ?)";
		App::Db()->exec($deleteLabels, array($datasetId));
		// Borra columnas
		$deleteCols = "DELETE FROM draft_dataset_column WHERE dco_dataset_id = ?";
		App::Db()->exec($deleteCols, array($datasetId));
		// Borra dataset
		App::Orm()->delete($dataset);
		// Borra tablas
		$tableName = $dataset->getTable();
		App::Db()->dropTable($tableName);
		App::Db()->dropTable($tableName . '_errors');
		App::Db()->dropTable($tableName . '_retry');
		$unregister = new DatasetTable();
		$unregister->UnregisterTable($tableName);
		// Libera al par de la multilevelMatrix si lo formaban dos miembros
		if ($matrixCount === 2)
		{
				$query = "UPDATE draft_dataset SET dat_multilevel_matrix = NULL WHERE dat_work_id = ? AND dat_multilevel_matrix = ?";
				App::Db()->exec($query, array($workId, $multilevelMatrix));
		}
		DatasetColumnCache::Cache()->Clear($datasetId);
		Profiling::EndTimer();
	}

	private function DeleteMetricVersionLevels($datasetId)
	{
		Profiling::BeginTimer();
		$metricVersionLevels = App::Orm()->findManyByQuery("SELECT v FROM e:DraftMetricVersionLevel v JOIN v.Dataset d WHERE d.Id = :p1", array($datasetId));
		foreach($metricVersionLevels as $metricVersionLevel)
			$this->DeleteMetricVersionLevel($metricVersionLevel);
		Profiling::EndTimer();
	}

	private function resolveMatrixMembersCount($workId, $dataset)
	{
		$multilevelMatrix = $dataset->getMultilevelMatrix();
		if ($multilevelMatrix === null)
			return 0;

		$query = "SELECT COUNT(*) FROM draft_dataset WHERE dat_work_id = ? AND dat_multilevel_matrix = ?";
		return App::Db()->fetchScalarInt($query, array($workId, $multilevelMatrix));
	}

	private function DeleteMetricVersionLevel($metricVersionLevel)
	{
		Profiling::BeginTimer();
		$metricVersion = $metricVersionLevel->getMetricVersion();
		$metric = $metricVersion->getMetric();
		// Borra las variables
		$this->DeleteVariables($metricVersionLevel);

		// Borra el metric version level
		App::Orm()->delete($metricVersionLevel);

		// Borra los metric sin verisones
		$this->DeleteOrphanMetricVersion($metricVersion);

		// Borra los metric sin verisones
		$this->DeleteOrphanMetric($metric);
		Profiling::EndTimer();
	}

	private function DeleteVariables($metricVersionLevel)
	{
		Profiling::BeginTimer();
		$metricVersionLevelId = $metricVersionLevel->getId();
		// Borra las variables
		$variables = "draft_variable WHERE mvv_metric_version_level_id = ?";
		$deleteVariableValueLabel = "DELETE FROM draft_variable_value_label WHERE vvl_variable_id IN (SELECT mvv_id FROM " . $variables . ")";
    App::Db()->exec($deleteVariableValueLabel, array($metricVersionLevelId));
    $deleteMetricVersionVariable = "DELETE FROM " . $variables;
    App::Db()->exec($deleteMetricVersionVariable, array($metricVersionLevelId));
		Profiling::EndTimer();
	}
	private function DeleteOrphanMetricVersion($metricVersion)
	{
		Profiling::BeginTimer();
		// Se fija si era el último
		$childrenSql = "SELECT count(*) FROM draft_metric_version_level WHERE mvl_metric_version_id = ?";
		$sibilings = App::Db()->fetchScalarInt($childrenSql, array($metricVersion->getId()));
		if ($sibilings == 0)
		{
			App::Orm()->delete($metricVersion);
		}
		Profiling::EndTimer();
	}
	private function DeleteOrphanMetric($metric)
	{
		Profiling::BeginTimer();
		// Se fija si era el último
		$childrenSql = "SELECT count(*) FROM draft_metric_version WHERE mvr_metric_id = ?";
		$sibilings = App::Db()->fetchScalarInt($childrenSql, array($metric->getId()));
		if ($sibilings == 0)
		{
			App::Orm()->delete($metric);
		}
		Profiling::EndTimer();
	}
}