<?php

namespace helena\services\common;

use minga\framework\ErrorException;

use helena\classes\DownloadStateBag;
use helena\db\frontend\DatasetModel;
use helena\db\frontend\ClippingRegionItemModel;
use helena\caches\DownloadCache;
use helena\caches\BackofficeDownloadCache;
use helena\classes\App;

class DownloadManager
{
	const STEP_BEGIN = 0;
	const STEP_ADDING_ROWS = 1;
	const STEP_CREATED = 2;
	const STEP_DATA_COMPLETE = 3;
	const STEP_CACHED = 4;

	const FILE_SPSS = 1;
	const FILE_CSV = 2;
	const FILE_SHP = 3;

	const OUTPUT_LATIN3_WINDOWS_ISO = false;

	private $start = 0.0;
	private $model;
	private $state;

	function __construct()
	{
			$this->start = microtime(true);
	}

	public function CreateMultiRequestFile($type, $datasetId, $clippingItemId, $fromDraft = false)
	{
		self::ValidateType($type);
		self::ValidateClippingItem($clippingItemId);

		// Si está cacheado, sale
		if(self::IsCached($type, $datasetId, $clippingItemId, $fromDraft))
			return array('done' => true);

		// Crea la estructura para la creación en varios pasos del archivo a descargar
		$this->PrepareNewModel($type, $datasetId, $clippingItemId, $fromDraft);
		$this->PrepareNewState($type, $datasetId, $clippingItemId, $fromDraft);
		return $this->GenerateNextFilePart();
	}

	public function StepMultiRequestFile($key)
	{
		// Carga los estados
		$this->LoadState($key);
		$this->LoadModel();
		// Avanza
		switch($this->state->Step())
		{
			case self::STEP_DATA_COMPLETE:
				return $this->PutFileToCache();
			case self::STEP_CACHED:
				return $this->state->ReturnState(true);
			default:
				return $this->GenerateNextFilePart();
		}
	}

	private function GenerateNextFilePart()
	{
		// Continúa creando el archivo
		$this->CreateNextFilePart();
		if($this->state->Step() == self::STEP_DATA_COMPLETE)
			return $this->PutFileToCache();
		else
			return $this->state->ReturnState(false);
	}

	private static function IsCached($type, $datasetId, $clippingItemId, $fromDraft)
	{
		$cacheKey = self::createKey($fromDraft, $type, $clippingItemId);
		$filename = null;
		return self::getCache($fromDraft)->HasData($datasetId, $cacheKey, $filename);
	}

	public static function GetFileBytes($type, $datasetId, $clippingItemId, $fromDraft = false)
	{
		self::ValidateType($type);

		if (!$fromDraft)
		{
			self::ValidateClippingItem($clippingItemId);
		}
		$cacheKey = self::createKey($fromDraft, $type, $clippingItemId);
		$friendlyName = self::GetFileName($datasetId, $clippingItemId, $type);
		$cache = self::getCache($fromDraft);
		// Lo devuelve desde el cache
		$filename = null;
		if ($cache->HasData($datasetId, $cacheKey, $filename, true))
			return App::StreamFile($filename, $friendlyName);
		else
			throw new ErrorException('File must be created before.');
	}

	private static function GetFileName($datasetId, $clippingItemId, $type)
	{
		if($type[0] == 's')
			$ext = 'sav';
		elseif($type[0] == 'z')
			$ext = 'zsav';
		elseif($type[0] == 'c')
			$ext = 'csv';
		elseif($type[0] == 'h')
			$ext = 'zip';
		else
			throw new ErrorException('Tipo de descarga inválido');

		$name = 'dataset' . $datasetId . $type;
		if($clippingItemId != 0)
			$name .= 'r'.$clippingItemId;

		return $name . '.' . $ext;
	}

	private static function createKey($fromDraft, $type, $clippingItemId)
	{
		if ($fromDraft)
			return BackofficeDownloadCache::CreateKey($type);
		else
			return DownloadCache::CreateKey($type, $clippingItemId);
	}

	private static function getCache($fromDraft)
	{
		if ($fromDraft)
			return BackofficeDownloadCache::Cache();
		else
			return DownloadCache::Cache();
	}

	private function PutFileToCache()
	{
		if($this->state->Step() == self::STEP_CACHED)
			return $this->state->ReturnState(false);

		if (!file_exists($this->state->Get('outFile')) || filesize($this->state->Get('outFile')) == 0)
			throw new ErrorException("No fue posible generar el archivo (" . $this->state->Get('cacheKey') . ").");

		$cache = self::getCache($this->state->FromDraft());
		$cache->PutData($this->state->Get('datasetId'), $this->state->Get('cacheKey'), $this->state->Get('outFile'));
		unlink($this->state->Get('outFile'));

		$this->state->SetStep(self::STEP_CACHED);
		return $this->state->ReturnState(true);
	}

	private static function ValidateType($type)
	{
		if($type != 's' && $type != 'sw' && $type != 'sg' && $type != 'h' && $type != 'hw' && $type != 'c' && $type != 'cw' && $type != 'cg' && $type != 'zw' && $type != 'zg')
			throw new ErrorException('Tipo de descarga inválido');
	}

	private static function ValidateClippingItem($clippingItemId)
	{
		if($clippingItemId != 0)
		{
			$model = new ClippingRegionItemModel();
			if(is_numeric($clippingItemId) == false || $model->Exists($clippingItemId) == false)
				throw new ErrorException('ClippingRegionItem no encontrada');
		}
	}

	private function LoadState($key)
	{
		$this->state = new DownloadStateBag();
		$this->state->LoadFromKey($key);
	}

	private function LoadModel()
	{
		$this->model = new DatasetModel($this->state->Get('fullQuery'), $this->state->Get('countQuery'),
								$this->state->Cols(), $this->state->Get('fullParams'), $this->state->Get('wktIndex'));
		$this->model->fromDraft = $this->state->FromDraft();
	}

	private function PrepareNewModel($type, $datasetId, $clippingItemId, $fromDraft)
	{
		$this->model = new DatasetModel();
		$this->model->fromDraft = $fromDraft;
		$this->model->PrepareFileQuery($datasetId, $clippingItemId, $this->GetPolygon($type));
	}

	private function PrepareNewState($type, $datasetId, $clippingItemId, $fromDraft)
	{
		$this->state = DownloadStateBag::Create($type, $datasetId, $clippingItemId, $this->model, $fromDraft);
		$this->state->SetStep(self::STEP_BEGIN);
		$this->state->SetTotalSteps(2);
		$friendlyName = self::GetFileName($datasetId, $clippingItemId, $type);
		$this->state->Set('friendlyName', $friendlyName);
		$this->state->Set('totalRows', $this->model->GetCountRows());
		$latLon = $this->model->GetLatLongColumns($datasetId);
		$this->state->Set('latVariable', $latLon['lat']);
		$this->state->Set('lonVariable', $latLon['lon']);
		$this->state->Save();
	}

	private function getFileType()
	{
		if ($this->state->Get('type')[0] == 's' || $this->state->Get('type')[0] == 'z')
			return self::FILE_SPSS;
		else if ($this->state->Get('type')[0] == 'h')
			return self::FILE_SHP;
		else if ($this->state->Get('type')[0] == 'c')
			return self::FILE_CSV;
		else
			throw new ErrorException('Tipo de descarga inválido');
	}

	private function getWriter($fileType)
	{
		if ($fileType === self::FILE_SPSS)
			return new SpssWriter($this->model, $this->state);
		else if ($fileType === self::FILE_CSV)
			return new CsvWriter($this->model, $this->state);
		else if ($fileType === self::FILE_SHP)
			return new ShpWriter($this->model, $this->state);
		else
			throw new ErrorException('Tipo de descarga inválido');
	}

	private function CreateNextFilePart()
	{
		$fileType = $this->getFileType();
		$writer = $this->getWriter($fileType);

		if($this->state->Step() == self::STEP_BEGIN)
		{
			$writer->SaveHeader();
			$this->state->SetStep(self::STEP_ADDING_ROWS, 'Anexando filas');
			$this->state->Save();
		}
		else if($this->state->Step() == self::STEP_ADDING_ROWS)
		{
			if (!$writer->PageData())
			{
				$this->state->SetStep(DownloadManager::STEP_CREATED, 'Consolidando archivo');
			}
			$this->state->Save();
		}
		else if($this->state->Step() == self::STEP_CREATED)
		{
			$writer->Flush();
			$this->state->SetStep(DownloadManager::STEP_DATA_COMPLETE, 'Descargando archivo');
			$this->state->Save();
		}
	}

	private function GetPolygon($type)
	{
		if (substr($type, 1, 1) === 'w')
			return 'wkt';
		else if (substr($type, 1, 1) === 'g')
			return 'geojson';
		else return null;
	}

}

