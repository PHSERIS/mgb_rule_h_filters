<?php
namespace MGB\RuleHFiltersExternalModule;

use ExternalModules;

use REDCap;

// restrict api to module use only, protects from random unwanted hits
if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"RuleHFiltersExternalModule") == false ) { exit(); }

$projectId = (defined("PROJECT_ID") ? PROJECT_ID : 0);

if ($projectId == 0) {
	$str = 'NOT a project';
	exit;
}

$html = $module->rewindLimitOffset();

//$module->viewHtml($html, 'project');

?>