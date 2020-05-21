<?php

use Symfony\Component\HttpFoundation\Request;

use helena\classes\App;
use minga\framework\PhpSession;
use helena\classes\Session;
use helena\services\backoffice as services;
use minga\framework\Params;
use helena\entities\backoffice as entities;
use minga\framework\Profiling;


// ******* Metric *********************************

App::$app->get('/services/backoffice/GetDatasetMetricVersionLevels', function (Request $request) {
	$controller = new services\MetricService();
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetReader($datasetId)) return $denied;
	$ret = App::OrmJson($controller->GetDatasetMetricVersionLevels($datasetId));
	return $ret;
});

App::$app->get('/services/backoffice/GetMetricVersionLevelVariables', function (Request $request) {
	$controller = new services\MetricService();
	$workId = Params::GetIntMandatory('w');
	$metricVersionLevelId = Params::GetIntMandatory('l');
	if ($denied = Session::CheckIsWorkReader($workId)) return $denied;
	$ret = App::OrmJson($controller->GetMetricVersionLevelVariables($workId, $metricVersionLevelId));
	return $ret;
});


App::$app->get('/services/backoffice/GetWorkMetricVersions', function (Request $request) {
	$controller = new services\MetricService();
	$workId = Params::GetIntMandatory('w');
	if ($denied = Session::CheckIsWorkReader($workId)) return $denied;
	$ret = App::Json($controller->GetWorkMetricVersions($workId));
	return $ret;
});

App::$app->get('/services/backoffice/GetAllMetricGroups', function (Request $request) {
	$controller = new services\MetricService();
	$ret = App::OrmJson($controller->GetAllMetricGroups());
	return $ret;
});

App::$app->get('/services/backoffice/GetPublicMetrics', function (Request $request) {
	$controller = new services\MetricService();
	$ret = App::OrmJson($controller->GetPublicMetrics());
	return $ret;
});

App::$app->get('/services/backoffice/GetCartographyMetrics', function (Request $request) {
	$controller = new services\MetricService();
	$ret = App::OrmJson($controller->GetCartographyMetrics());
	return $ret;
});


App::Get('/services/backoffice/GetRelatedDatasets', function (Request $request) {
	$controller = new services\MetricService();
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetReader($datasetId)) return $denied;
	return App::Json($controller->GetRelatedDatasets($datasetId));
});

App::Get('/services/backoffice/LevelDatasetMetrics', function (Request $request) {
	$controller = new services\MetricService();
	$sourceDatasetId = Params::GetIntMandatory('sk');
	$targetDatasetId = Params::GetIntMandatory('tk');
	if ($denied = Session::CheckIsDatasetEditor($targetDatasetId)) return $denied;
	return App::Json(array('Affected' => $controller->LevelDatasetMetrics($sourceDatasetId, $targetDatasetId)));
});

App::Get('/services/backoffice/GetColumnDistributions', function (Request $request) {
	$controller = new services\MetricService();
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetReader($datasetId)) return $denied;

	$dataColumn = Params::GetMandatory('c');
	if ($dataColumn === 'O') {
		$dataColumnId = Params::GetIntMandatory('ci');
	} else {
		$dataColumnId = null;
	}
	$normalizationColumn = Params::Get('o');
	if ($normalizationColumn === 'O') {
		$normalizationColumnId = Params::GetIntMandatory('oi');
	} else {
		$normalizationColumnId = null;
	}
	if ($normalizationColumn)
		$normalizationScale = Params::GetMandatory('s');
	else
		$normalizationScale = Params::Get('s');

	return App::Json($controller->GetColumnDistributions($datasetId, $dataColumn, $dataColumnId, $normalizationColumn, $normalizationColumnId, $normalizationScale));
});


App::GetOrPost('/services/backoffice/UpdateMetricVersionLevel', function (Request $request) {
	$controller = new services\MetricService();
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetEditor($datasetId)) return $denied;

	$param = Params::GetJsonMandatory('l');
	$level = App::ReconnectJsonParam(entities\DraftMetricVersionLevel::class, 'l');

	return App::Json($controller->UpdateMetricVersionLevel($datasetId, $level));
});

App::Post('/services/backoffice/ClipboardCopy', function (Request $request) {
	$text = Params::GetMandatory('t');
	PhpSession::SetSessionValue('clipboard', $text);
	return "OK";
});

App::Get('/services/backoffice/ClipboardPaste', function (Request $request) {
	$text = PhpSession::GetSessionValue('clipboard', null);
	return App::Json(array('text' => $text));
});

App::Get('/services/backoffice/MoveVariableUp', function (Request $request) {
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetEditor($datasetId)) return $denied;
	$controller = new services\MetricService();
	$variableId = Params::GetIntMandatory('v');
	return App::Json($controller->MoveVariableUp($datasetId, $variableId));
});

App::Get('/services/backoffice/MoveVariableDown', function (Request $request) {
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetEditor($datasetId)) return $denied;
	$controller = new services\MetricService();
	$variableId = Params::GetIntMandatory('v');
	return App::Json($controller->MoveVariableDown($datasetId, $variableId));
});

App::GetOrPost('/services/backoffice/DeleteVariable', function (Request $request) {
	$controller = new services\MetricService();
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetEditor($datasetId)) return $denied;

	$variableId = Params::GetIntMandatory('v');
	$levelId = Params::GetIntMandatory('l');

	return App::Json($controller->DeleteVariable($datasetId, $levelId, $variableId));
});

App::GetOrPost('/services/backoffice/DeleteMetricVersionLevel', function (Request $request) {
	$controller = new services\MetricService();
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetEditor($datasetId)) return $denied;

	$levelId = Params::GetIntMandatory('l');

	return App::Json($controller->DeleteMetricVersionLevel($datasetId, $levelId));
});

App::GetOrPost('/services/backoffice/UpdateVariable', function (Request $request) {
	$controller = new services\MetricService();
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetEditor($datasetId)) return $denied;

	$variable = Params::GetJsonMandatory('v');
	$level = App::ReconnectJsonParam(entities\DraftMetricVersionLevel::class, 'l');

	return App::Json($controller->UpdateVariable($datasetId, $level, $variable));
});

//TODO: programar esto...
App::GetOrPost('/services/backoffice/CalculateNewMetric', function (Request $request) {
	$datasetId = Params::GetIntMandatory('k');
	if ($denied = Session::CheckIsDatasetEditor($datasetId))
		return $denied;

		// 'w': loc.Work.Id,
		$workId = Params::Get('w');
		// 'm': newMetric.Id,
		$metricId = Params::Get('m');
		// 't': newMetric.Type,
		$type = Params::Get('t');
		// 'b': newMetric.SourceMetric.Metric.Id,
		$sourceMetricId = Params::Get('b');
		// 'o': JSON.stringify(newMetric.Output),
		$output = Params::GetJson('o');
		// 'a': JSON.stringify(newMetric.Area),
		$area = Params::GetJson('a');
		// 's': JSON.stringify(newMetric.Source),
		$source = Params::GetJson('s');

		if($type == 'distance')
		{
			$controller = CalculatedDistanceService();
			return App::Json($controller->StartCalculate($workId,
				$datasetId, $metricId, $sourceMetricId, $output, $area, $source
			));
		}
		elseif($type == 'area')
			return App::Json(['error' => 1, 'msg' => 'No implementado']);

	return App::Json(['error' => 1, 'msg' => 'No implementado']);
	// return App::Json($controller->CalculateNewMetric($datasetId, $etc, $etc));
});

