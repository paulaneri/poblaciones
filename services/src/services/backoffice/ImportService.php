<?php

namespace helena\services\backoffice;

use minga\framework\IO;
use minga\framework\Log;
use minga\framework\Str;
use minga\framework\FileBucket;
use minga\framework\ErrorException;
use minga\framework\System;

use PhpOffice\PhpSpreadsheet\Writer\Csv;
use helena\services\backoffice\import\PhpSpreadSheetCsv;

use helena\services\common\BaseService;
use helena\classes\Paths;
use helena\classes\App;
use helena\classes\CsvToJson;

use helena\entities\backoffice\DraftDataset;

use helena\services\backoffice\import\MetadataMerger;
use helena\services\backoffice\import\ImportStateBag;
use helena\services\backoffice\import\DatasetTable;
use helena\services\backoffice\import\DatasetColumns;
use helena\services\backoffice\publish\WorkFlags;
use helena\entities\backoffice as entities;


class ImportService extends BaseService
{
	const STEP_BEGIN = 0;
	const STEP_CONVERTED = 1;
	const STEP_INSERTING = 2;
	const STEP_INSERTED = 3;
	const STEP_END = 4;

	private $state;

	public function CreateMultiImportFile($datasetId, $bucketId, $fileExtension, $keepLabels){
		$dataset = App::Orm()->find(entities\DraftDataset::class, $datasetId);
		WorkFlags::SetDatasetDataChanged($dataset->getWork()->getId());

		$bucket = FileBucket::Load($bucketId);
		$this->PrepareNewState($datasetId, $keepLabels, $bucketId);
		$this->state->SetTotalSteps(self::STEP_END);
		$fileExtension = Str::ToLower($fileExtension);
		if ($fileExtension == "csv" || $fileExtension == "txt"
				|| $fileExtension == "xlsx"  || $fileExtension == "xls")
		{
			if ($fileExtension == "xlsx"  || $fileExtension == "xls")
			{
				$this->ExcelToCsv($fileExtension, $bucket);
			}
			return $this->CSVtoJson($bucket);
		}
		else if ($fileExtension == "sav")
		{
			return $this->SPSStoJson($bucket);
		}

		throw new ErrorException('La extensión del archivo debe ser .SAV o .CSV. Extensión recibida: ' . $fileExtension);
	}

	public function FileChunkImport($bucketId) {
		$bucket = FileBucket::Load($bucketId);
		return $this->SaveTo($bucket);
	}

	private function SaveTo($bucket)
	{
		$uploadFolder = $bucket->path;
		$extension = '';
		if (!empty($_FILES))
		{
			$tempFileName = $_FILES['file']['tmp_name'];
			$actual = file_get_contents($tempFileName);
			$extension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
			$destFile =  $uploadFolder . '/file.dat';
			file_put_contents($destFile, $actual, FILE_APPEND | LOCK_EX);
		}

		return array('status' => 'OK', 'bucket' => $bucket->id, 'extension' => $extension);
	}

	public function SingleStepFileImport()
	{
		$bucket = FileBucket::Create();
		return $this->SaveTo($bucket);
	}

	public function StepMultiImportFile($key)
	{
		// Carga los estados
		$this->LoadState($key);
		// Avanza
		switch($this->state->Step())
		{
			case self::STEP_CONVERTED:
				return $this->CreateTables();
			case self::STEP_INSERTING:
				return $this->InsertData();
			case self::STEP_INSERTED:
				{
					$this->CreateMetadata();
					$this->MergeOldData();
					return $this->UpdateMetadata();
				}
			default:
				throw new ErrorException('Invalid step.');
		}
	}

	private function LoadState($key)
	{
		$this->state = new ImportStateBag();
		$this->state->LoadFromKey($key);
	}

	private function PrepareNewState($datasetId, $keepLabels, $defaultBucketId)
	{
		$this->state = ImportStateBag::Create($datasetId, $defaultBucketId);
		$this->state->Set("datasetId", $datasetId);
		$this->state->Set("keepLabels", $keepLabels);
		$this->state->Save();
	}

	private function UpdateMetadata()
	{
		$tableName = $this->state->Get("tableName");
		$datasetId = $this->state->GetDatasetId();
		// Lo saca de temp
		$datasetTable = new DatasetTable();
		$datasetTable->PromoteFromTemp($tableName);
		$tableName = DatasetTable::GetNonTemporaryName($tableName);
		// Actualiza el nombre de tabla
		$dataset = App::Orm()->find(DraftDataset::class, $datasetId);
		$dataset->setTable($tableName);
		$dataset->setGeocoded(false);
		// Termina
		$this->state->SetStep(self::STEP_END, 'Completado exitosamente');
		return $this->state->ReturnState(true);
	}

	private function MergeOldData(){
		$datasetId = $this->state->GetDatasetId();
		$targetDatasetId = $datasetId;
		$keepOldMetadata = $this->state->GetKeepLabels();
		$dropSourceDataset = true;
		$maxPreviousId = $this->state->Get('maxPreviousId');

		$merger = new MetadataMerger($datasetId, $targetDatasetId, $keepOldMetadata,
																	$maxPreviousId, $dropSourceDataset);
		$merger->MergeMetadata();
	}

	private function CreateMetadata()
	{
		// Guarda max id previo
		$maxPreviousId = App::Db()->fetchScalarInt("SELECT max(dco_id) FROM draft_dataset_column");
		$this->state->Set('maxPreviousId', $maxPreviousId);
		// Inserta columnas
		$datasetId = $this->state->GetDatasetId();
		$headers = $this->state->GetHeaders();
		$datasetColumns = new DatasetColumns($headers);
		$datasetColumns->InsertColumnDescriptions($datasetId);
	}

	private function ExcelToCsv($fileExtension, $bucket)
	{
		$uploadFolder = $bucket->path;
		$sourceFile =  $uploadFolder . '/file.dat';
		$xlsFile =  $uploadFolder . '/file_xls.dat';
		if (file_exists($xlsFile))
			// es un reintento
			return;

		IO::Move($sourceFile, $xlsFile);

		$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsFile);
		$loadedSheetNames = $spreadsheet->getSheetNames();
		$writer = new PhpSpreadSheetCsv($spreadsheet);

		foreach($loadedSheetNames as $sheetIndex => $loadedSheetName) {
				$writer->setSheetIndex($sheetIndex);
				$writer->save($sourceFile);
				break;
		}
	}

	private function CSVtoJson($bucket)
	{
		$folder = $this->state->GetFileFolder();
		$uploadFolder = $bucket->path;
		$sourceFile =  $uploadFolder . '/file.dat';
		CsvToJson::Convert($sourceFile, $folder);
		$this->state->SetStep(self::STEP_CONVERTED, 'Creando tablas');
		return $this->state->ReturnState(false);
	}

	private function SPSStoJson($bucket)
	{
		$folder = $this->state->GetFileFolder();
		$uploadFolder = $bucket->path;
		$sourceFile =  $uploadFolder . '/file.dat';

		$python = App::GetPython3Path();
		$p3 = '3';
		if($python == null)
		{
			$python = App::GetPythonPath();
			$p3 = '';
		}

		if (IO::Exists($python) === false)
			throw new ErrorException('El ejecutable de python no fue encontrado en ' . $python);

		$lines = array();

		$ret = System::Execute($python, array(
			Paths::GetPythonScriptsPath() . '/spss2json' . $p3 . '.py',
			$sourceFile,
			$folder
		), $lines);

		if($ret !== 0)
		{
			$err = '';
			$detail = "\nScript: " . Paths::GetPythonScriptsPath() . '/spss2json' . $p3 . '.py'
				. "\nSource: " . $sourceFile
				. "\nFolder: " . $folder
				. "\nScript Output was: \n----------------------\n" . implode("\n", $lines) . "\n----------------------\n";
			if(App::Debug())
				$err = $detail;
			else
				Log::HandleSilentException(new ErrorException($detail));

			throw new ErrorException('Error en la subida de archivo spss.' . $err);
		}

		$this->state->SetStep(self::STEP_CONVERTED, 'Creando tablas');
		return $this->state->ReturnState(false);
	}

	private function CreateTables()
	{
		$datasetTable = new DatasetTable();
		$headers = $this->state->GetHeaders();
		$tableName = $datasetTable->CreateTable($headers);
		$this->state->Set('tableName', $tableName);
		//$this->state->SetStep(self::STEP_METADATA, 'Creando variables');
		$this->state->SetStep(self::STEP_INSERTING, 'Insertando datos');
		return $this->state->ReturnState(false);
	}

	private function InsertData()
	{
		// Comienza a insertar
		$headers = $this->state->GetHeaders();
		$tableName = $this->state->Get("tableName");
		$datasetTable = new DatasetTable();

		$files = IO::GetFilesStartsWith($this->state->GetFileFolder(), "data_");
		$this->state->SetTotalSlices(sizeof($files));

		$file = $files[$this->state->Slice()];
		$filePath = $this->state->GetFileFolder() . "/" . $file;
		$datasetTable->InsertDatafile($tableName, $headers, $filePath);

		$this->state->NextSlice();
		if ($this->state->Slice() == $this->state->GetTotalSlices())
		{
			$this->state->SetStep(self::STEP_INSERTED, 'Actualizando dataset');
		}
		return $this->state->ReturnState(false);
	}

}

