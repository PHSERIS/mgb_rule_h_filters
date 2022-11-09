<?php
namespace MGB\RuleHFiltersExternalModule;

use ExternalModules;

use REDCap;

// REDCap::escapeHtml

// restrict api to module use only, protects from random unwanted hits
if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"RuleHFiltersExternalModule") == false ) { exit(); }
	 
	$nl = "\n";
	$br = '<br>';
	$hr = '<hr>';
	$end = $br.$nl;
	$break = $hr.$nl;
	$retdata = null;

	$htmlDebug .= '';
	$html = '';
	$html .= '';

	$projectId = (isset($_POST['projectId']) ? basicFilter($_POST['projectId']) : 0);
	
	// TODO: put the protection back in
	if ($projectId == 0) {
		exit;
	}

	$dagsList = (isset($_POST['dagslist']) ? basicFilter($_POST['dagslist']) : 0);
	$timeList = (isset($_POST['timelist']) ? basicFilter($_POST['timelist']) : 0);

	$formsList  = (isset($_POST['formslist']) ? basicFilter($_POST['formslist']) : 0);
	$eventsList = (isset($_POST['eventslist']) ? basicFilter($_POST['eventslist']) : 0);
	
	// Flag to PROCESS or PREVIEW ONLY  TRUE = Process  FALSE = Preview
	$flagProcess = (isset($_POST['flagprocess']) ? (basicFilter($_POST['flagprocess']) ? true : false) : false);
	// 
	
	$logMsg = 'api process: [' . $flagProcess . ']';
	$module->emLog($logMsg, $projectId);

	$typeDags = 'dags';
	$typeTime = 'time';
	$typeBoth = 'dagstime';
	
	$type = 'NA';
	if ($dagsList > 0) {
		$type = $typeDags;
	}
	
	if ($timeList > 0) {
		$type = $typeTime;
	}

	if ( ($dagsList > 0) && ($timeList > 0) ) {
		$type = $typeBoth;
	}
	
	$retdata = $module->processingRuleHxParams($projectId, $dagsList, $timeList, $formsList, $eventsList, $type, $flagProcess);
	
	// programmer wants to see some data to diagnose
	//
	if ($module->getSystemSetting('debug_view') || $module->getProjectSetting('debug_view_project') ) {
		$htmlDebug .=  $hr;
		$htmlDebug .= '<div id="debuglisting">';
		$htmlDebug .= 'MSG:[';
		$htmlDebug .= $retdata['diagmsg'];
		$htmlDebug .= ']';
		$htmlDebug .= '</div>';

		if (isset($retdata['errors'])) {			
			$htmlDebug .=  $hr;
			$htmlDebug .= '<div id="errordebuglisting">';
			$htmlDebug .= 'ERRORS:[';
			$htmlDebug .= $retdata['errors'];
			$htmlDebug .= ']';
			$htmlDebug .= '</div>';
		}

	}	

	$recordsCount = ( isset($retdata['records']) ? count($retdata['records']) : 0 );
	
	$exclusionRecordsCount = ( isset($retdata['countExlusions']) ? basicFilter($retdata['countExlusions']) : 0 );
	
	// VIEW or PROCESS
	//
	//   VIEW
	if ($flagProcess == false) { // then we want a preview
		$html .=  $hr;
		$html .=  '<h1>Records</h1>';
		$html .=  $br;
	
		if ($recordsCount) {
			$html .= '<div id="apirecordlisting" class="flexigrid ui-dialog-content ui-widget-content" style="width: auto; min-height: 0px; max-height: none; height: 435px; overflow:scroll;">';

			$html .= '<p>';
			$html .= 'NOTE: Based on criteria selected above, the Selected Records Count, displayed below is the number of records that match your filters.  This is not a count of records with calculation fields.';
			$html .= '</p>';

			$html .= '<h2>';
			$html .= 'Selected Records Count: ';
			$html .= basicFilter($recordsCount);
			$html .= '</h2>';

			$html .= '<p>';
			$html .= 'Excluded Records Count: ';
			$html .= basicFilter($exclusionRecordsCount);
			$html .= '</p>';
			
			$html .=  $hr;

			$html .= '<table id="tablerecordlisting" width="100%" border="2" >';
			$html .= '<tbody>';
			$html .= '<tr>';
			$html .= '<th>Record ID</th>';
			$html .= '</tr>';
			
			$recordsList = $retdata['records'];
			
			foreach ($recordsList as $recordId => $recordVal) {
				$html .= '<tr>';
				$html .= '<td style="margin: 5px; padding: 3px 5px 2px;">';
				$html .= '';
				$html .= basicFilter($recordId);
				$html .= '</td>';
				$html .= '</tr>';
			}
			
			$html .= '</tbody>';
			$html .= '</table>';

			// show excluded record IDs if any
			if ($exclusionRecordsCount > 0) {
				$html .=  $hr;

				$excludedrecordsList = $retdata['excludedrecords'];
	
				$html .= '<table id="tablerecordlisting" width="100%" border="2" >';
				$html .= '<tbody>';
				$html .= '<tr>';
				$html .= '<th>Excluded Record ID</th>';
				$html .= '</tr>';
				
				
				foreach ($excludedrecordsList as $recordId => $recordVal) {
					$html .= '<tr>';
					$html .= '<td style="margin: 5px; padding: 3px 5px 2px;">';
					$html .= '';
					$html .= basicFilter($recordId);
					$html .= '</td>';
					$html .= '</tr>';
				}
				
				$html .= '</tbody>';
				$html .= '</table>';			
				$html .= '</div>';
			}
			
			cleanEcho($html);
			
			cleanEcho($htmlDebug);
		
		} else {
		//
		//  NO RECORDS

			$html .= '<div id="apirecordlisting">';

			$html .= '<h2>';
			$html .= 'NO Records Selected';
			$html .= '</h2>';
			
			$html .= '</div>';
			
			cleanEcho($html);
			cleanEcho($htmlDebug);
		}
			
	} else { // end if flagProcess
		//
		//  PROCESS
				
		if ($recordsCount) {
			$html .= '<div id="apirecordlisting">';

			$html .= '<h2>';
			$html .= 'Selected Records Count: ';
			$html .= $recordsCount;
			$html .= '</h2>';
			
			$html .= '<p>';
			$html .= 'Excluded Records Count: ';
			$html .= $exclusionRecordsCount;
			$html .= '</p>';
			
			$html .= '</div>';
			
			$html .= '<div id="apirecordlistingmsgs">';

			$html .= '<h2>';
			$html .= 'Results Info: ';
			$html .= '</h2>';
			$html .= '<p>';
			$html .= $retdata['results'];

			$html .= '</p>';
			
			$html .= '</div>';
			
			cleanEcho($html);
			cleanEcho($htmlDebug);
		}
	}

exit;
// **********

function cleanEcho($msg)
{
	echo $msg;
}

function basicFilter($data)
{
	return trim(strip_tags(html_entity_decode(trim($data), ENT_QUOTES)));
}

?>