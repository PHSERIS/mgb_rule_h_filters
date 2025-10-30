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

    $htmlLimit = '';
    $htmlDebug .= '';
    $html = '';
    $html .= '';
    
    date_default_timezone_set($module->timeZoneToUse);

    $projectId = (isset($_POST['projectId']) ? basicFilter($_POST['projectId']) : 0);
    
    // the protection 
    if ($projectId == 0) {
        exit;
    }

    $module->RuleHFiltersInitializer($projectId);
    
    $module->projectId = $projectId;

    $dagsList = (isset($_POST['dagslist']) ? basicFilter($_POST['dagslist']) : 0);
    $timeList = (isset($_POST['timelist']) ? basicFilter($_POST['timelist']) : 0);

    $formsList  = (isset($_POST['formslist']) ? basicFilter($_POST['formslist']) : 0);
    $eventsList = (isset($_POST['eventslist']) ? basicFilter($_POST['eventslist']) : 0);
    
    // Flag to PROCESS or PREVIEW ONLY  TRUE = Process  FALSE = Preview
    $flagProcess = (isset($_POST['flagprocess']) ? (basicFilter($_POST['flagprocess']) ? true : false) : false);
    // 
    $flagRewind = false;
    $flagRewind = (isset($_POST['flagrewind']) ? (basicFilter($_POST['flagrewind']) == 1 ? true : false) : false);

    $eventsList = (isset($_POST['eventslist']) ? basicFilter($_POST['eventslist']) : 0);

    $dataitems_email = (isset($_POST['emailuser']) ? basicFilter($_POST['emailuser']) : '');

   
    // preview or process, use different limit offsets.  one for preview. one for process.
        // Flag to PROCESS or PREVIEW ONLY  TRUE = Process  FALSE = Preview
        // static_limit_offset for both preview and process
        // static_limit_offset_preview
        // static_limit_offset_process
        // rewind for preview for process
    
    if ($flagRewind) {
        $module->rewindLimitOffset();

        $valLimitOffset = $module->getLimitOffset($flagProcess);
        
        $htmlLimit .= '<div id="limitmsgs">';
        $htmlLimit .= '<h2>';
        $htmlLimit .= 'Limit Offset: ';
        $htmlLimit .= '</h2>';
        $htmlLimit .= '<p>';
        $htmlLimit .= $module->escape($valLimitOffset);
        $htmlLimit .= '</p>';           
        $htmlLimit .= '</div>';

        echo($htmlLimit);
        
        exit;
    }



    $dateProcessTimeStart = null;
    $dateProcessTimeFinish = null;
    $debug_cron = true;

    if ($debug_cron) {  
        $logMsg = 'api process: [' . $flagProcess . ']';
        $module->emLog($logMsg, $projectId);
    }

    $dateProcessTimeStart = $module->getProcessNowTime();
    
    // ***** LOG Start of this page activity *****
    if ($debug_cron) {
        $module->log($module->getMarkerHeader() . 'START: ' . $module->getStrNowTime($dateProcessTimeStart));
    }

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

    $StartvalLimitOffset = $module->getLimitOffset($flagProcess);
    
    $retdata = $module->processingRuleHxParams($projectId, $dagsList, $timeList, $formsList, $eventsList, $type, $flagProcess);

    $recordsCount = ( isset($retdata['records']) ? count($retdata['records']) : 0 );
    $exclusionRecordsCount = ( isset($retdata['countExlusions']) ? basicFilter($retdata['countExlusions']) : 0 );


    $valLimitOffset = $module->getLimitOffset($flagProcess);
        
    $htmlLimit .= '<div id="limitmsgs">';

    if ( $module->runningCount == $module->totalCountOfRecords ) {
        $htmlLimit .= '<h2>';
//        $htmlLimit .= 'LAST ' . $module->runningCount . ' ' . $module->totalCountOfRecords;
        $htmlLimit .= 'LAST';
        $htmlLimit .= '</h2>';
        $htmlLimit .= '<p>';
    }

    if ( $recordsCount == 0 ) {
        $htmlLimit .= '<h2>';
//        $htmlLimit .= 'DONE ' . $valLimitOffset . ' '  . $module->runningCount . ' ' . $module->totalCountOfRecords;
        $htmlLimit .= 'DONE';
        $htmlLimit .= '</h2>';
        $htmlLimit .= '<p>';
    } else {
        $htmlLimit .= '<h2>';
//        $htmlLimit .= 'Limit Offset: ' . $valLimitOffset . ' '  . $module->runningCount . ' ' . $module->totalCountOfRecords;
        $htmlLimit .= 'Limit Offset:';
        $htmlLimit .= '</h2>';
        $htmlLimit .= '<p>';
        $htmlLimit .= ($flagProcess ? 'PROCESS: ' : 'PREVIEW: ');
        $htmlLimit .= $StartvalLimitOffset;
//        $htmlLimit .= ( $valLimitOffset == 0 ? 'ALL' : $valLimitOffset );
    }
        
    $htmlLimit .= '</p>';           
    $htmlLimit .= '</div>';

    echo($htmlLimit);
    
    // programmer wants to see some data to diagnose
    //
    if ($module->getSystemSetting('debug_view') || $module->getProjectSetting('debug_view_project') ) {
        $htmlDebug .=  $hr;
        $htmlDebug .= '<div id="debuglisting">';
        $htmlDebug .= 'MSG:[';
        $htmlDebug .= $module->escape($retdata['diagmsg']);
        $htmlDebug .= ']';
        $htmlDebug .= '</div>';

        if (isset($retdata['errors'])) {            
            $htmlDebug .=  $hr;
            $htmlDebug .= '<div id="errordebuglisting">';
            $htmlDebug .= 'ERRORS:[';
            $htmlDebug .= $module->escape($retdata['errors']);
            $htmlDebug .= ']';
            $htmlDebug .= '</div>';
        }

    }   
    
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
            $html .= $module->escape(basicFilter($recordsCount));

            if ($module->runningCount > 0) {                
                $html .= ' <br>TOTAL Number of records: ';
                $html .= $module->escape($module->runningCount);
                $html .= ' of ';
                $html .= $module->escape($module->totalCountOfRecords);
            } else {
                $html .= ' <br> ';
                
            }

            $html .= '</h2>';

            $html .= '<p>';
            $html .= 'Excluded Records Count: ';
            $html .= $module->escape(basicFilter($exclusionRecordsCount));
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
                $html .= $module->escape(basicFilter($recordId));
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
                    $html .= $module->escape(basicFilter($recordId));
                    $html .= '</td>';
                    $html .= '</tr>';
                }
                
                $html .= '</tbody>';
                $html .= '</table>';            
                $html .= '</div>';
            }
            
            echo($html);            
            echo($htmlDebug);
        
        } else {
        //
        //  NO RECORDS

            $html .= '<div id="apirecordlisting">';

            $html .= '<h2>';
            $html .= 'NO Records Selected';
            $html .= '</h2>';
            
            $html .= '</div>';
            
            echo($html);
            echo($htmlDebug);
        }
            
    } else { // end if flagProcess
        //
        //  PROCESS
                
        if ($recordsCount) {
            $html .= '<div id="apirecordlisting">';

            $html .= '<h2>';
            $html .= 'BATCH<br> Selected Records Count: ';
            $html .= $recordsCount;

            $html .= ' <br>TOTAL Number of records: ';
            $html .= $module->escape($module->runningCount);
            $html .= ' of ';
            $html .= $module->escape($module->totalCountOfRecords);

            $html .= '</h2>';
            
            $html .= '<p>';
            $html .= 'Excluded Records Count: ';
            $html .= $module->escape($exclusionRecordsCount);
            $html .= '</p>';
            
            $html .= '</div>';
            
            $html .= '<div id="apirecordlistingmsgs">';

            $html .= '<h2>';
            $html .= 'CHUNK<br> Results Info: ';
            $html .= '</h2>';
            $html .= '<p>';
            $html .= $module->escape($retdata['results']);

            $html .= '</p>';
            
            $html .= '</div>';
            
            echo($html);
            echo($htmlDebug);
        }
    }
    
    $dateProcessTimeFinish = $module->getProcessNowTime();
    // ***** LOG Finish of this page activity *****
    processorComplete($module, $debug_cron, $dateProcessTimeStart, $dateProcessTimeFinish);

    // add send an email to who concerned about when done
    if ($flagProcess) {
        if ($recordsCount) {
            $msg  = 'Selected Records Count: ' . $recordsCount;
                
            $msg .= '<br>TOTAL Number of records: ';
            $msg .= $module->runningCount;
            $msg .= ' of ';
            $msg .= $module->totalCountOfRecords;

            if ( $module->runningCount == $module->totalCountOfRecords ) {
                $msg .= '<br>';
                $msg .= 'LAST';
            }
                     
            $msg .= '<br>';
            $msg .= 'Done';
            $module->sendEmailWhenDoneMsg($msg, $dataitems_email);
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

/** 
 *
 */
function processorComplete($module, $debug_cron, $startDateTime = null, $endDateTime = null)
{
    if ($endDateTime == null) {
        $endDateTime = $module->getProcessNowTime();
    }
    
    $module->processDurationMsg($module, $debug_cron, $startDateTime, $endDateTime);
    finishMsg($module, $debug_cron, $endDateTime);

    return $endDateTime;
}

/** 
 *
 */
function finishMsg($module, $debug_cron, $now = null)
{
    // ***** LOG Finish of this page activity *****
    if ($debug_cron) {
        $module->log($module->getMarkerHeader() . 'FINISH: ' . $module->getStrNowTime($now));
    }
}


?>