<?php

namespace helena\services\backoffice\publish;

use helena\caches\DownloadCache;
use helena\caches\DatasetShapesCache;
use helena\caches\FabMetricsCache;
use helena\caches\SelectedMetricsMetadataCache;
use helena\caches\MetricGroupsMetadataCache;
use helena\caches\DatasetColumnCache;
use helena\caches\WorkHandlesCache;
use helena\caches\ClippingCache;
use helena\caches\RankingCache;
use helena\caches\BackofficeDownloadCache;
use helena\caches\PdfMetadataCache;
use helena\caches\WorkVisiblityCache;

use helena\caches\SummaryCache;
use helena\caches\TileDataCache;
use helena\caches\GeographyCache;
use helena\caches\LabelsCache;

class CacheManager
{
	// Dataset
	public function ClearDataset($datasetId)
	{
		$this->ClearDatasetMetaData($datasetId);
		$this->ClearDatasetData($datasetId);
	}

	public function ClearDatasetData($datasetId)
	{
		$datasetIdShardified = PublishDataTables::Shardified($datasetId);
		DatasetShapesCache::Cache()->Clear($datasetIdShardified);
	}

	public function ClearDatasetMetaData($datasetId)
	{
		$datasetIdShardified = PublishDataTables::Shardified($datasetId);
		DownloadCache::Cache()->Clear($datasetIdShardified);
	}

	public function CleanPdfMetadata($metadataId)
	{
		$metadataIdShardified = PublishDataTables::Shardified($metadataId);
		PdfMetadataCache::Cache()->Clear($metadataIdShardified);
	}

	public function CleanFabMetricsCache()
	{
		FabMetricsCache::Cache()->Clear();
	}

	public function CleanWorkHandlesCache($workId)
	{
		WorkHandlesCache::Cache()->Clear($workId);
	}
	public function CleanWorkVisiblityCache($workId)
	{
		$workIdShardified = PublishDataTables::Shardified($workId);
		WorkVisiblityCache::Cache()->Clear($workIdShardified);
	}
	public function CleanGeographyCache()
	{
		GeographyCache::Cache()->Clear();
	}
	public function CleanClippingCache()
	{
		ClippingCache::Cache()->Clear();
		WorkHandlesCache::Cache()->Clear();
	}
	public function CleanLabelsCache()
	{
		LabelsCache::Cache()->Clear();
	}

	public function CleanAllMetricCaches()
	{
		SummaryCache::Cache()->Clear();
		TileDataCache::Cache()->Clear();
		RankingCache::Cache()->Clear();
		DatasetColumnCache::Cache()->Clear();
		BackofficeDownloadCache::Cache()->Clear();
		DatasetShapesCache::Cache()->Clear();
		DownloadCache::Cache()->Clear();
		FabMetricsCache::Cache()->Clear();
		SelectedMetricsMetadataCache::Cache()->Clear();
	}
	public function CleanMetricGroupsMetadataCache()
	{
		MetricGroupsMetadataCache::Cache()->Clear();
	}

	// Metric
	public function ClearSelectedMetricMetadata($metricId)
	{
		$metricIdShardified = PublishDataTables::Shardified($metricId);
		SelectedMetricsMetadataCache::Cache()->Clear($metricIdShardified);
	}
	public function ClearMetricMetadata($metricId)
	{
		$metricIdShardified = PublishDataTables::Shardified($metricId);
		SummaryCache::Cache()->Clear($metricIdShardified);
		TileDataCache::Cache()->Clear($metricIdShardified);
		RankingCache::Cache()->Clear($metricIdShardified);
		SelectedMetricsMetadataCache::Cache()->Clear($metricIdShardified);
	}
}