<?php
/// A processor for performing RULE H calculations with a smaller set of records defined by DAGs, past 7 days or 24 hours, Forms, Events or a combination of those choices.
/**
 * RuleHFiltersExternalModule
 *  - CLASS for some features.
 *  
 *  
 *  - MGB - Mass General Brigham RISC. 
 * @author David L. Heskett
 * @version1.0.0
 * @date20220602
 * @copyright &copy; 2022 Mass General Brigham, RISC, Research Information Science and Computing <a href="https://rc.partners.org//">MGB RISC\</a>  <a href="https://redcap.partners.org/redcap/">redcap.partners.org</a> 
 */

// TODO: create page of config settings we need access to.

/*
TODO: enhancements

suggested enhancements:

1. run rule H for participants not in a DAG
2. get a listing at the end of what was changed
    - listing of all the ids/events/forms that were touched
    - or a list of all the log transactions on the same page 
3. a mode to find out what calculations are incorrect

4. use a record ID,  use a list of record IDs

5. use a report to gather list of IDs, use report ID.

thoughts: for 2 b   just going to end up with a list of record IDs that we have.  and all the fields that are calc fields.
I am not sure that this is truly useful information.
maybe it is.

use a report to gather list of IDs, use report ID.

TODO: change count() to $this->robustCount(

Lynn:  now with a report and record ids, also, can it run just certain calcs.


*/

namespace MGB\RuleHFiltersExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

use \HtmlPage;
use \Logging;

use \REDCap;
use \Records;
use \Calculate;
use \Project;

use DateTime;
use DateInterval;


//static $staticLimitOffset = 2;
global $staticLimitOffset;

class RuleHFiltersExternalModule extends AbstractExternalModule
{
    public $debugLogFlag;
    public $debug_mode_log;
    public $debug_mode_project;
    public $debug_view;
    public $debug_view_project; 
    public $spool_switch;  // Spooling Switch ON or OFF (Default: ON which is UNCHECKED) Check to turn OFF
    public $spool_size;    // Spooling Chunk Size (Default: 100)    
    public $recordCount;
    public $valuesFixed;
    public $errorMsg;
    public $markerHeader;
    public $timeZoneToUse;
    public $totalCountOfRecords;
    public $runningCount;

    public $projectId;
    private $dagsList;
    private $flagMergeType;
    private $initOnce = false;

    private $main_cron_switch;          // potential cron use NOT IMPLEMENTED
    private $project_main_cron_switch;  // potential cron use NOT IMPLEMENTED
    
    public $flag_email_send;
    public $flag_turbo;  // true = use the experimental faster DAG and Time lookups, false = use previous and REDCap core calls 

    const NAME_IDENTIFIER = 'RuleHFilters';

    const VIEW_CMD_PROJECT = 'project';
    const VIEW_CMD_CONTROL = 'control';
    const VIEW_CMD_SYSTEM  = 'system';
    const VIEW_CMD_DEFAULT = '';
    
    const API_PROCESS_RECORDS  = 'apiprocessrecords.php';

    const BTN_LABEL_CLEAR   = 'CLEAR CHOICES';
    const BTN_LABEL_PREVIEW = 'PREVIEW';
    const BTN_LABEL_PROCESS = 'Update Calcs Now: SUBMIT TO PROCESS';
    const BTN_LABEL_PREVIEW2 = 'Record List PREVIEW';
    const BTN_LABEL_REWIND  = 'REWIND The list';
    const BTN_LABEL_NOTIFY  = 'Notify me when DONE send EMAIL to address: ';
    
    const MSG_PROCESS_DONE = 'Changes have been processed successfully';
    const MSG_PROCESS_ERR  = 'Error';
    const MSG_PROCESS_FIN  = 'Finished';

    const SETTING_SPOOL_SIZE  = 100;
    
    const MERGE_TYPE_AND  = 1;
    const MERGE_TYPE_OR   = 2;
    
    const TIMEZONETOUSE = 'America/New_York';
    const RULEHPREFIXLOG = 'Rule H:';
    const RULEHDATEFORMATEXTENDEDSECS = 'Y-m-d H:i:s';
    const RULEHDATEFORMATEXTENDED = 'Y-m-d H:i';
    
    const EMAIL_MSG = 'Rule H Filters Processing Done.';
    const EMAIL_SUBJECT = 'Rule H Filters Processing Done.';
    const EMAIL_NO_REPLY = 'noreply@mgb.org';

    // **********************************************************************   
    // **********************************************************************   
    // **********************************************************************   

    /**
     * constructor - set up object.
     */
    //public function __construct($pid = null) 
    public function RuleHFiltersInitializer($pid = null) 
    {
        //parent::__construct();
        // Other code to run when object is instantiated
        
        if ($this->initOnce) {
            return;
        }
        $this->initOnce = true;

        $this->projectId = null;
        $this->dagsList = null;

        $this->debugLogFlag = null;
        $this->debug_mode_log_project = null;
        $this->debug_mode_log_system  = null;

        // project ID of project 
        if ($pid) {
            $projectId = $pid;
        } else {
            $projectId = (isset($_GET['pid']) ? $_GET['pid'] : 0);
        }
        
        if ($projectId > 0) {
            $this->projectId = $projectId;
        } else {
            $projectId = null;
        }

        $this->loadConfig($projectId);
        
        $this->debugLogFlag = ($this->debug_mode_log ? true : false);
        
        $this->formsNamesList = null;
        $this->formsFields = null;
        $this->flagMergeType = null;
        
        // handle the flag merge type, the AND OR combine logic flagging
        $this->flagMergeType = RuleHFiltersExternalModule::MERGE_TYPE_AND;  // initial default it to AND.  it will be checked again before actual use.
        
        $this->main_cron_switch = false;
        $this->project_main_cron_switch = false;
        
        $this->debug_view = false;
        $this->debug_view_project = false;
        
        $this->markerHeader = self::RULEHPREFIXLOG . ' ';
        $this->timeZoneToUse = self::TIMEZONETOUSE;
        
        $this->totalCountOfRecords = 0;
        $this->runningCount = 0;
        
        $this->flag_email_send = false;
    }

    /**
     * clearInitOnce - clear the init flag.
     */
    public function clearInitOnce() 
    {
        $this->initOnce = false;
    }

    /**
     * loadConfig - configuration settings here.
     */
    public function loadConfig($projectId = 0) 
    {
        if ($projectId > 0) {
            $this->loadProjectConfig($projectId);
        } else {
            $this->loadProjectConfigDefaults();
        }

        $this->loadSystemConfig();

        $this->debugLogFlag = ($this->debug_mode_log_project || $this->debug_mode_log_system ? true : false);
    }

    /**
     * loadSystemConfig - System configuration settings here.
     */
    public function loadSystemConfig() 
    {
        $this->debug_mode_log_system = $this->getSystemSetting('debug_mode_log_system');
        
        // put some of your other config settings here
        
        //$this->main_cron_switch = $this->getSystemSetting('main_cron_switch');

        $this->debug_view = $this->getSystemSetting('debug_view');

    }

    /**
     * loadProjectConfig - Project configuration settings here.
     */
    public function loadProjectConfig($projectId = 0) 
    {
        if ($projectId > 0) {
            $this->debug_mode_log_project = $this->getProjectSetting('debug_mode_log_project');

            // put some of your other config settings here
            
            //$this->project_main_cron_switch = $this->getProjectSetting('project_main_cron_switch');
            
            $this->debug_view_project = $this->getProjectSetting('debug_view_project');
            
            // spool_switch
            // Spooling Switch ON or OFF (Default: ON which is UNCHECKED) Check to turn OFF
            $this->spool_switch = ($this->getProjectSetting('spool_switch') ? false : true);
            
            // spool_size
            // Spooling Chunk Size (Default: 100)
            $this->spool_size = ($this->getProjectSetting('spool_size') ? $this->getProjectSetting('spool_size') : self::SETTING_SPOOL_SIZE);
            
        }
    }

    /**
     * loadProjectConfigDefaults - set up our defaults.
     */
    public function loadProjectConfigDefaults()
    {
        $this->debug_mode_log_project   = false;
    }
    
    /**
     * easyLogMsg - .
     */
    public function easyLogMsg($debugmsg, $shortMsg = '')
    {
        if ($this->debugLogFlag) {
            $this->debugLog($debugmsg, ($shortMsg ? $shortMsg : $debugmsg));
            return true;
        }
        
        return false;
    }
    
    /**
     * alwaysLogMsg - .
     */
    public function alwaysLogMsg($debugmsg, $shortMsg = '')
    {
        $this->performLogging($debugmsg, ($shortMsg ? $shortMsg : $debugmsg));
    }

    /**
     * performLogging - .
     */
    public function performLogging($logDisplay, $logDescription = self::NAME_IDENTIFIER)
    {
        REDCap::logEvent($logDescription, $logDisplay);
    }
        
    /**
     * debugLog - (debug version) Simplified Logger messaging.
     */
    public function debugLog($debugmsg = '', $logDisplayMsg = self::NAME_IDENTIFIER)
    {
        if (!$this->debugLogFlag) {  // log mode off
            return;
        }
        
        $this->performLogging($debugmsg, $logDisplayMsg);
    }

    /**
     * showJson - show a json parsable page.
     */
    public function showJson($rsp, $convertFlag = false) 
    {
        if ($convertFlag) {
            $rsp = json_encode($rsp);
        }
        
        $jsonheader = 'Content-Type: application/json; charset=utf8';
        header($jsonheader);
        echo $rsp;
    }

    /**
     * viewHtml - the front end part, display what we have put together. This method has an added feature for use with the control center, includes all the REDCap navigation.
     */
    public function viewHtml($msg = 'view', $flag = self::VIEW_CMD_DEFAULT)
    {
        $HtmlPage = new HtmlPage(); 

        switch ($flag) {
            // project
            case self::VIEW_CMD_PROJECT:
                $HtmlPage->ProjectHeader();
              echo $msg;
                $HtmlPage->ProjectFooter();
                break;

            // control
            case self::VIEW_CMD_CONTROL:
                $user = $this->getUser();
                if(!$user->isSuperUser()) {
                //if (!SUPER_USER) {
                    redirect(APP_PATH_WEBROOT); 
                }
    
                global $lang;  // this is needed for these two to work properly
                include APP_PATH_DOCROOT . 'ControlCenter/header.php';
                include APP_PATH_VIEWS . 'HomeTabs.php';
              echo $msg;
                include APP_PATH_DOCROOT . 'ControlCenter/footer.php';
                break;

            // system
            case self::VIEW_CMD_SYSTEM:
            default:
                $HtmlPage->setPageTitle($this->projectName);
                $HtmlPage->PrintHeaderExt();
              echo $msg;
                $HtmlPage->PrintFooterExt();
                break;
        }
    }
    
    /**
     * prettyPrint - an html pretty print of a given array of data. if given htmlformat false then just as text
     */
    public function prettyPrint($data, $htmlFormat = true) 
    {
        if ($htmlFormat) {
            $html = '';
            $pre = '<pre>';
            $pree = '</pre>';
    
            $html .= $pre;
            $formatted = print_r($data, true);

            $html .= htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8', true);
            $html .= $pree;

            return $html;
        }
        
        $text = print_r($data, true);
        
        return $text;
    }

    /**
     * emlog - set this projects logs.
     */
    public function emlog($msg = null, $projectId = null, $actionMsg = '') 
    {
        if (!$projectId) {
            $projectId = ($this->projectId ? $this->projectId : (defined("PROJECT_ID") ? PROJECT_ID : 0));
            
            if($projectId == 0) {
                return; 
            }
        }
        
        $this->makeLogMsg($msg, $projectId, $actionMsg);
    }

    /*
        log date formats
        current:
        RHF 20240501134040 RULE H FILTERS PROGRESS PID: 36 Chunk: 28 of 28 Processed: 2772 of 2772
        proposed:
        Rule H: 2024-05-01 13:40 - PID: 36; Chunk: 28 / 28; Processed: 2772 / 2772
        
        
        current:
        RHF 20240501134040 FINISH RULE H FILTERS PID: 36
        proposed:
        Rule H: 2024-05-01 13:40 - PID: 36; Finished duration 1h20m
    
    */
    /**
     * makeLogMsg - set this projects logs.
     */
    public function makeLogMsg($msg = null, $projectId = null, $action = '') 
    {
        $now = date(self::RULEHDATEFORMATEXTENDED);
        $logPrefix = $this->markerHeader;
        
        $logparams = ($projectId ? array('project_id' => $projectId) : null);
        
        if ($logparams['project_id'] != null) {  // prevent log from breaking when no PID
            //$this->log($logPrefix . ' ' . $now . ' ' . $msg, $logparams);     
            //$this->alwaysLogMsg($logPrefix . ' ' . $now . ' ' . $msg);    
        }
        
        if (strlen($action) > 0) {
            $this->alwaysLogMsg($logPrefix . ' ' . $now . ' ' . $msg, $action);
            return;
        }
        
        $this->alwaysLogMsg($logPrefix . ' ' . $now . ' ' . $msg);  
    }   

    /**
     * testLog - .
     */
    public function testLog($projectId = null) 
    {
        if (!$projectId) {
            $projectId = 22;
        }
        
        $msg = 'TEST EM LOGGING Feature: ' . count($projectId) . ' ' . $projectId;
        
        $this->emlog($msg, $projectId);
        
        $this->manualRunRuleH();
        
        $msg = 'finished.';
        $this->emlog($msg);
        
        return $msg;
    }

    // potential cron use NOT IMPLEMENTED
    /*  if want cron, then add to config one of these as desired with change of settings we wish to have

    "crons": [
        {
            "cron_name": "on_the_hour",
            "cron_description": "RUN RULE H",
            "method": "cronRunRuleH",
            "cron_hour": 9,
            "cron_minute": 15
        },
        {
            "cron_name": "every_minute_check",
            "cron_description": "Automatic Run Rule H",
            "method": "cronRunRuleH",
            "cron_frequency": "60",
            "cron_max_run_time": "500"
        }
    ],
    
    */

    // potential cron use NOT IMPLEMENTED
    /**
     * runRuleH - .
     */
    public function cronRunRuleH($projectId = null) 
    {
        if (!$projectId) {
            $projectId = (defined("PROJECT_ID") ? PROJECT_ID : 0);
            
            if ($projectId == 0) {
                // log not in a project
                // TODO: make the log call
                return;
            }
        }

        if ($this->isCronOff()) {  // main cron switch OFF
            // Do we log this?  or just be quiet?
            // TODO: log  "Cron RULE H is Turned OFF"
            return;
        }       
    }
        
    /**
     * runRuleH - .
     */
    public function runRuleH($projectId = null) 
    {
        if (!$projectId) {
            $projectId = (defined("PROJECT_ID") ? PROJECT_ID : 0);
            
            if ($projectId == 0) {
                // log not in a project
                // TODO: make the log call in runRuleH
                return;
            }
        }       
    }
    
    /**
     * isCronOff - .
     */
    public function isCronOff() 
    {
        //$this->main_cron_switch = $this->getSystemSetting('main_cron_switch');
        
        //$this->project_main_cron_switch = $this->getProjectSetting('project_main_cron_switch');

        $this->main_cron_switch = 0;
        
        $this->project_main_cron_switch = 0;
        
        if ($this->main_cron_switch == 1) {  // main cron switch OFF
            return true;
        }

        if ($this->project_main_cron_switch == 1) {  // project main cron switch OFF
            return true;
        }
        
        return false;
    }
        
    /**
     * processingRuleHxParams - .
     */
    public function processingRuleHxParams($projectId = null, $paramsDagList = null, $paramsTimeList = null, $paramsFormList = null, $paramsEventList = null, $type = 'NA', $flagProcess = true) 
    {
        // $flagProcess = Flag to PROCESS or PREVIEW ONLY  TRUE = Process  FALSE = Preview
        
        $ret = array();
        $processResults = array();
        $diagmsg = '';
        
        if (!$projectId) {
            $projectId = (defined("PROJECT_ID") ? PROJECT_ID : 0);
            
            if ($projectId == 0) {
                // log not in a project
                // TODO: make the log call in processingRuleHxParams
                return;
            }
        }

        $recordsDags = $this->getListRecords($projectId, $paramsDagList, 'dags', $flagProcess);
        $recordsTime = $this->getListRecords($projectId, $paramsTimeList, 'time');
        $recordsForm = $this->getListRecords($projectId, $paramsFormList, 'form');
        $recordsEvts = $this->getListRecords($projectId, $paramsEventList, 'event');

        // DEBUGGING CODE display
        $diagmsg .= 'paramsDagList: [' . print_r($paramsDagList, true) . '];';
        $diagmsg .= '<br>';
        $diagmsg .= 'paramsTimeList: [' . print_r($paramsTimeList, true) . '];';
        $diagmsg .= '<br>';
        $diagmsg .= 'paramsFormList: [' . print_r($paramsFormList, true) . '];';
        $diagmsg .= '<br>';
        $diagmsg .= 'paramsEventList: [' . print_r($paramsEventList, true) . '];';
        $diagmsg .= '<br>';
        
        $diagmsg .= 'DAGS: [' . print_r($recordsDags, true) . '];';
        $diagmsg .= '<br>';

        $diagmsg .= 'TIME: [' . print_r($recordsTime, true) . '];';
        $diagmsg .= '<br>';

        $diagmsg .= 'FORM: [' . print_r($recordsForm, true) . '];';
        $diagmsg .= '<br>';

        $diagmsg .= 'EVNT: [' . print_r($recordsEvts, true) . '];';
        $diagmsg .= '<br>';
        
        $flagMergeType = $this->getFlagMergeType();
        
        // if we are OR logic, do these
        if (self::MERGE_TYPE_OR == $flagMergeType) {
            $records = $this->recordsListMerge($recordsDags, $recordsTime);
            $records = $this->recordsListMerge($recordsForm, $records);
            $records = $this->recordsListMerge($recordsEvts, $records);
            
            ksort($records);
        } else if (self::MERGE_TYPE_AND == $flagMergeType) {
            $bits = 0;
            
            $bits += ($paramsDagList   ? 1 : 0);
            $bits += ($paramsTimeList  ? 2 : 0);
            $bits += ($paramsFormList  ? 4 : 0);
            $bits += ($paramsEventList ? 8 : 0);

            $diagmsg .= 'BITS: [' . print_r($bits, true) . '];';
            $diagmsg .= '<br>';
            
            $strHasParamsList = '';
            
            $strHasParamsList .= ($bits & 1   ? 'DAGs: ' : '');
            $strHasParamsList .= ($bits & 2   ? 'TIME: ' : '');
            $strHasParamsList .= ($bits & 4   ? 'FORMS: ' : '');
            $strHasParamsList .= ($bits & 8   ? 'EVENTS: ' : '');

            $diagmsg .= 'Chosen: [' . $strHasParamsList . '];';
            $diagmsg .= '<br>';
            
            // TODO: probably a better way to do this.  Improve this
            // the problem solve here is, if only ONE item chosen, give back that list.
            // if some items chosen, must exclude the NULL items for the not chosen (otherwise we end up ANDing with empty and result will be empty)
            switch ($bits) {
                case 1:
                    $records = $recordsDags;
                    break;
                case 2:
                    $records = $recordsTime;
                    break;
                case 4:
                    $records = $recordsForm;
                    break;
                case 8:
                    $records = $recordsEvts;
                    break;

                case 3: // 1, 2
                    $records = $this->recordsListMerge($recordsDags, $recordsTime);
                    break;
                case 5: // 1, 4
                    $records = $this->recordsListMerge($recordsDags, $recordsForm);
                    break;
                case 6: // 2, 4
                    $records = $this->recordsListMerge($recordsTime, $recordsForm);
                    break;
                case 7: // 1, 2, 4
                    $records = $this->recordsListMerge($recordsDags, $recordsTime);
                    $records = $this->recordsListMerge($recordsForm, $records);
                    break;
                case 9: // 1, 8
                    $records = $this->recordsListMerge($recordsDags, $recordsEvts);
                    break;
                case 10: // 2, 8
                    $records = $this->recordsListMerge($recordsTime, $recordsEvts);
                    break;
                case 11: // 1, 2, 8
                    $records = $this->recordsListMerge($recordsDags, $recordsTime);
                    $records = $this->recordsListMerge($recordsEvts, $records);
                    break;
                case 12: // 4, 8
                    $records = $this->recordsListMerge($recordsForm, $recordsEvts);
                    break;
                case 13: // 1, 4, 8
                    $records = $this->recordsListMerge($recordsDags, $recordsForm);
                    $records = $this->recordsListMerge($recordsEvts, $records);
                    break;
                case 14: // 2, 4, 8
                    $records = $this->recordsListMerge($recordsTime, $recordsForm);
                    $records = $this->recordsListMerge($recordsEvts, $records);
                    break;
                case 15: // 1, 2, 4, 8
                    $records = $this->recordsListMerge($recordsDags, $recordsTime);
                    $records = $this->recordsListMerge($recordsForm, $records);
                    $records = $this->recordsListMerge($recordsEvts, $records);
                    break;

                default: // 
                    $records = null;
                    break;                  
            }
            
            
        }
        
        $logMsg = 'RUNNING:';
        $this->emlog($logMsg . ($flagProcess ? 'flag to process' : 'flag is off'), $projectId);

        if ($flagProcess) {
            // processRecordsSpooling
            $processResults = $this->processRecordsSpooling($projectId, $records);  // spool the processing of records
        }           

        $ret['msg']             = (isset($processResults['status'])          ? $processResults['status']          : '');
        $ret['results']         = (isset($processResults['results'])         ? $processResults['results']         : '');
        $ret['errors']          = (isset($processResults['errors'])          ? $processResults['errors']          : '');
        $ret['excludedrecords'] = (isset($processResults['excludedrecords']) ? $processResults['excludedrecords'] : null);
        $ret['countExlusions']  = (isset($processResults['countExlusions'])  ? $processResults['countExlusions']  : 0);
        $ret['diagmsg'] = $diagmsg;
        $ret['records'] = $records;

        return $ret;        
    }   

    /**
     * robustCount - fix broken count() in php8.  because, PHP8 can no longer count a null or nonexistant array or value.
     *
     * robustCount(Something) where something is a string, null, 0, array, object, pancake, apples, oranges
     *
     */
    public function robustCount($val)
    {   
        if ($val === null) {
            return 0;
        }
        
        if (isset($val)) {
            if (is_string($val) ) {
                return strlen($val);
            }
            
            if (is_countable($val) === false) {
                if (is_object($val) ) {
                    return 1;
                }
                
                if ($val === 0) {
                    return 0;
                }

                if ($val === false) {
                    return 0;
                }
                
                return 1;
            }
            
            return count($val);
        }
    
        return 0;
    }
    
    /**
     * showReports - get a listing of the Reports, titles and report IDs. return an array or a string list (as a csv style with pipes for the newlines).
     */
    public function showReports($showString = false)
    {
        $reportNameList = array();
        
        $reports = \DataExport::getReportNames(null, true);
        
        if ($reports) {
            foreach ($reports as $rep) {
                $reportNameList[] = array('title' => $rep['title'], 'reportId' => $rep['report_id']);
            }
        }
        
        if ($showString) {
            $list = 'blank';
            
            if ($reportNameList != null) {
                
                $list = '';
                
                foreach ($reports as $rep) {
                    $list .= $rep['title'];
                    $list .= ',';
                    $list .= $rep['report_id'];
                    $list .= '|';
                }
            }
            
            return $list;
        }
        
        return $reportNameList;
    }
    
    /**
     * getRecordCount - get count of records also get project ID if not set.
     */
    public function getRecordCount($projectId = 0)
    {   
        $recordCount = 0;
        
        if ($this->projectId === 0 || $this->projectId === null) {
            $this->projectId = (PROJECT_ID ? PROJECT_ID : 0);
        } 

        if ($projectId) {
            $recordCount = \Records::getRecordCount($projectId);
        } elseif ($this->projectId) {
            $recordCount = \Records::getRecordCount($this->projectId);
        }
        
        $this->recordCount = $recordCount;
        
        return $this->recordCount;
    }

    /**
     * getCalcCount - get count of calc fields also get project ID if not set.
     */
    public function getCalcCount($Proj = null)
    {   
        if ($Proj === null) {
            return 0;  // maybe -1 
        }
        
        // Count the number of calc fields
        $numCalcFields = 0;
        
        foreach ($Proj->metadata as $attr) {
            if ($attr['element_type'] == 'calc') {
                $numCalcFields++;
            }
        }
        
        $this->numCalcFields = $numCalcFields;
        
        return $this->numCalcFields;
    }

    /**
     * getCalcStats - get count of calc fields and count of records also get project ID if not set.
     */
    public function getCalcStats($givenProj = null)
    {   
        $calcProj = null;
        
        $this->getRecordCount();
        
        global $Proj;

        if ($givenProj === null) {
            if ($Proj === null) {
            } else {
                $calcProj = $Proj;
            }
        }
                
        $this->getCalcCount($calcProj);
    }
        
    /**
     * getRecordIdList - given a report ID, get a list of record IDs using a report. return an array of record IDs.
     *
     *  given:  report ID (an unsigned integer number)
     *  return: [] record IDs
     */
    public function getRecordIdList($reportId)
    {
        $list = [];

        $reportItemList = \DataExport::doReport($reportId, 'export', 'array', false, false,
                        false, false, false, false,
                        false, false,
                        false, false, false,
                        array(), array(), false,
                        false, false, false,
                        true, '', '', '', 
                        true);  // this last true is key to this working $isDeveloper = true;

        foreach ($reportItemList as $reportItemListKey => $reportListItem) {
            $event_idX = array_keys($reportListItem);  // get the event_id, whatever it is, because it is going to change
            $event_id = $event_idX[0];  // TODO: what if longitudinal
            
            $recordIdFieldName = REDCap::getRecordIdField();  // normally: record_id  however, this could be changed by designer.
            $list[] = $reportListItem[$event_id][$recordIdFieldName];  // get the record ID and make a list of just those.  with this list you can give to RULE H for processing
        }

        return $list;
    }
    
    /**
     * processRecordsSpooling - process records for the project.
     */
    public function processRecordsSpooling($projectId, $records) 
    {       
        $str = '';
        $msg = '';
        $statusMsg = '';
        $resultMsg = '';
        $processingMsgData = array();
        $dateProcessTimeStart = null;
        $dateProcessTimeFinish = null;

        $countRecords = $this->robustCount($records);
        
        $statusMsg .= '';
        $statusMsg .= 'Records to run: ' . $countRecords;
        //$statusMsg .= '<hr>';
        $dateProcessTimeStart = $this->getProcessNowTime();
        /*
            log date formats
            current:
            RHF 20240501134040 RULE H FILTERS PROGRESS PID: 36 Chunk: 28 of 28 Processed: 2772 of 2772
            proposed:
            Rule H: 2024-05-01 13:40 - PID: 36; Chunk: 28 / 28; Processed: 2772 / 2772
            
            
            current:
            RHF 20240501134040 FINISH RULE H FILTERS PID: 36
            proposed:
            Rule H: 2024-05-01 13:40 - PID: 36; Finished duration 1h20m
        
        */      
        
        $this->emlog('START RULE H FILTERS PID: ' . $projectId, $projectId, 'RULE H Filters Process');
        
        // check records count before dropping into the process.
        if ($countRecords > 0) {
            
            // Check for any exclusions, and then feed in the list of records, and process the calc fields.         
            $excluded = $this->exclusionList($projectId);
            $countExlusions = $this->robustCount($excluded);
            $statusMsg .= ' Exclusion count: ' . $countExlusions . ' ';
            
            // add spooling here
            $spooling = $this->getconfigSpoolSwitch();
            // if spooling and spool size
            // 
            if ($spooling) {
                $spoolSize = $this->getconfigSpoolSize();
                
                $countProcessed = 0;
                $countChunk = 0;
                $totalCountChunk = 0;
                
                $recordChunks = array_chunk($records, $spoolSize, true);
                $totalCountChunk = $this->robustCount($recordChunks);
                
                foreach ($recordChunks as $recordChunksKeys => $recordsToProcess) {                 
                    $thisSavedCalc = $this->saveCalcFields($recordsToProcess, $excluded);
                    
                    $countProcessed += $this->robustCount($recordsToProcess);
                    $countChunk++;
        
                    $msg .= ' Chunk: ' . $countChunk . ' ';
                    $msg .= ' of ' . $totalCountChunk . ' ';

                    $msg .= 'Processed: ' . $countProcessed . ' of ' . $countRecords;

                    /*
                        log date formats
                        current:
                        RHF 20240501134040 RULE H FILTERS PROGRESS PID: 36 Chunk: 28 of 28 Processed: 2772 of 2772
                        proposed:
                        Rule H: 2024-05-01 13:40 - PID: 36; Chunk: 28 / 28; Processed: 2772 / 2772
                        
                        
                        current:
                        RHF 20240501134040 FINISH RULE H FILTERS PID: 36
                        proposed:
                        Rule H: 2024-05-01 13:40 - PID: 36; Finished duration 1h20m
                    
                    */
                    $this->emlog('- PID: ' . $projectId . ' ' . $msg, $projectId, 'RULE H Filters Process');                
                    
                    $nowProcessTime = $this->getProcessNowTime();
                    $processTimeStr = $nowProcessTime->format('Y-m-d H:i');
                    $this->log($this->markerHeader . $processTimeStr . ' - PID: ' . $projectId . ' ' . $msg);                

                    $statusMsg .= $msg;

                    // the error part here, detect if an array perhaps. for now, just give back what it hands you.
                    //  our method leads to: Calculate::saveCalcFields   which leads to Records::saveData   (and I recall the errors was an array)
                    // saveCalcFields returns, either, a string count, or, errors (and errors could potentially be an array?)
                    // print_r($thisSavedCalc, true) 
    
                    if (is_array($thisSavedCalc)) {
                        // Results for chunk 1 of 1 calculated fields processed 330                     
                        $resultMsg .= $this->msgCalcResults($countChunk, $totalCountChunk, 'ERRORS: There were errors in processing.  If problem persists then run the standard Rule H Data Quality process.');
                        
                        // if diag mode
                        // display
                        if ($this->getSystemSetting('debug_view') || $this->getProjectSetting('debug_view_project') ) {
                            $resultMsg .= $this->msgCalcResults($countChunk, $totalCountChunk, 'ERRORS: ' . print_r($thisSavedCalc, true));
                            $processingMsgData['errors']  = print_r($thisSavedCalc, true);
                        }
                        
                        
                    } else {
                        if ($thisSavedCalc == '0') {
                            // all done, no calcs to handle
                            $resultMsg .= $this->msgCalcResults($countChunk, $totalCountChunk, 'calculated fields DONE and NO MORE TO PROCESS');
                        } else {
                            // some number of calcs completed
                            $resultMsg .= $this->msgCalcResults($countChunk, $totalCountChunk, 'calculated fields processed: ' . print_r($thisSavedCalc, true));
                        }
                    }
                    
                    $msg = '';                      
                }
            } else {
                $thisSavedCalc = $this->saveCalcFields($records, $excluded);
                
                // the error part here, detect if an array perhaps. for now, just give back what it hands you.
                //  our method leads to: Calculate::saveCalcFields   which leads to Records::saveData   (and I recall the errors was an array)
                // saveCalcFields returns, either, a string count, or, errors (and errors could potentially be an array?)
                // print_r($thisSavedCalc, true) 
                
                if (is_array($thisSavedCalc)) {
                    $resultMsg .= $this->msgCalcResults(null, null, 'ERRORS: There were errors in processing.  If problem persists then run the standard Rule H Data Quality process.');

                    // if diag mode
                    // display
                    if ($this->getSystemSetting('debug_view') || $this->getProjectSetting('debug_view_project') ) {
                        $resultMsg .= $this->msgCalcResults(null, null, 'ERRORS: ' . print_r($thisSavedCalc, true));
                        $processingMsgData['errors']  = print_r($thisSavedCalc, true);
                    }
                } else {
                    if ($thisSavedCalc == '0') {
                        // all done, no calcs to handle
                        $resultMsg .= $this->msgCalcResults(null, null, 'calculated fields DONE and NO MORE TO PROCESS');
                        $statusMsg .= 'Records DONE ' . print_r($thisSavedCalc, true);
                    } else {
                        // some number of calcs completed
                        $resultMsg .= $this->msgCalcResults(null, null, 'calculated fields processed: ' . print_r($thisSavedCalc, true));
                        $statusMsg .= 'Records DONE ' . print_r($thisSavedCalc, true);
                        $statusMsg .= ' Count of Records: [' . $countRecords . '] Processed count: [' . print_r($thisSavedCalc, true) . ']' . '' ;
                    }
                }
            }
        } else {
            $statusMsg = 'Count of records to process is ZERO.';
        }
        /*
            log date formats
            current:
            RHF 20240501134040 RULE H FILTERS PROGRESS PID: 36 Chunk: 28 of 28 Processed: 2772 of 2772
            proposed:
            Rule H: 2024-05-01 13:40 - PID: 36; Chunk: 28 / 28; Processed: 2772 / 2772
            
            
            current:
            RHF 20240501134040 FINISH RULE H FILTERS PID: 36
            proposed:
            Rule H: 2024-05-01 13:40 - PID: 36; Finished duration 1h20m
        
        */
        
        $dateProcessTimeFinish = $this->getProcessNowTime();
        
        $this->emlog($statusMsg, $projectId, 'RULE H Filters Process');
        $this->log($statusMsg);
//      $this->emlog('FINISH RULE H FILTERS PID: ' . $projectId, $projectId, 'RULE H Filters Process');
//        $this->emlog('- PID: ' . $projectId . '; FINISH duration', $projectId, 'RULE H Filters Process');
        
        $durMsgList = $this->processDurationMsg($this, false, $dateProcessTimeStart, $dateProcessTimeFinish);

        $this->emlog('- PID: ' . $projectId . '; FINISH duration ' . $durMsgList['duration'], $projectId, 'RULE H Filters Process');

        $processingMsgData['status']  = $statusMsg;
        $processingMsgData['results'] = $resultMsg;
        $processingMsgData['excludedrecords'] = $excluded;
        $processingMsgData['countExlusions']  = $countExlusions;
        
        return $processingMsgData;
    }   

    /**
     * msgCalcResults - message for reporting.
     */
    public function msgCalcResults($countChunk, $totalCountChunk, $msg) 
    {
        if ($countChunk != null) {
            return ' Results for (Chunk: '.$countChunk.' of '.$totalCountChunk.') ' . $msg;
        }
        return ' Results ' . $msg;      
    }   
            
    /**
     * saveCalcFields - do the actual calc fields processing.
     */
    public function saveCalcFields($records, $excluded) 
    {
        $this->valuesFixed = 0;
        
        if ($this->errorMsg) {
            unset($this->errorMsg);
        }
        
        $this->errorMsg = array();
        
        $thisSavedCalc = Calculate::saveCalcFields($records, array(), 'all', $excluded);
        
        // count number of values fixed
        if (is_numeric($thisSavedCalc)) {
            $this->valuesFixed += $thisSavedCalc;
        } else {
            $this->errorMsg[] = $thisSavedCalc;
        }
            
        return $thisSavedCalc;
    }   
        
    /**
     * processRecords - process records for the project.
     */
    public function processRecords($projectId, $records) 
    {       
        $str = '';
        $msg = '';

        $countRecords = $this->robustCount($records);
        
        $this->emlog('Records to run: ' . $countRecords, $projectId);
        
        // check records count before dropping into the process.
        if ($countRecords > 0) {
            
            // Check for any exclusions, and then feed in the list of records, and process the calc fields.
            
            $excluded = $this->exclusionList($projectId);
            
            $thisSavedCalc = Calculate::saveCalcFields($records, array(), 'all', $excluded);
            
            $this->emlog('Records DONE ' . $thisSavedCalc, $projectId);  //  number of calculations that were updated/saved
        
            // put some of your other config settings here

            $msg = '<br>Count of Records: [' . $countRecords . '] <br>Processed count: [' . $thisSavedCalc . ']' . '<br>' ;

            $this->emlog($msg, PROJECT_ID);
        } else {
            $this->emlog('NO Records to PROCESS', $projectId);
            $msg = 'Count of records to process is ZERO.';
        }
        
        return $msg;
    }   
        
    /**
     * runRuleH - .
     */
    public function TESTrunRuleH($projectId = null) 
    {
        if (!$projectId) {
            $projectId = (defined("PROJECT_ID") ? PROJECT_ID : 0);
            
            if ($projectId == 0) {
                // log not in a project
                // TODO: make the log call in TESTrunRuleH
                return;
            }
        }
        /*  NOT on an ON DEMAND call
        if ($this->isCronOff()) {  // main cron switch OFF
            // Do we log this?  or just be quiet?
            // TODO: log  "Cron RULE H is Turned OFF" in TESTrunRuleH
            return;
        }*/
        
        $records = $this->getListRecords($projectId, [4,5], 'dags');
        $msg = $this->processRecords($projectId, $records);
        
        return $msg;        
    }   
        
    /**
     * manualRunRuleH - .
     */
    public function manualRunRuleH() 
    {
        $projectId = $this->projectId;

        if (!$projectId) {
            $projectId = (defined("PROJECT_ID") ? PROJECT_ID : 0);
            
            if ($projectId == 0) {
                // log not in a project
                // TODO: make the log call in manualRunRuleH
                return;
            }
        }
        
        /* NOT on an ON DEMAND call
        if ($this->isCronOff()) {  // main cron switch OFF
            // Do we log this?  or just be quiet?
            // TODO: log  "Cron RULE H is Turned OFF" in manualRunRuleH
            return'CRON is Switched OFF';
        }
        */
        
        if ($projectId > 0 && $projectId != null) {
    
            $this->emlog('manualRunRuleH TEST', $projectId);
    
            $this->emlog('manualRunRuleH PROJECT_ID ' . PROJECT_ID, PROJECT_ID);
    
    //      $msg = $this->runRuleH($projectId);
            $msg = $this->TESTrunRuleH($projectId);
            $this->emlog(' ****** DONE manualRunRuleH PROJECT_ID ' . PROJECT_ID, PROJECT_ID);
        }
        
        return ('manualRunRuleH: [' . $projectId . ']' . $msg);     
    }

    /**
     * getDagsListIds - given a project ID get the DAG listing and return sorted list of DAG IDs (group_id) as a comma separated list string.
     */
    public function getDagsListIds($projectId, $asStr = true) 
    {
        $list = array();
        
        $dagsList = $this->getDagList($projectId);
        if ($dagsList) {
            foreach ($dagsList as $dagId => $dagName) {
                $list[] = $dagId;
            }
            sort($list);
        }
        
        if ($asStr == false) {
            return $list;
        }
        
        $strList = implode(',', $list);
        
        return $strList;
    }

    /**
     * getDateRangeDay - build a text string of previous day or week datetimestamp YYYYMMDDHHiiss as in 20220715000000.
     *  range is either past 7 days or past 24 hours
     *  used in getRecordsByTime
     */
    public function getDateRangeDay($range = 24) 
    {
        $dateStr = '';
        
        $endTimeStr = '000000';
        $year = date('Y');
        $month = date('m');
        $day = date('d');

        $now = new DateTime($year.$month.$day);

        switch ($range)
        {
            case 7:  // take now and do date math to subtract 7 days
                // now minus 7 days
                $now->sub(new DateInterval('P7D'));
                $dateStr = $now->format('Ymd') . $endTimeStr;
                break;
                
            //default:
            case 24:  // take now and do date math to subtract 24 hours
                // now minus 24 hours
                $now->sub(new DateInterval('PT24H'));
                $dateStr = $now->format('Ymd') . $endTimeStr;
                break;              

            default:
                break;              
        }

        return $dateStr;
    }

    /**
     * getLogTableByProjectId - get Redcap Log Event table used by a project.
     */
    public function getLogTableByProjectId($projectId) 
    {
        if (!is_numeric($projectId) || ($projectId == 0)) {
            return null;
        }

        $tableName = Logging::getLogEventTable($projectId);
        
        return $tableName;
    }
        
    /**
     * makeDagListHtml - make a DAG list into HTML selection listing.
     */
    public function makeDagListHtml($dagList) 
    {
        $html = '';
        $nl = "\n";
        $br = '<br>';

        if ($dagList) {
            $html .= '<div id="checklistdags" class="round chklist col-12">';

            foreach ($dagList as $dagId => $dagName) {
                $html .= '<input type="checkbox" id="'.$dagName.'" name="daglistnames" value="'.$dagId.'">';
                
                $html .= ' <label for="'.$dagName.'"> '.$dagName. ' ('.$dagId.')' . '</label>';
                $html .= $nl;
                $html .= $br;
            }
            $html .= '</div>';
        }
        
        return $html;
    }
    
    /**
     * giveButton - .
     */
    public function giveButton($button = self::BTN_LABEL_PROCESS) 
    {
        $html = '';
        
        switch ($button)
        {
            default:

            case 'processlist':
                $html .= '<input type="button" form="formbulklistadd" value="'.self::BTN_LABEL_PROCESS.'" onclick="jsProcessList(1)">'; // tag jsProcessList
                break;
                
            case 'previewlist':
                $html .= '<input type="button" form="formbulklistadd" value="'.self::BTN_LABEL_PREVIEW2.'" onclick="jsProcessList(0)">'; // tag jsProcessList PREVIEW ONLY
                break;

            case 'rewindlimit': // BTN_LABEL_REWIND
                $html .= '<input type="button" form="formbulklistadd" value="'.self::BTN_LABEL_REWIND.'" onclick="jsProcessList(0, 1)">'; // tag rewind the process list
                break;

        }

        return $html;
    }
            
    /**
     * makeMenuPage - make the menu page with choices for DAG and Time Frame.
     */
    public function makeMenuPage($projectId) 
    {
        $dagList = $this->getDagList($projectId);
        
        $strList = $this->getDagsListIds($projectId);

        $dagListHtml = $this->makeDagListHtml($dagList);

        $timeFrameHtml = $this->makeTimeFrameMenu();
        
        $html = '';
        
        // add the js code
        //
        $html .= '<script>';
        $html .= $this->getJsCode($this->projectId);
        $html .= '</script>';
        
        $html .= '<div id="rulehprocessingheader"><h1>Rule H Filters Processing Menu<h1></div>';
        $html .= '';
        $html .= '<hr>';
        $html .= '<h3>Choose Type (either by DAG groups and/or Time frame)</h3>';
        $html .= '<p>What to expect here is choice of DAGs and/or Time Frame will narrow down selection of RECORDS to be processed in the project.  Intention is use a smaller subset of records for the RULE H processing for a more manageable size which will be processed and not overburden the system.</p>';
        $html .= '<hr>';
        $html .= '<form>';
        $html .= '<div id="dagheader" class="chklisthdr">DAG Listing</div>';
        $html .= $dagListHtml;
        $html .= '<h3>AND / OR</h3>';
        $html .= '<div id"timeheader" class="chklisthdr">Time Frame</div>';
        $html .= $timeFrameHtml;

        $html .= '<div id="processbutton">';
        $html .= '<hr>';
        $html .= $this->giveButton();
        $html .= '</div>';
        $html .= '<div id="previewbutton">';
        $html .= '<hr>';
        $html .= $this->giveButton('previewlist');
        $html .= '</div>';

        $html .= '<div id="rewindbutton">';
        $html .= '<hr>';
        $html .= $this->giveButton('rewindlimit');
        $html .= '</div>';

        $html .= '<div id="resultsdata">';
        $html .= '</div>';

        $html .= '</form>';
        
        return $html;
    }
        
    /**
     * makeTimeFrameMenu - make time frame html.
     */
    public function makeTimeFrameMenu() 
    {
        $html = '';

        $html .= '';
        $html .= '<div id="radiotimelist" class="round chklist col-12">';
        $html .= '<input type="radio" id="past7days" name="timeframechoice" value="7">';
        $html .= '<label for="past7days"> &nbsp;Past 7 Days</label><br>';
        $html .= '<input type="radio" id="past24hrs" name="timeframechoice" value="24">';
        $html .= '<label for="past24hrs"> &nbsp;Past 24 Hours</label><br>';
        
        $html .= '<input type="button" id="clearradios" value="Reset Time Frame" name="clearradios" onclick="$(\'#past7days\').prop(\'checked\',false); $(\'#past24hrs\').prop(\'checked\',false);">';
        $html .= '</div>';
        
        return $html;
    }
        
    /**
     * getJsCode - the js code we need.
     */
    public function getJsCode($projectId = 0) 
    {
        $js = '';
        $nl = "\n";
        $brnl = '<br>' . $nl;
        $br = '<br>' . $nl;
        $hr = '<hr>' . $nl;
        $js .= '';

        $page = self::API_PROCESS_RECORDS;
        $pathToAjaxFunction = $this->getUrl($page, false, false);
        
        // jsProcessList - gets the checkbox values and or the radio button values and then ...
        //
        $js .= 'function jsProcessList(paramarg, paramrewind) {';

        $js .= 'var d_projectid = "'.$projectId.'";';
        $js .= $nl;

        // demoformdagslist
        $js .= 'var dataitemsDags = $(\'[name="demoformdagslist"]\').val().toString();';  // numbers need an explicit to string conversion
        
        $js .= 'var dataitemsForms = $(\'[name="demoformformlist"]\').val().toString();';

        $js .= 'var dataitemsEvents = $(\'[name="demoformeventlist"]\').val().toString();';

        $js .= 'var dataitems_email = $(\'[name="email_to_user"]\').val().toString();';  // Email to user when done

        $js .= '  var flagprocess = (paramarg == 1 ? 1 : 0);';  // 1 = process, 0 = view
        $js .= '  var flagrewind = (paramrewind == 1 ? 1 : 0);';  // 1 = process, 0 = view
        $js .= '  var strdaglist = "";';
        $js .= '  var strtimeframe = "0";';

        $js .= '  strdaglist = getDagListingsChecked();';

        $js .= '  strtimeframe = getTimeFrameListingsChecked();';
        
        $js .= '  var strmsg = "";';

        $js .= "    var pathToAjaxFunctionUrl = '{$pathToAjaxFunction}';";
        
        // actionspinner
        $js .= '  actionSpinnerOn();';

        $js .= "    $.ajax({";
        $js .= $nl;
        $js .= "        url: pathToAjaxFunctionUrl,";
        $js .= $nl;
        $js .= '        type: "POST",';
        $js .= $nl;
        
        $js .= '        data: { projectId: d_projectid, dagslist: dataitemsDags, timelist: strtimeframe, formslist: dataitemsForms, eventslist: dataitemsEvents, flagprocess: flagprocess, flagrewind: flagrewind, emailuser: dataitems_email}';
        $js .= $nl;
        
        $js .= "     ";
        $js .= $nl;
        $js .= "    })  ";
        $js .= $nl;
        
        // **** AJAX DONE (success) ****
        $js .= "        .done(function(result) {";
        $js .= $nl;

        //      resultsdata
        $js .= '$("#resultsdata").html(strmsg + result);';

        $js .= '  actionSpinnerOFF();';

        $js .= $nl;

        $js .= "        })";
        $js .= $nl;

        $js .= $nl;

        // **** AJAX FAIL (error) ****
        $js .= "        .fail(function(result) {";
        $js .= $nl;

        //$js .= 'alert("FINISHED FAIL!");';

        $js .= '$("#resultsdata").html("FINISHED FAIL! " + result);';

        $js .= '  actionSpinnerOFF();';
        $js .= $nl;

        $js .= "        })";
        $js .= $nl;

        // **** AJAX ALWAYS (complete) ****
        $js .= "        .always( function(result) {";
        $js .= $nl;
        $js .= $nl;
        $js .= '  actionSpinnerOFF();';
        
        $js .= "    }); ";
        $js .= $nl;

        $js .= $nl;
        $js .= $nl;
        
        $js .= '};';
                    
        // getDagListingsChecked - gets the checkbox values that are selected, and makes a sorted string list comma separated.   ex:  4,5,7,8
        $js .= 'function getDagListingsChecked() {';

        $js .= '  var checkeddaglist = [];';
        $js .= '  var strdaglist = "";';
        $js .= '  $.each($("input[name=\'daglistnames\']:checked"), function(){checkeddaglist.push($(this).val());});';
        $js .= '  checkeddaglist.sort();';
        $js .= '  strdaglist = checkeddaglist.join(",");';
        $js .= '  return strdaglist;';
        
        $js .= '};';
        
        // getTimeFrameListingsChecked - get the radio button choice value: 7,24 default:0   
        $js .= 'function getTimeFrameListingsChecked() {';

        $js .= '  var numreturn = "0";';
        $js .= '  var strdaglist = "";';
        $js .= '  var ch7day = $("#past7days:checked").val();';
        $js .= '  var ch24hr = $("#past24hrs:checked").val();';
        $js .= '  numreturn = (ch7day == 7 ? "7" : (ch24hr == 24 ? "24" : "0") );';
        $js .= '  return numreturn;';
        
        $js .= '};';


        $js .= 'function actionSpinnerOn() {';

        $js .= '  ';
        $js .= '$("#actionspinner").show();';
        
        $js .= '};';

        $js .= 'function actionSpinnerOFF() {';

        $js .= '  ';
        $js .= '$("#actionspinner").hide();';
        
        $js .= '};';

        return $js;
    }
                    
    /**
     * getDagList - get list of DAGs for project.  
     *   returns (sorted by name) Array of DAGs key DagGroupId  value DagName 
     *     ARRAY: [group_id] = group_name
     *   see table: redcap_data_access_groups  fields: group_id, group_name, project_id
     */
    public function getDagList($projectId = 0) 
    {
        if ($projectId == 0) {
            return null;
        }

        global $Proj;  // globals... (rolls eyes)
        
        // direct SQL:
        // select group_id, group_name from redcap_data_access_groups where project_id = ? order by group_id
        
        $groups = $Proj->getGroups();  // $sql = "select * from redcap_data_access_groups where project_id = " . $this->project_id . " order by trim(group_name)";
        
        asort($groups);
        
        return $groups;
    }

    /**
     * getRecentLogTimeStampList - find what start and finish time stamps are to use elsewhere.
     */
    public function getRecentLogTimeStampList($projectId)
    {
        $timeList = array();
        
        $table = $this->getLogTableByProjectId($projectId);  // TODO: all these (getLogTableByProjectId) might want to stick this into some, one and done call.  load it upon init constructor perhaps.
        
        $sql = 'SELECT log_event_id, ts, data_values FROM '.$table.' WHERE event IN ("UPDATE","INSERT","OTHER") AND project_id = ? AND data_values like "%'.$this->markerHeader.'%START%" OR data_values like "%'.$this->markerHeader.'%FINISH%" ORDER BY log_event_id DESC LIMIT 2;';
        // Question, would the DESC and Limit 2, give us the TWO MOST RECENT PAIR?
        
        $queryResult = $this->sqlQueryAndExecute($sql, [$projectId]);
        
        // so we can default to most recent time.  if nothing else.  and given what we found, overwrite it.
        $hour = date('H');
        $min  = date('i');

        $now = date('Ymd') . $hour . $min . '00';
        // 20220909112904
        $timeList['start']  = $now;  // trick. prefill with now.  maybe tick back a minute?
        $now = date('Ymd') . $hour . ($min + 1) . '00';
        $timeList['finish'] = $now;
        
        if ($queryResult) {
            while ($row = $queryResult->fetch_assoc()) {
                $statusStartFlag = (str_contains($row['data_values'], 'START') == true ? true : false);
                $statusFinishFlag = (str_contains($row['data_values'], 'FINISH') == true ? true : false);
                if ($statusStartFlag) {
                    $timeList['start'] = $row['ts'];
                }
                else if ($statusFinishFlag) {
                    $timeList['finish'] = $row['ts'];
                } else {
                    $timeList['NA'] = $row['ts'];                   
                }
            }
        }
        // ideally, we want TWO values (ONE SET), a pair of Start and Finish.  
        //  in practice, you could have many many.  so, how to find recent we want...?
        
        return $timeList;
    }
                
    /**
     * getListOfTouchedFields - find what records and fields were RULE H processed for our project recently.
     */
    public function getListOfTouchedFields($projectId)
    {
        $calcList = array();
        
        $table = $this->getLogTableByProjectId($projectId);
        
        $timesList = $this->getRecentLogTimeStampList($projectId);
        
        //$tsStart = '20220908000000';
        //$tsEnd   = '20220910000000';
        $tsStart = $timesList['start'];  // TODO: check if has value,  if not, use now perhaps.  or just skip out?
        $tsEnd   = $timesList['finish']; // TODO: ditto
        
        $sql = 'SELECT pk, data_values, ts, log_event_id, event, event_id, description FROM '.$table.' WHERE event IN ("UPDATE","INSERT","OTHER") AND project_id = ? AND ts >= ? AND ts <= ? AND description  like "%(Auto calculation)" ORDER BY ts';
        
        $queryResult = $this->sqlQueryAndExecute($sql, [$projectId, $tsStart, $tsEnd]); 
        
        if ($queryResult) {
            while ($row = $queryResult->fetch_assoc()) {
                $dataValuesList = explode(',', $row['data_values']);
                $dvList = array();
                foreach($dataValuesList as $dvKey => $dvVal) {
                    $dvList[] = explode(' = ', $dvVal)[0];
                }
                $calcList[] = array('recordID' => $row['pk'], 'calcfield' => $dvList );
            }
        }
        
        return $calcList;
    }

    /**
     * getRecordsByDag - given project ID and string list of DAG IDs.
     */
    public function getRecordsByDag($projectId, $param, $flagProcess = false) 
    {
        $tallyCountProcess = 0;
        $tallyCountPreview = 0;
        
        // $flagProcess = Flag to PROCESS or PREVIEW ONLY  TRUE = Process  FALSE = Preview
        //
        // we either, get ALL the data, or a PORTION of data, a page at a time
        //
        
        $valLimit = $this->getProjectSetting('val_limit');
        $this->flag_turbo = $this->getProjectSetting('flag_turbo');
        
        // *** get ALL data ***
        if ($valLimit == 0) {  
            $totalCount = Records::getRecordCountForDags($projectId, $param);
            $this->totalCountOfRecords = $totalCount;
            
            $countList = $totalCount;

            if ($flagProcess) {
                $tallyCountProcess += $countList;
                $this->setProjectSetting('tally_count_process', $tallyCountProcess);
                $this->runningCount = $tallyCountProcess;
            } else {
                $tallyCountPreview += $countList;
                $this->setProjectSetting('tally_count_preview', $tallyCountPreview);
                $this->runningCount = $tallyCountPreview;
            }

            if ($this->flag_turbo) {
                $this->log('DAGs TURBO');
                return  $this->pullRecordsListByDag($projectId, $param);
                
            } else {
                $this->log('DAGs NORMAL');
                return Records::getRecordList($projectId, $param);
            }
        }        
        
        $valLimitOffset = 0;

        // *** get a PORTION or page of data ***
        if ($flagProcess) {  // PROCESS
            $staticLimitOffsetProcess = $this->getProjectSetting('static_limit_offset_process');
            $valLimitOffset = $staticLimitOffsetProcess;
            $staticLimitOffsetProcess += $valLimit;
        } else {             // PREVIEW
            $staticLimitOffsetPreview = $this->getProjectSetting('static_limit_offset_preview');
            $valLimitOffset = $staticLimitOffsetPreview;
            $staticLimitOffsetPreview += $valLimit;            
        }

        // bump the offset and keep track of it in a static fashion
        if ($flagProcess) {
            $this->setProjectSetting('static_limit_offset_process', $staticLimitOffsetProcess);
        } else {
            $this->setProjectSetting('static_limit_offset_preview', $staticLimitOffsetPreview);
        }

        $totalCount = Records::getRecordCountForDags($projectId, $param);
        $this->totalCountOfRecords = $totalCount;
        
        if ($flagProcess) {
            $tallyCountProcess = $this->getProjectSetting('tally_count_process');
        } else {
            $tallyCountPreview = $this->getProjectSetting('tally_count_preview');
        }
        
        // get up to limit amount of records
        if ($this->flag_turbo) {
            $listOfRecords = $this->pullRecordsListByDag($projectId, $param, $valLimit, $valLimitOffset);
            $this->log('DAGs TURBO');
        } else {
            $listOfRecords = Records::getRecordList($projectId, $param, false, false, null, $valLimit, $valLimitOffset);            
            $this->log('DAGs NORMAL');
        }

        $countList = $this->robustCount($listOfRecords);

        // reset it. we need to know when to reset, either run out of records, or starting over
        if($countList == 0) {
            $tallyCountProcess = 0;
            $tallyCountPreview = 0;
            // this will reset or rewind the paging pointer marker to the beginning            
            if ($flagProcess) {
                $this->setProjectSetting('static_limit_offset_process', 0);
                $this->setProjectSetting('tally_count_process', 0);
            } else {
                $this->setProjectSetting('static_limit_offset_preview', 0);
                $this->setProjectSetting('tally_count_preview', 0);
            }
        }

        if ($flagProcess) {
            $tallyCountProcess += $countList;
            $this->setProjectSetting('tally_count_process', $tallyCountProcess);
            $this->runningCount = $tallyCountProcess;
        } else {
            $tallyCountPreview += $countList;
            $this->setProjectSetting('tally_count_preview', $tallyCountPreview);
            $this->runningCount = $tallyCountPreview;
        }
        
        return $listOfRecords;
    }
        
    /**
     * getRecordsByTime - get records by time frame factor.
     */
    public function getRecordsByTime($projectId, $param) 
    {
        $records = null;

        if (!is_numeric($projectId) || ($projectId == 0)) {
            return null;//'NOT a valid project ID';
        }
        
        $table = $this->getLogTableByProjectId($projectId);
        
        if ($table == null) {
            return null;//'NOT a valid project log table';
        }

        $sql = 'SELECT DISTINCT(pk) AS primekey FROM '.$table.' WHERE ts > ? and event IN ("UPDATE","INSERT") AND project_id = ? AND description not like "%(Auto calculation)" AND data_values not like "user =%" ORDER BY pk * 1';
        
        $datestr = $this->getDateRangeDay($param);

        if ($datestr) {
            $queryResult = $this->sqlQueryAndExecute($sql, [$datestr, $projectId]); 
    
            while ($row = $queryResult->fetch_assoc()) {
                $records[$row['primekey']] = htmlspecialchars($row['primekey'],ENT_QUOTES,'UTF-8');  // address Checkmarx games.
            }
        }

        return $records;
    }
      
    /**
     * getListRecords - get list of records to use. May be by group of DAGs or Time Frame or Forms or Events.
     */
    public function getListRecords($projectId, $param, $type, $flagProcess = false) 
    {
        // $flagProcess = Flag to PROCESS or PREVIEW ONLY  TRUE = Process  FALSE = Preview
        $records = null;
        
        global $Proj;
        
        // get records by DAG  or get records by time frame or Form ors Events
        // call get record  by type  with params
        
        // get records by DAG is this:
        // 
        switch ($type)
        {
            case 'dags':
                $now = $this->getStrNowTime();
                $this->log('DAGS TIME START: ' . $now);

                if (is_array($param)) {
                    $records = $this->getRecordsByDag($projectId, $param, $flagProcess);
                } else {
                    $records = $this->getRecordsByDag($projectId, explode(',', $param), $flagProcess);
                }
                
                if ($records === false) {
                    // log error
                    $records = null;
                } else {
                    natcaseksort($records);  // Order records (natural case KEY sort)
                }

                $now = $this->getStrNowTime();
                $this->log('DAGS TIME END: ' . $now);
                break;
                
            case 'time':
                $this->flag_turbo = $this->getProjectSetting('flag_turbo');
                $now = $this->getStrNowTime();
                $this->log('TFRAME TIME START: ' . $now);
                
                if ($this->flag_turbo) {
                    if ($param == '24' || $param == '7' || $param == 'all') {  // TODO: add all feature
                        switch ($param) {
                            case '24':   // 24 hours or past 1 day
                                $records = $this->pullRecordsListByTime($projectId, 1);
                                $this->log('Use Past 24 hours');
                                break;
                            case '7':   // past 7 days
                                $records = $this->pullRecordsListByTime($projectId, 7);
                                $this->log('Use Past 7 days');
                                break;
                            case 'all':   // past 5 years
                                $records = $this->pullRecordsListByTime($projectId, 365*5);
                                $this->log('Use Past ALL days');
                                break;
                            default:
                                $this->log('Use Past nothing');
                                break;
                        }
                        
                        if ($records === false) {
                            // log error
                            $records = null;
                        }
                    }                    
                } else {
                    if ($param == '24' || $param == '7') {
                        $records = $this->getRecordsByTime($projectId, $param);
                    }                    
                }

                $now = $this->getStrNowTime();
                $this->log('TFRAME TIME END: ' . $now);

                break;
                
            case 'all':
                $records = Records::getRecordList($projectId);
                break;

            case 'form': {  // trick with the brace, to collapse in the editor this case block.
                // ** CHANGE ADDING HERE **/
                $this->flag_turbo = $this->getProjectSetting('flag_turbo');
                
                if ($this->flag_turbo) {
                    $records = null;
                    break;
                }
                // ** CHANGE ADDING HERE **/
                                
                $param = explode(',', $param);  // make string of comma separated list into an array                

                // arrays are too complicated. Rob Taylor (even Rob struggles with repeating array structures), suggests, flatten it, using the json format option (which is not an array turned into json, the Records getData actually processes and flattens the array, THEN builds the json data).
                $recordsListJson = REDCap::getData($projectId, 'json', null, null, $param);  // okay, so trick here, is, pull the data as JSON format, and flatten the structure.  array gets complex with repeating. and we really just need to see some specific things.
                $recordsList = json_decode($recordsListJson);

                $formnames = $param;

                $givenEventIdList = $this->getEventListData($projectId);  // dynamically get event ID list
                
                $recordIdFieldName = REDCap::getRecordIdField();  // oh yeah, record_id can be changed to any other name at a whim.  so we need to know that later to get the actual RECORD ID

                // ** CHANGE REMOVING HERE **/
                
                //$hasRepeatingFormsEvents   = $Proj->hasRepeatingFormsEvents();  // true = has repeating forms events; false = not repeating
                //$hasRepeatingForms         = $Proj->hasRepeatingForms();
                //$hasRepeatingEvents        = $Proj->hasRepeatingEvents();

                // FIND ALL THE RECORDS for a FORM (and needs to know, Event IDs, to do it)
                //    because the data is like this:
                //   array()
                //      [event id]
                //         [fields] (and one of these fields MAY be the "form_name_we_want" + "_complete") (and if found, we want the field "record_id")
                //    if the  [][nnnEventID][form_name_we_want_complete] exists, 
                //    then get the [][nnnEventID][record_id] 
                //
                // if you have say 50 forms, 20 events.  and 10,000 records....  this might take awhile to process.
                //
                $records = $this->gatherRecordsListPlainJsonVariant($formnames, $recordsList, $givenEventIdList, $recordIdFieldName);
                
                natcaseksort($records);  // Order records (natural case KEY sort)
                }
                break;

            case 'event': {
                // almost a rehash of forms above, with twist, we grab ALL the forms names
                //
                $param = explode(',', $param);  // make string of comma separated list into an array
                
                if (!isset($param)) {
                    return $records;                    
                }

                if ($this->robustCount($param) == 0) {
                    return $records;
                }
                if ( ($this->robustCount($param) == 1) && (strlen($param[0]) == 0) ) {
                    return $records;
                }

                $recordsListJson = REDCap::getData($projectId, 'json', null, null, $param);
                $recordsList = json_decode($recordsListJson);

                $recordIdFieldName = REDCap::getRecordIdField();  // oh yeah, record_id can be changed to any other name at a whim.  so we need to know that later to get the actual RECORD ID

                $hasRepeatingFormsEvents = $Proj->hasRepeatingFormsEvents();  // true = has repeating forms events; false = not repeating

                $records = $this->gatherRecordsListPlainJsonVariant($formnames, $recordsList, $givenEventIdList, $recordIdFieldName);

                // get list of all the forms
                $formnamesList = $this->getFormsList($projectId);
                $formnames = array();
                foreach ($formnamesList as $formname => $forminfo) {
                    $formnames[] = $formname;
                }

                $givenEventIdList = $this->getEventListData($projectId);  // dynamically get event ID list
    
                // TODO: I believe the set of loops are inefficient.  This may need improvement.
                //

                $records = $this->gatherRecordsListPlainJsonVariant($formnames, $recordsList, $givenEventIdList, $recordIdFieldName);
                
                natcaseksort($records);  // Order records (natural case KEY sort)
              } 
              break;
                         
            default:
                return null;
                break;
        }
        
        // NOTE: also see Records::getRecordListAllDags  
        
        return $records;
    }

    // FIND ALL THE RECORDS for a EVENT (and needs to know, Event IDs, to do it)
    //    because the data is like this:
    //   array()
    //      [event id]
    //         [fields] (and one of these fields MAY be the "form_name_we_want" + "_complete") (and if found, we want the field "record_id")
    //    if the  [][nnnEventID][form_name_we_want_complete] exists, 
    //    then get the [][nnnEventID][record_id] 
    //
    // if you have say 50 forms, 20 events.  and 10,000 records....  this might take awhile to process.
    //

    /**
     * gatherRecordsListPlain - gather a record ID list, given our forms and events. no fuss with REPEATING array layers. plain get records list (as in, no repeating events or instances or instruments).
     *  - the trick: use getData, but as 'json' format, which can flatten the arrays for us... you have one array level to loop through, then the structure ends up simple.
     */
    public function gatherRecordsListPlainJsonVariant($formnames, $recordsListJson, $givenEventIdList, $recordIdFieldName) 
    {
        $records = null;
                
        foreach ($formnames as $key => $formname) {
            foreach ($recordsListJson as $key => $item) {
                $fieldNameToUse = $formname . '_complete';
                
                if (isset($item->$fieldNameToUse)) {
                    $recordID = $item->$recordIdFieldName;
                }
                    
                if ($recordID) {
                    $records[$recordID] = $recordID;
                }
                
                $recordID = false;
            }
        }
        
        return $records;
    }
    
    /**
     * gatherRecordsListPlain - gather a record ID list, given our forms and events. no fuss with REPEATING array layers. plain get records list (as in, no repeating events or instances or instruments).
     */
    public function gatherRecordsListPlain($formnames, $recordsList, $givenEventIdList, $recordIdFieldName) 
    {
        $records = null;
        
        // 
        foreach ($formnames as $key => $formname) {
            foreach ($recordsList as $key => $oneRecord) {
                foreach ($givenEventIdList as $eventId => $eventNameId) {

                    if (isset($oneRecord[$eventId][$formname . '_complete'])) {
                        $recordID = $oneRecord[$eventId][$recordIdFieldName];
                        
                        if ($recordID) {
                            $records[$recordID] = $recordID;
                        }
                    }
                    
                }
            }
        }
        
        return $records;
    }

    /**
     * gatherRecordsListRepeatingWhat - gather a record ID list, given our forms and events. no fuss with REPEATING array layers. plain get records list (as in, no repeating events or instances or instruments).
     */
    public function gatherRecordsListRepeatingWhat($formnames, $recordsList, $givenEventIdList, $recordIdFieldName) 
    {
        $records = null;
        
        foreach ($formnames as $formname) {
            foreach ($recordsList as $oneRecord) {
                foreach ($givenEventIdList as $eventId => $eventNameId) {
        
                    // Skip if neither normal nor repeating instance exists
                    if (!isset($oneRecord[$eventId]) && !isset($oneRecord['repeat_instances'][$eventId])) {
                        continue;
                    }
        
                    // Process regular records
                    if (isset($oneRecord[$eventId][$formname . '_complete'])) {
                        $recordID = $oneRecord[$eventId][$recordIdFieldName] ?? null;
                        if ($recordID) {
                            $records[$recordID] = $recordID;
                        }
                    }
        
                    // Process repeating instances
                    if (!isset($oneRecord['repeat_instances'][$eventId])) {
                        continue;
                    }
        
                    $repeatInstances = &$oneRecord['repeat_instances'][$eventId]; // Reference, no copy
                    $countRepeatInstances = $this->robustCount($repeatInstances[null] ?? []);
        
                    for ($repeat_instance = 1; $repeat_instance <= $countRepeatInstances; $repeat_instance++) {
                        $recordID = $repeatInstances[null][$repeat_instance][$recordIdFieldName] ?? null;
                        if ($recordID && isset($repeatInstances[null][$repeat_instance][$formname . '_complete'])) {
                            $records[$recordID] = $recordID;
                        }
                    }
                }
            }
        }

        return $records;
    }


    /**
     * gatherRecordsListRepeatingWhat - gather a record ID list, given our forms and events. no fuss with REPEATING array layers. plain get records list (as in, no repeating events or instances or instruments).
     */
    public function OLD_TWO_gatherRecordsListRepeatingWhat($formnames, $recordsList, $givenEventIdList, $recordIdFieldName) 
    {
        $records = null;

        foreach ($formnames as $key => $formname) {
            foreach ($recordsList as $key => $oneRecord) {
                foreach ($givenEventIdList as $eventId => $eventNameId) {
                    if (isset($oneRecord[$eventId]) ) {  // repeating forms                                 
                                        
                        if (isset($oneRecord[$eventId][$formname . '_complete'])) {
                            $recordID = $oneRecord[$eventId][$recordIdFieldName];
                            
                            if ($recordID) {
                                $records[$recordID] = $recordID;
                            }
                        }
                    }

                    if (isset($oneRecord['repeat_instances'][$eventId]) ) {  // repeating instances
                        $countFieldInstruments = $this->robustCount($oneRecord['repeat_instances'][$eventId]);
                        $countRepeatInstances = $this->robustCount($oneRecord['repeat_instances'][$eventId][null]);
                        
                        $glob = $oneRecord['repeat_instances'][$eventId];

                        foreach($glob as $globKey => $globElement) {
                            foreach($globElement as $globElementKey => $globElementElement) {
                                foreach($globElementElement as $fieldNameIs => $gElement) {
                                    $repeat_instance = 1; // we can always expect 1 at least in this case...?
                                    $countRepeatInstances = $this->robustCount($oneRecord['repeat_instances'][$eventId][null]);

                        // TODO: also, terminolgy probably needs fixing.  is it instances, events, instruments...?
                        //
                        $countFieldInstruments = $this->robustCount($oneRecord['repeat_instances'][$eventId]);  // TODO: what if this is > 1 ?   if it is 1, then [null]  but when > 1 then [null],[2],[3[...
                        $countRepeatInstances = $this->robustCount($oneRecord['repeat_instances'][$eventId][null]);

                                // TODO: if ($countFieldInstruments > 1)  
                                // $oneRecord['repeat_instances'][$eventId] > 1 
                                // then we need to account for $oneRecord['repeat_instances'][$eventId][null]  $oneRecord['repeat_instances'][$eventId][2], $oneRecord['repeat_instances'][$eventId][number]...
                                    
                                    for ($repeat_instance = 1; $repeat_instance <= $countRepeatInstances; $repeat_instance++) {
                                        $recordID = null;
                                        if (isset($oneRecord['repeat_instances'][$eventId][null][$repeat_instance][$recordIdFieldName])) {
                                            $recordID = $oneRecord['repeat_instances'][$eventId][null][$repeat_instance][$recordIdFieldName]; // Record ID normally as 'record_id' however, the name is changeable.
                                        }

                                        if ($fieldNameIs == $formname . '_complete') {
                                            if ($recordID) {
                                                $records[$recordID] = $recordID;
                                            }
                                        }
                                    }                                               
                                }
                            }
                        }
                    }
                }
            }
        }

        return $records;
    }

    /**
     * exclusionList - get Exclusion list of calc fields that are marked as excluded.
     */
    public function exclusionList($projectId = 0) 
    {
        if ($projectId == 0) {
            return null;
        }

        $excluded = array();
        global $Proj, $longitudinal;

        $hasRepeatingFormsEvents = $Proj->hasRepeatingFormsEvents();

        // NOTE: rule H is case 'pd-10':  and thus "10" used here.  A hard coded value you say, well, REDCap has it cast in code stone pervasively this way, so we can rely on this value being fixed.
        $sql = 'SELECT record, event_id, field_name, instance FROM redcap_data_quality_status WHERE pd_rule_id = 10 AND project_id = ? AND exclude = 1';
        
        $exclusions = $this->sqlQueryAndExecute($sql, [$projectId]);        
        
        // NOTE: this is extracted out of Classes/DataQuality.php  method: executePredefinedRule
        while ($row = $exclusions->fetch_assoc())
        {
            // Figure out all the repeating details
            // Repeating forms/events
            $isRepeatEvent       = ($hasRepeatingFormsEvents && $Proj->isRepeatingEvent($row['event_id']));
            $isRepeatForm        = $isRepeatEvent ? false : ($hasRepeatingFormsEvents && $Proj->isRepeatingForm($row['event_id'], $Proj->metadata[$row['field_name']]['form_name']));
            $isRepeatEventOrForm = ($isRepeatEvent || $isRepeatForm);
            
            $repeat_instrument = ($isRepeatForm ? $Proj->metadata[$row['field_name']]['form_name'] : '');
            $instance          = ($isRepeatEventOrForm ? $row['instance'] : 0);
            
            // Add to excluded array
            $excluded[$row['record']][$row['event_id']][$repeat_instrument][$instance][$row['field_name']] = true;
        }         
        
        return $excluded;
    }

    /**
     * showExclusionList - display Calc Exclusion list.
     */
    public function showExclusionList($projectId) 
    {
        $exclusions = $this->exclusionList($projectId);
        
        $str = print_r($exclusions, true);
        
        $this->viewHtml($str, self::VIEW_CMD_PROJECT);
        
        return $str;
    }

    /**
    * sqlQueryAndExecute - encapsulate some of the repeative details.  pass one param or many params.
    */
    private function sqlQueryAndExecute($sql, $params = [])
    {
        $queryResult = null;
        
        try {
            $query = $this->createQuery();
            
            $this->queryHandle = $query;
            
            if ($params == null) {
                $params = [];
            }

            if ($query === false) {
                $error = db_error();
                $this->log('ERROR: ' . $sql . ' err:' . $error);

                return false;
            }
            
            $query->add($sql, $params);
            
            $queryResult = $query->execute();
            
            return $queryResult;

        } catch (Throwable $e) {
            $this->log('ERROR: ' . $sql . ' err:' . $e->__toString());
        }
        
        return false;
    }

    /**
     * getEventListData - the event names.
     */
    public function getEventListData($projectId = 0) 
    {
        if (!defined('PROJECT_ID')) {  // not in a project context
            return null;
        }       

        global $Proj;
        //$Proj = new Project($projectId);  // simpler to use the global, but do we really want to. something to consider.
        
        $events = $Proj->getUniqueEventNames();

        $this->eventsList = $events;
        
        return $events;
    }
    
    /**
     * getProjectInfo - the event names. TODO: maybe refactor the name (or remove, not used?). 
     */
    public function getProjectInfo($projectId = 0) 
    {
        if (!defined('PROJECT_ID')) {  // not in a project context
            return null;
        }       

        global $Proj;
        
        $events = $Proj->getUniqueEventNames();
        
        $this->eventsList = $events;
        
        return $events;
    }
        
    /**
     * getFlagHasRepeatingFormsEvents - give us the flag state for repeating forms and events.
     */
    public function getFlagHasRepeatingFormsEvents($projectId = 0) 
    {
        if (!defined('PROJECT_ID')) {  // not in a project context
            return null;
        }       

        global $Proj;
        
        $hasRepeatingFormsEvents = $Proj->hasRepeatingFormsEvents();
                
        return $hasRepeatingFormsEvents;
    }
    
    /**
     * getFormsList - record gathering by Forms List.
     */
    public function getFormsList($projectId = 0) 
    {
        if (!defined('PROJECT_ID')) {  // not in a project context
            return null;
        }       
        
        global $Proj;
        
        $formsFields = array();
        
        $formsList = $Proj->forms;
        $formsNamesList = array();
        
        // [form_number] => 69 [menu] => AE/PD Report
        foreach ($formsList as $key => $val) {
            $formsNamesList[$key] = array('formNumber' => $val['form_number'], 'formName' => $val['menu'], 'keyFormName' => $key);
            
            $formFldsList = $val['fields'];
            
            if (isset($formFldsList)) {             
                foreach ($formFldsList as $fldKey => $fldVals) {
                    $formsFields[$key][] =  $fldKey;
                }
            }
        }
        
        $this->formsFields = $formsFields;
        $this->formsNamesList = $formsNamesList;
                
        return $formsNamesList;
    }

    /**
     * getRecordsByFormsFldsList - record gathering by Forms Fields list.
     */
    public function getRecordsByFormsFldsList($fldsList) 
    {
        if (!defined('PROJECT_ID')) {  // not in a project context
            return null;
        }       

        $recordsList = array();
        // use getData
        // now call the getData ... grab by form 
        $recordsList = REDCap::getData(PROJECT_ID, 'array', null, $fldsList);
        
        return $recordsList;
    }

    /**
     * eventListClean - check the given list, against the projects list of events and remove any items that do not exist, return the clean list of actual events that are present and requested.
     */
    public function eventListClean($eventsList) 
    {
        $cleanedEventList = array();
    
        if ($this->eventsList == null) {
            return null;
        }
            
        $projectEventsList = $this->eventsList;
        
        if (!is_array($eventsList)) {
            $eventsList = [$eventsList];
        }
        
        if ($this->robustCount($eventsList) > 0 && ($this->robustCount($projectEventsList) > 0)) {
            foreach ($eventsList as $eventsListkey => $eventVar) {
                foreach ($projectEventsList as $projectEventsKey => $projectsEventVar) {
                    if ($eventVar == $projectsEventVar) {
                        $cleanedEventList[] = $eventVar;
                    } else {
                    }
                }
            }
        }
        
        return $cleanedEventList;
    }
    
    /**
     * getRecordsByEventsList - record gathering by Events.
     */
    public function getRecordsByEventsList($eventsList) 
    {
        if (!defined('PROJECT_ID')) {  // not in a project context
            return null;
        }       
        
        if ($eventsList == null) {
            return null;
        }
        
        $eventsList = $this->eventListClean($eventsList); // make sure events exist
        
        if ($this->robustCount($eventsList) == 0) {
            return null;
        }

        $recordsList = array();
        // use getData
        // now call the getData ... grab by form 
        $recordsList = REDCap::getData($this->projectId, 'array', null, null, $eventsList);
        
        return $recordsList;
    }
    
    /**
     * getFormsFieldList - given a form name key (the lower cased underscored name of a Form), return the field list for that form.
     */
    public function getFormsFieldList($formNameKey) 
    {
        $flag = false;
        $fieldList = array();
        
        if ($this->robustCount($this->formsFields) > 0) {
            $flag = true;
        }
        
        if ($flag) {
            if (isset($this->formsFields[$formNameKey])) {
                $fieldList = $this->formsFields[$formNameKey];
            }
        }
        
        return $fieldList;
    }

    /**
     * getRecordsByEvents - test.
     */
    public function getRecordsByEvents($projectId, $eventsList) 
    {
        $dataList = array();

        return $dataList;
    }
    
    /**
     * getRecordsByFormKey - given a form variable name (the lower cased underscored name of a Form), return the list of records for that. also the list of fields that form uses.
     */
    public function getRecordsByFormKey($formNameKey) 
    {
        $recordsList = array();
        $fldsList = array();
        
        
        if ($this->formsNamesList == null) {  // if we do not have the list, build it, so we can use it.
            $this->getFormsList();
        }

        $this->fldsList = $this->getFormsFieldList($formNameKey);
        
        $recordsList = $this->recordsListByForm = $this->getRecordsByFormsFldsList($this->fldsList);
                
        return $recordsList;
    }
    
    /**
     * showConfigSettings - access config settings when you have powers but not have full powers but need to have them.
     */
    public function showConfigSettings() 
    {
        $html = '';
        $js = '';
        
        // build js code for this, AJAX bits
        
        // TODO: create page of config settings we need access to.
        
        
        // global configs
            // get global all this EM settings
            
            // main_cron_switch
            // debug_mode_log_system
        
        // project configs
            // get project settings
            
            // debug_mode_log_project
            // project_main_cron_switch

        // put some of your other config settings here
        
            // put some of your other config settings here
            
        $flag1 = ($this->debug_mode_log_system ? 'TRUE' : 'FALSE');
        $flag2 = ($this->main_cron_switch ? 'TRUE' : 'FALSE');
        $flag3 = ($this->debug_mode_log_project ? 'TRUE' : 'FALSE');
        $flag4 = ($this->project_main_cron_switch ? 'TRUE' : 'FALSE');
            
        $html .= '';
        $html .= '<div id="rulehmodulesettingspage">';
        $html .= '<h1>Rule H Filters Processing Configuration</h1>';
        $html .= '<p>TODO: Fill in build out settings page.</p>';

        $html .= '<ol>';
        $html .= '<li>';
        $html .= 'item debug_mode_log_system: ';
        $html .= $flag1;        
        $html .= '</li>';

        $html .= '<li>';
        $html .= 'item debug_mode_log_project: ';
        $html .= $flag3;        
        $html .= '</li>';

        $html .= '</ol>';
        
        $html .= '</div>';
        
        // Display
        $this->viewHtml($html, 'project');
        
        // then AJAX methods will store settings
        
        return 1;
    }

    /**
     * sampleMetaData - test.
     */
    public function sampleMetaData() 
    {
        $someList = array();
         
        $nl = "\n";
        $br = '<br>';
        $hr = '<hr>';
        $end = $br.$nl;
        $break = $hr.$nl;
        
        if (!defined('PROJECT_ID')) {  // not in a project context
            return null;
        }   

        global $Proj;

        foreach ($Proj->metadata as $attr) {
            $someList[] = $attr;
        }
                
        return $someList;
    }
    
    /**
     * recordsListMerge - test.
     */
    public function recordsListMerge($arr1 = array(), $arr2 = array()) 
    {
        $someList = array();
        
        $flagMergeType = $this->getFlagMergeType();
        
        //$someList = array_merge($arr1, $arr2, $arr3, $arr4);
        //$someList = [...$arr1, ...$arr2, ...$arr3, ...$arr4];  // 7.4 and 8.0 PHP trick

        // protect for all null as the + operand does not work when all are null
        if ($arr1 === null && $arr2 === null) {
            return null;
        }

        // need to preserve the keys...  array_merge does not preserve numeric keys
        $flag1 = false;
        $flag2 = false;
        
        if (is_array($arr1) && $arr1 != null) {
            $flag1 = true;
        }
        if (is_array($arr2) && $arr2 != null) {
            $flag2 = true;
        }
        
        $flagNull1 = false;
        $flagNull2 = false;
        
        if ($arr1 === null) {
            $flagNull1 = true;
        }
        if ($arr2 === null) {
            $flagNull2 = true;
        }
        
        if (isset($arr1) || isset($arr2)) {
            if ($flagNull1 && $flagNull2)   { // both null
                return null;
            }

            // none or only have arr1
            if ($flagNull2) {  // second is null, 
                if ($flagNull1) { // first is null
                    return null;  // neither
                }
                return $arr1; // only have arr1
            }

            // only have arr2
            if ($flagNull1) {  // first is null and second has something
                return $arr2;
            }
            
            // make sure data is in arrays, so merge works.
            if (!is_array($arr1)) {
                $arr1 = [$arr1];
            }
            if (!is_array($arr2)) {
                $arr2 = [$arr2];
            }
            
            //$flagMergeType = $this->getFlagMergeType();
            
            // both arrays have something  MERGE THEM!
            // each of these merge types preserve KEYS          
            switch ($flagMergeType) {
                // dead code due to changes above
                // resurrecting this code 20230925
                case self::MERGE_TYPE_AND:
                    $someList = array_intersect($arr1, $arr2);  // MERGE as AND Logic  (both arrays common values)
                    break;

                case self::MERGE_TYPE_OR:
                    $someList = $arr1 + $arr2;  // MERGE as OR Logic (both arrays all values)
                    break;
                    
                default:
                    break;
            }
        }

        ksort($someList); // key sort the list so it is ordered by Record
        
        return $someList;
    }   

    /**
     * getFlagMergeType - get config setting for merge type.  Default: to merge as AND logic). 
     */
    public function getFlagMergeType() 
    {
        $valSetting = ($this->getProjectSetting('flag_merge_type') ? true : false);  // CHECKED = true = OR   UNCHECKED = false = AND (and our intended DEFAULT state)
        
        // if it is blank (unchecked).  it is false.  and we mean it to be the AND
        // if it is checked. it is true.  and we mean it to be the OR
        
        // Right now this is binary true or false in the config while setting in internally is designed to allow expansion to more types.
        $this->flagMergeType = ($valSetting === false ? self::MERGE_TYPE_AND : self::MERGE_TYPE_OR);  // false = AND  true = OR   AND = 1  OR = 2 (a switch statement with a null or false is less precise)
                
        return $this->flagMergeType;
    }   
        
    /**
     * buildUiTab - create the User Interface for selection of DAGs, Time Frame, Forms, Events which produces the list of records to be used for the RULE H processing.
     */
    public function buildUiTab() 
    {
        $html = '';

        $dagsSection = '';
        
        $dagsSection = $this->htmlDags();
        
        $timeSection = $this->makeTimeFrameMenuTableRows();

        $html .= '<script>';
        $html .= $this->getJsCode($this->projectId);
        $html .= '</script>';

        // supporting CSS for the DUAL LIST Box
        $cssFileUrl[] = 'css/bootstrap.min.css';
        $cssFileUrl[] = 'css/prettify.min.css';
        $cssFileUrl[] = 'css/bootstrap-duallistbox.css';
        
        foreach ($cssFileUrl as $cssFileUrlKey => $fileUrl) {
            $path = $this->getUrl($fileUrl);
        $html .= '<link rel="stylesheet" type="text/css" href="'.$path.'">';
        }

        // supporting JS for the DUAL LIST Box
        $jsFileUrl[] = 'js/prettify.js';
        $jsFileUrl[] = 'js/jquery.bootstrap-duallistbox.js';

        foreach ($jsFileUrl as $jsFileUrlKey => $fileUrl) {
            $path = $this->getUrl($fileUrl);
        $html .= '<script src="'.$path.'"></script>';
      }
      
      // Supporting JS code interface code     
        $dataProcessMethod = '
        function getDagListIdsFromChoices()
        {
            var dataitems2 = $(\'[name="demoformdagslist"]\').val();
            return dataitems2;
        }   
    
        function getChoicesFormsList()
        {
            var dataitems2 = $(\'[name="demoformformlist"]\').val();
            return dataitems2;
        }
    
        function getChoicesEventsList()
        {
            var dataitems2 = $(\'[name="demoformeventlist"]\').val();
            return dataitems2;
        }
            
        function dataProcessMethod(flag)
        {
            var dataitems = getDagListIdsFromChoices();
            var str = sortMyData(dataitems);
            
            Swal.fire("DAG IDs are: " + str);
            
            return str;
        }
    
        function dataProcessMethodForms(flag)
        {
            var dataitems = getChoicesFormsList();
            var str = sortMyData(dataitems);

            Swal.fire("Form IDs are: " + str);
            
            return str;
        }       
    
        function dataProcessMethodEvents(flag)
        {
            var dataitems = getChoicesEventsList();
            var str = sortMyData(dataitems);

            Swal.fire("Event IDs are: " + str);
            
            return str;
        }       
        '; 
       
      // sorting the data
        $sortMethod = '
        function sortMyData(inData)
        {
            if (!inData) {
                return "";
            }
            
            var list = inData.toString().split(",");
            list.sort();
            var str = list.toString();
            
            return str;
        }
        ';

    $html .= '<style>
    .classformssections {
        style="color:#016f01;
        margin: 3px 0px 7px 0px;    
    }
    .classdiv {
        background-color: #F0F0F0;
        style="color:#016f01;
        margin: 3px 0px 7px 0px;
    }
    .rowcolors {
        margin: 3px 0px 7px 0px;
        color: #016f01;
    }
    .listbuttons {
        margin: 3px 0px 7px 0px;
        color: red;
    }
    #clearradios {
        margin: 3px 0px 7px 0px;
        color: #016f01;
    }
    #button_dag {
        margin: 3px 0px 7px 0px;
        color: #016f01;
    }
    </style>';
        
    $html .= '<script>'.$dataProcessMethod.'</script>';
    $html .= '<script>
  $( function() {
    $( ".class_accordion" ).accordion();
  } );
</script>';

    $html .= '<script>'.$sortMethod.'</script>';

        $html .= '<div id="formwrapper">';
        // ***** FILTERS FORM START *****
        $html .= '<form id="formrulehfilters">';
        $html .= '';

        $html .= '<div id="rulehuisectiontopNEW" width="100%" class="round chklist col-12">';

        $html .= '<h1>Rule H Filters Processing Menu<h1></h1>';

        $html .= '<h3>Choose Type (either by DAG groups and/or Time frame)</h3>';
        $html .= '<p>What to expect here is choice of DAGs and/or Time Frame will narrow down selection of RECORDS to be processed in the project.  Intention is use a smaller subset of records for the RULE H processing for a more manageable size which will be processed and not overburden the system.</p>';

        $html .= '<table width="100%" >';
        $html .= '<tr style="color:#444;position:relative;top:10px;background-color:#e0e0e0;border:1px solid #ccc;border-bottom:1px solid #ddd;float:left;padding:5px 8px;" colspan="2">';
        $html .= '<td>';
        $html .= 'FILTERING OPTIONS';
        $html .= '</td>';
        $html .= '</tr>';
        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" style="padding:5px 8px 8px 8px;"  colspan="2">';
        $html .= '<td>';
        $html .= '&nbsp; RECORD Filters';
        $html .= '</td>';
        $html .= '</tr>';
        $html .= '</table>';

        $html .= '<div class="class_accordion">';

        // ***** Begin form DAGs Section *****
        $html .= '<h1 style="color:#016f01;margin: 3px 0px 7px 0px;">DAGS SECTION</h1>';
        $html .= '<div id="dags_form_section" class="classdiv">';

        $html .= '<table width="100%" >';

        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" colspan="2">';
        $html .= '<td class="labelrc rowcolors" colspan="2">';
        $html .= 'DAG LIST';

        $dagsList = $this->dagsList;
        $optionsDags = '';

        if ($dagsList) {
            foreach ($dagsList as $dagId => $dagName) {
                $optionsDags .= '<option value="'.$dagId.'">'.$dagName.'</option>';
            }
        }

    $htmlbuttondagslist = '<input id="button_dag" type="button" form="demoformdagslist" class="listbuttons" value="'.'SHOW DAG LIST IDs'.'" onclick="dataProcessMethod(1)">'; // tag jsProcessList
    
        $html .= '<form id="demoformdagslist" action="#" method="post">
        <select multiple="multiple" size="10" name="demoformdagslist">
                    '.$optionsDags.'
        </select>
        <br>' . $htmlbuttondagslist .'
      </form>
      <script>
      var inmenutestdags = $(\'select[name="demoformdagslist"]\').bootstrapDualListbox();
      </script>
      ';

        $html .= '</td>';
        $html .= '</tr>';

        $html .= '</table>';
        
        $html .= '</div>';
        // ***** End form DAGs Section *****
        
        // ***** Begin form TIME FRAME Section *****
        $html .= '<h1 style="color:#016f01;margin: 3px 0px 7px 0px;">TIME FRAME SECTION</h1>';
        $html .= '<div id="timeframe_form_section" class="classdiv">';

        $html .= '<table width="100%" >';       
        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" style="padding:50px 8px 8px 8px;">';
        $html .= '<td class="labelrc rowcolors">';
        $html .= 'TIME FRAME';
        $html .= '</td>';

        $html .= $timeSection;

        $html .= '</tr>';
        $html .= '</table>';
        $html .= '</div>';
        // ***** End form TIME FRAME Section *****      

        // ***** Begin form FORMs Section
        $html .= '<h1 style="color:#016f01;margin: 3px 0px 7px 0px;">FORMS SECTION</h1>';
        $html .= '<div id="forms_form_section" class="classdiv">';
        $html .= '<table width="100%" >';

        $formsList = $this->getFormsList();
        $countForms = $this->robustCount($formsList);
        
        // Forms Section UI grid
        $htmlbuttonFormList = '<input type="button" form="demoformformlist" value="'.'SHOW FORMS LIST IDs'.'" onclick="dataProcessMethodForms()">'; // tag jsProcessList
        $formsSectionLabel = 'FORMS SECTION';
        
        $optionsForms = '';

        foreach($formsList as $formsKey => $formsData) {
            $formId = $formsData['keyFormName'];
            $formName = $formsData['formName'];
            $optionsForms .= '<option value="'.$formId.'">'.$formName.'</option>';
        }
        
        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" style="padding:50px 8px 8px 8px;">';
        $html .= '<td class="labelrc rowcolors">';
        $html .= $formsSectionLabel;

        $html .= '<form id="demoformformlist" action="#" method="post">
        <select multiple="multiple" size="10" name="demoformformlist">
                    '.$optionsForms.'
        </select>
        <br>' . $htmlbuttonFormList .'
      </form>
      <script>
      var inmenutestform = $(\'select[name="demoformformlist"]\').bootstrapDualListbox();
      </script>
      ';
        $html .= '</td>';
        $html .= '</tr>';       
                
        $html .= '</table>';
        $html .= '</div>';
        // ***** End form FORMs Section

        // ***** Begin form EVENTs Section
        $html .= '<h1 style="color:#016f01;margin: 3px 0px 7px 0px;">EVENTS SECTION</h1>';
        $html .= '<div id="events_form_section" class="classdiv">';
        $html .= '<table width="100%" >';
        
        // Events section
        $eventsList = $this->getEventListData();
        $countEvents = $this->robustCount($eventsList);

        // EVENTS SECTION UI Grid
        $eventsSectionLabel = 'EVENTS SECTION';
    $htmlbuttonEventList = '<input type="button" form="demoformeventlist" value="'.'SHOW EVENTS LIST IDs'.'" onclick="dataProcessMethodEvents()">'; // tag jsProcessList

        $optionsForms = '';
        foreach($eventsList as $eventsKey => $eventsName) {
            $optionsForms .= '<option value="'.$eventsKey.'">'.$eventsName.'</option>';
        }

        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" style="padding:50px 8px 8px 8px;">';
        $html .= '<td class="labelrc" style="color:#016f01;margin: 3px 0px 7px 0px;">';
        $html .= $eventsSectionLabel;

        $html .= '<form id="demoformeventlist" action="#" method="post">
        <select multiple="multiple" size="10" name="demoformeventlist">
                    '.$optionsForms.'
        </select>
        <br>' . $htmlbuttonEventList .'
      </form>
      <script>
      var inmenutestevent = $(\'select[name="demoformeventlist"]\').bootstrapDualListbox();
      </script>
      ';
        $html .= '</td>';
  
        $html .= '</tr>';
        $html .= '</table>';

        $html .= '</div>';
        // ***** End form EVENTs Section

        $html .= '</div>';  // end DIV class_accordion

        $html .= '<table width="100%" >';

        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" style="padding:50px 8px 8px 8px;"  colspan="2">';
        $html .= '</tr>';

        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" style="padding:50px 8px 8px 8px;"  colspan="2">';
        $html .= '<td class="labelrc" style="color:#016f01; margin: 3px 0px 7px 0px; text-align: center;">';
        $html .= $this->giveButton('previewlist');
        $html .= '</td>';
        $html .= '</tr>';

        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" style="padding:50px 8px 8px 8px; text-align: center;"  colspan="2">';
        $html .= '<td class="labelrc" style="color:#016f01;margin: 3px 0px 7px 0px;">';
        $html .= $this->giveButton('rewindlimit');
        $html .= '</td>';
        $html .= '</tr>';


        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" style="padding:50px 8px 8px 8px; text-align: center;"  colspan="2">';
        $html .= '<td class="labelrc" style="color:#016f01;margin: 3px 0px 7px 0px;">';
        
        //
        $html .= '<div class="round chklist col-12" id="emaildata">';
        $html .= '<div id="emaildatafield">';
        //$html .= 'Notify me when DONE send EMAIL to address: ';
        $html .= self::BTN_LABEL_NOTIFY;
        
        $html .= '<input type="text" width="40" form="formbulklistadd" id="email_to_user" name="email_to_user">';
        
        $html .= '</div>';        
        $html .= '</div>';


        $html .= '</td>';
        $html .= '</tr>';
        
        $html .= '<tr class="labelrc create_rprt_hdr" width="100%" style="padding:50px 8px 8px 8px; text-align: center;"  colspan="2">';
        $html .= '<td class="labelrc" style="color:#016f01;margin: 3px 0px 7px 0px;">';
        
        //self::BTN_LABEL_PROCESS
        $html .= $this->giveButton();
        $html .= '</td>';
        $html .= '</tr>';
        
        $html .= '</table>';
        

        $html .= '</div>';      // end DIV rulehuisectiontopNEW

        $html .= '</form>';

        $html .= '</div>';  // end DIV form wrapper
        // ***** FILTERS FORM END *****

        // actionspinner
        $html .= '<div id="actionspinner" class="round chklist col-12" style="display: none; style="display: block; margin-left: auto; margin-right: auto; width: 50%;" width="100%">';
        $html .= '<h1 style="text-align: center; color:green;" >PROCESSING...</h1>';
        $html .= '<div id="spinnerimg">';
        $html .= '<img src="'.APP_PATH_IMAGES.'progress_circle.gif" style="display: block; margin-left: auto; margin-right: auto;"></img>';
        $html .= '</div>';
        $html .= '';
        $html .= '</div>';

        $html .= '<div class="round chklist col-12" id="resultsdata">';
        $html .= '</div>';
               
        // ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** 
        // ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** 
        // ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** 
        // ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** 
        // ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** ***** 

        return $html;
    }


    /**
     * intToHex - number to hex. ex: 255 becomes FF.
     */
    public function intToHex($string) {
        $hex = '';
        $hexCode = dechex($string);
        
        $hex .= substr('0'.$hexCode, -2);
    
        return strToUpper($hex);
    }

    /**
     * toColor - number to hex RGB color.
     */
    public function toColor($n)
    {
        return('#'.substr('000000'.dechex($n),-6));
    }

    /**
     * toColorRGB - three numbers to hex RGB color.
     */
    public function toColorRGB($r, $g, $b, $tag = false)
    {
        return( ($tag ? '#' : '') . strToUpper( substr('00'.dechex($r),-2) . substr('00'.dechex($g),-2) . substr('00'.dechex($b),-2) ) );
    }
    
    /**
     * htmlDags - DAGs html.
     */
    public function htmlDags() 
    {
        $html = '';
        $projectId = (defined("PROJECT_ID") ? PROJECT_ID : 0);
        $dagsList = $this->getDagList($projectId);
        
        $this->dagsList = $dagsList;
        $dagListHtml = $this->makeDagListHtmlTableRows($dagsList);
                
        return $dagListHtml;
    }
        
    /**
     * makeDagListHtml - make a DAG list into HTML selection listing.
     */
    public function makeDagListHtmlTableRows($dagList) 
    {
        $html = '';
        $nl = "\n";
        $br = '<br>';

        if ($dagList) {
            foreach ($dagList as $dagId => $dagName) {
                $html .= '<tr>';

                $html .= '<td class="labelrc">';
                $html .= '<input type="checkbox" id="'.$dagName.'" name="daglistnames" value="'.$dagId.'">';
                
                $html .= ' <label for="'.$dagName.'"> '.$dagName. ' ('.$dagId.')' . '</label>';
                $html .= '</td>';
                
                $html .= '<tr>';
                $html .= $nl;
            }
        }
        
        return $html;
    }
    
    /**
     * makeTimeFrameMenu - make time frame html.
     */
    public function makeTimeFrameMenuTableRows() 
    {
        $html = '';

        $html .= '';
        $html .= '<tr>';
        $html .= '<td class="labelrc">';
        $html .= '<input type="radio" id="past7days" name="timeframechoice" value="7">';
        $html .= '<label for="past7days"> &nbsp;Past 7 Days</label><br>';
        $html .= '</td>';
        $html .= '</tr>';
        
        $html .= '<tr>';
        $html .= '<td class="labelrc">';
        $html .= '<input type="radio" id="past24hrs" name="timeframechoice" value="24">';
        $html .= '<label for="past24hrs"> &nbsp;Past 24 Hours</label><br>';
        $html .= '</td>';
        $html .= '</tr>';
        
        $html .= '<tr>';
        $html .= '<td class="labelrc listbuttons">';
        $html .= '<input type="button" id="clearradios" value="Reset Time Frame" name="clearradios" onclick="$(\'#past7days\').prop(\'checked\',false); $(\'#past24hrs\').prop(\'checked\',false);">';
        $html .= '</td>';
        $html .= '</tr>';
        
        return $html;
    }       

    /**
     * makeEventsSectionHtml - given array events list make the table row html checkbox listing.
     */
    public function makeEventsSectionHtml($eventsList) 
    {
        $html = '';

        foreach ($eventsList as $key => $val) {
            $formId = $key;
            $formName = $val;

            $formNameKey = '';
            if ($formName > '') {
                $formNameKey = $val['keyFormName'];
            }           

            $formcheck = '<input type="checkbox" id="eventslistingcheckboxes"'.$formId.' name="eventschoicelisting" value="'.$formName.'">';
            $formchecklabel = '<label for="eventslistingcheckboxes"'.$formId.'> &nbsp;['.$formId.'] '.$formName.' ('.$formNameKey.')</label><br>';

            $html .= '<tr>';
            $html .= '<td class="labelrc">';
            $html .= $formcheck;
            $html .= $formchecklabel;
            $html .= '</td>';
            $html .= '<tr>';            
        }
                
        return $html;
    }
    
    /**
     * makeFormsSectionHtml - given array forms list make the table row html checkbox listing.
     */
    public function makeFormsSectionHtml($formsList) 
    {
        $html = '';

        foreach ($formsList as $key => $val) {
            $formId = $val['formNumber'];
            $formName = $val['formName'];


            $formNameKey = '';
            if ($formName > '') {
                $formNameKey = $val['keyFormName'];
            }           

            $formcheck = '<input type="checkbox" id="formslistingcheckboxes"'.$formId.' name="formschoicelisting" value="'.$formName.'">';
            $formchecklabel = '<label for="formslistingcheckboxes"'.$formId.'> &nbsp;['.$formId.'] '.$formName.' ('.$formNameKey.')</label><br>';

            $html .= '<tr>';
            $html .= '<td class="labelrc">';
            $html .= $formcheck;
            $html .= $formchecklabel;
            $html .= '</td>';
            $html .= '<tr>';            
        }
                
        return $html;
    }
    
    /**
     * getconfigSpoolSwitch - get config setting for Spool Switch.
     */
    public function getconfigSpoolSwitch() 
    {
        // Spooling Switch ON or OFF (Default: ON which is UNCHECKED) Check to turn OFF
        $spool_switch = ($this->getProjectSetting('spool_switch') ? false : true);  // flip the bits as REDCap config has no working default
        
        return $spool_switch;
    }

    /**
     * getconfigSpoolSize - get config setting for Spool Size.
     */
    public function getconfigSpoolSize() 
    {
        // Spooling Chunk Size (Default: 100)
        $spool_size = ($this->getProjectSetting('spool_size') ? $this->getProjectSetting('spool_size') : self::SETTING_SPOOL_SIZE);  // if blank, make it 100 the default chunk size
        
        return $spool_size;
    }

    // Flag to PROCESS or PREVIEW ONLY  TRUE = Process  FALSE = Preview
    // static_limit_offset_preview
    // static_limit_offset_process
    /**
     * rewindLimitOffset - when using limit ranging this will rewind to the beginning.
     */
    public function rewindLimitOffset()
    {
        $this->setProjectSetting('static_limit_offset_preview', 0);
        $this->setProjectSetting('static_limit_offset_process', 0);
        
        $this->setProjectSetting('tally_count_process', 0);
        $this->setProjectSetting('tally_count_preview', 0);
    }

    /**
     * getLimitOffset - get the current value paging marker position.
     */
    public function getLimitOffset($flagProcess = false)
    {
        // $flagProcess = Flag to PROCESS or PREVIEW ONLY  TRUE = Process  FALSE = Preview
        
        if ($flagProcess) {  // PROCESS
            return $this->getProjectSetting('static_limit_offset_process');
        } else {             // PREVIEW
            return $this->getProjectSetting('static_limit_offset_preview');
        }
    }

    // ***** ***** 
    // ***** ***** 
    
    
    /** 
     *
     */
    public function getStrNowTime($now = null)
    {
        if (!$now) {
            $now = $this->getProcessNowTime();
        }
       
        return $now->format(self::RULEHDATEFORMATEXTENDEDSECS);   
    }
    
    /** 
     *
     */
    public function getProcessNowTime()
    {
        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone($this->timeZoneToUse));
        
        return $now;    
    }
    
    /** 
     *
     */
    public function processDurationMsg($module, $debug_cron, $startDateTime = null, $endDateTime = null)
    {
        $pid = $module->projectId;
        $durationMsg = '';
        $durationMsgArray = array();
        
        // ***** LOG Finish of this page activity *****
        if (true) {
            
            // no start time, you are out.
            if ($startDateTime == null) {
                $startDateTime = $this->getProcessNowTime();
            }
    
            // add an end time if none
            if ($endDateTime == null) {
                $endDateTime = $this->getProcessNowTime();
            }
            
            // calc duration = end - start
            // $durationStr = math of end minus start added here
            $duration = $startDateTime->diff($endDateTime);
    
            // 2024-05-02 13:40
            $endtimeStr = $endDateTime->format('Y-m-d H:i');
            $durFormat = '%dh%dm%ds';
            $durationStrMinimal = sprintf(
                $durFormat,
                $duration->h,
                $duration->i,
                $duration->s
            );
            
            $durationMsg = $this->markerHeader . $endtimeStr . ' - PID: ' . $pid . '; Finished duration ' . $durationStrMinimal;
            
            $durationMsgArray['msg'] = $durationMsg;
            $durationMsgArray['duration'] = $durationStrMinimal;
            $durationMsgArray['header'] = $this->markerHeader . $endtimeStr;
            $durationMsgArray['pidmsg'] = ' - PID: ' . $pid;
            
            if ($debug_cron) {
                // Rule H: 2024-05-02 13:40 - PID: 36; Finished duration: 0h0m0s    
                $module->log($durationMsg);
            }
        }
        
        return $durationMsgArray;
    }

    /** 
     *  getMarkerHeader - get logging header string
     */
    public function getMarkerHeader($renew = false)
    {
        if ($renew) {
            // generate a new one ( previously had a date stamp and random digit. no longer using that )
            $this->markerHeader = self::RULEHPREFIXLOG;
        }
        
        return $this->markerHeader;
    }

    /** 
     *  getTimeZoneToUse - get preferred timezone
     */
    public function getTimeZoneToUse()
    {
        return $this->timeZoneToUse;
    }
    
    /** 
     *  getEmailToUse - get preferred email to send when done.
     */
    public function getEmailToUse()
    {
        // email_to_send_when_done
        $email = $this->getProjectSetting('email_to_send_when_done');
        
        $email = (strlen($email) ? $email : '');
        
        return $email;
    }

    /** 
     *  getEmailFlag - get flag for email send.
     */
    public function getEmailFlag()
    {
        $this->flag_email_send = false;

        // flag_email_send
        $this->flag_email_send = $this->getProjectSetting('flag_email_send');
        
        $this->flag_email_send = ($this->flag_email_send ? true : false);
                
        return $this->flag_email_send;
    }

    /** 
     *  getEmailNoReply - use noreply and tack on base address of configured email. if no param will use default constant.
     */
    public function getEmailNoReply($email = '')
    {
        $noreply = self::EMAIL_NO_REPLY;
        
        if ($email) {
            // extract the root address
            $emailArr = explode('@', $email);
            
            $address = ( isset($emailArr[1]) ? $emailArr[1] : 'noreply.org' );
            
            // build noreply using root address
            $noreply = 'noreply' . '@' . $address;
        }
                
        return $noreply;
    }
    
    /** 
     *  sendEmailWhenDoneMsg - finish up email notice when done.
     */
    public function sendEmailWhenDoneMsg($msg = 'Done', $overrideEmail = '')
    {
        $flagEmail = $this->getEmailFlag();
        
        if (strlen($overrideEmail) > 0) {
            $flagEmail = true;
        }
        
        if ($flagEmail) {
            
            $email   = $this->getEmailToUse();
            
            if (strlen($overrideEmail) > 0) {
                $email = $overrideEmail;
            }

            if (strlen($email) == 0 ) {
                $this->log('Error sending email blank');
                return;
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->log('Error sending email is invalid: ' . $email);
                return;
            }

            $from    = $this->getEmailNoReply($email);
            $subject = self::EMAIL_SUBJECT;
            $body    = self::EMAIL_MSG;
            
            $body .= ' ' . $this->getStrNowTime();
            $body .= '<hr>';
            $body .= $msg;
                        
            try {
                return REDCap::email($email, $from, $subject, $body);
            } catch (\Exception $e) {
                $this->log('Error sending username email:' . $email . ' ' . print_r($e, true) );
            }

        } // end if email flag
    }


// TODO: add something for dag like:
// SELECT distinct record FROM redcap_record_list where dag_id = '12';

/*
SELECT distinct record FROM redcap_record_list where project_id = 21 and dag_id = '9';
SELECT * FROM redcap_record_list where project_id = 21;# and dag_id is null;
SELECT * FROM redcap_record_list where project_id = 21 and dag_id is null;

all
null
DAGID

project_id  int 10  not null

dag_id      int 10  null

*/

/*

  SELECT project_id, group_id, group_name FROM redcap_data_access_groups WHERE project_id = 21 ORDER BY group_id;

*/
    public function getDagsListForProject($projectId)
    {
        $dagsList = array();
        $sqlParams = array();
        
        // SEE 
        //  public function getDagList($projectId = 0)
        //   and
        //  $groups = $Proj->getGroups();  // $sql = "select * from redcap_data_access_groups where project_id = " . $this->project_id . " order by trim(group_name)";
        
        $recordSql = 'SELECT project_id, group_id, group_name FROM redcap_data_access_groups WHERE project_id = ? ORDER BY group_id';

        $sqlParams[] = db_escape($projectId);

        $queryRows = $this->query($recordSql, $sqlParams);
        
        if ($queryRows) {
            while ($row = db_fetch_assoc($queryRows)) {
                $dagsList[] = $row['group_id'];
            }
        }
                
        return $dagsList;
    }

    // experimental
    /** 
     *  pullRecordsListByDag - given a project, pull records for a time frame, or all, or none
     *  returns: false = not valid days filter param, array = of records OR empty array
     *  dagsFilter: 0 = all records, not numeric = abort, 1 or greater = number of days to count to past
     *              negative values are not allowed.
     */
    public function pullRecordsListByDag($projectId, $dagsFilter = null, $limit = null, $limitOffset = 0)
    {
        $records = array();
        $sqlParams = array();

        // Check that we have valid filters
        // TODO: may want to work this for the array of DAG IDs
        //if ( !is_numeric($dagsFilter) ) {
        //    $this->log('DAGS RECORD ERROR filter: [' . print_r($dagsFilter, true) . ']');
        //    return false; // We don't have a valid DAGs filter
        //}
        if ( !is_numeric($projectId) ) {
            $this->log('DAGS RECORD ERROR PID: [' . $projectId . ']');
            return false; // We don't have a valid projectId filter
        }
       

		$limitSql = '';  // no limit: $limit = null, $limitOffset = 0
		
		// if has a limit value, then, make the limit string
		//  as in  $limit = value > 0, $limitOffset = 0  or last position number
		//  limit NNN
		//   or
		//  limit OFFSET, NNN
		if (is_numeric($limit)) {
			$limitSql = ' limit ' . ((is_numeric($limitOffset) && $limitOffset > 0) ? $limitOffset . ', ' . $limit : $limit);
		}

        $flagSingleDag = true;  // ONE DAG = true, MANY DAGS = false
        if (is_array($dagsFilter)) {
            $flagSingleDag = false;    

        } else {
            if ( !is_numeric($dagsFilter) ) {
                $this->log('DAGS RECORD ERROR filter: [' . print_r($dagsFilter, true) . ']');
                return false; // We don't have a valid DAGs filter
            }            
        }

        // TODO: maybe handle null dag
        // $sql .= " and dag_id is null";
        
        if ($flagSingleDag) {
            $recordSql = 'SELECT distinct record FROM redcap_record_list WHERE project_id = ? AND dag_id = ?' . $limitSql;
            $sqlParams[] = db_escape($dagsFilter);              // DAGS
        } else {
            $recordSql = 'SELECT distinct record FROM redcap_record_list WHERE project_id = ? AND dag_id in (' . prep_implode($dagsFilter) . ')' . $limitSql;
        }

        $sqlParams[] = db_escape($projectId);               // PROJECT ID

        $queryResult = $this->sqlQueryAndExecute($recordSql, $sqlParams); 
        
        // build list of primary keys
        while ($row = $queryResult->fetch_assoc()) {
            $records[$row['record']] = htmlspecialchars($row['record'],ENT_QUOTES,'UTF-8');  // address Checkmarx games.
        }
        
        return $records;        
    }
    
    /** 
     *  pullRecordsListByTime - given a project, pull records for a time frame, or all, or none
     *  returns: false = not valid days filter param, array = of records OR empty array
     *  daysFilter: 0 = all records, not numeric = abort, 1 or greater = number of days to count to past
     *              negative values are not allowed.
     */
    public function pullRecordsListByTime($projectId, $daysFilter = 1)
    {   
        // Check that we have a valid days filter
        if ( !is_numeric($daysFilter) ) {
            return false; // We don't have a valid days filter
        }
        
        if ($daysFilter == 0) {
            $allRecords = Records::getRecordList($projectId);
            
            if ( $allRecords ) {
                return $allRecords;
            } else {
                return array();
            }
        }

        // No negative values for the days filter
        if ($daysFilter < 0) {
            return false; // We do not have a valid days filter
        }
    
        $records = array();
        $sqlParams = array();
        
        // What are the filters we need to apply?
        // Get the project log table
        // REDCap function is: Logging::setEventFilterSql
        $logTableName = Logging::getLogEventTable($projectId);
        
        // find the correct data table for the project
        $dataTable = method_exists('\REDCap', 'getDataTable') ? REDCap::getDataTable($projectId) : 'redcap_data';
        
        $todayNow = new DateTime();  // now
        $todayPast = new DateTime();  // now
        $intervalString = 'P' . $daysFilter . 'D'; // number of days interval
    
        $todayNow->add(DateInterval::createFromDateString('+5 minutes')); // add 5 minutes to this
        
        $lastXdays = $todayPast->sub(new DateInterval($intervalString)); // X days ago
               
        // checking  
        // todayNow now plus five minutes
        // todayPast (number of days ago given by daysFilter)
        //
        // time bracket ( number of days ago ) to ( five minutes from now )
        //
        //  ( TS >= todayPast ) AND ( TS <= todayNow )
        //
        $sqlParams[] = db_escape($projectId);               // PROJECT ID
        $sqlParams[] = $lastXdays->format('YmdHi') . '00';  // PAST DAYS   format: YYYYMMDDHHii00    
        $sqlParams[] = $todayNow->format('YmdHi') . '00';   // NOW
        
        // ts time bracket [past AND now] between and including past and now date times
        // SQL : SELECT pk FROM  logTableName  WHERE  object_type = '$dataTable' AND event in ('INSERT') AND description not like "%(Auto calculation)" and project_id = $projectId  AND ts>=$lastXdays AND ts<=$todayNow  group by pk
        //
        // SELECT pk AS record, project_id, log_event_id, event, event_id, object_type, ts, description FROM  redcap_log_event11  WHERE  object_type = 'redcap_data' AND event in ('INSERT') AND description NOT LIKE '%(Auto calculation)' AND project_id = 20 AND ts >= 20250119164600 AND ts <= 20250122164600 order by pk;
        // SELECT pk AS record FROM  redcap_log_event11  WHERE  object_type = 'redcap_data' AND event in ('INSERT') AND description NOT LIKE '%(Auto calculation)' AND project_id = 20 AND ts >= 20250119164600 AND ts <= 20250122164600 group by pk;
    
        $sqlFilter       = " object_type = '" . $dataTable . "' AND event in ('INSERT') AND description not like '%(Auto calculation)' AND project_id = ? ";
        $timeStampFilter = ' AND ts >= ? AND ts <= ?';
    
        $recordSql = 'SELECT pk AS record FROM ' . $logTableName . ' WHERE ' . $sqlFilter . $timeStampFilter . ' group by pk';
            
        $queryResult = $this->sqlQueryAndExecute($recordSql, $sqlParams); 
        
        // build list of primary keys which are record IDs
        while ($row = $queryResult->fetch_assoc()) {
            $records[$row['record']] = htmlspecialchars($row['record'],ENT_QUOTES,'UTF-8');  // address Checkmarx games.
        }
        
        return $records;
    }

    
    
    // **********************************************************************   
    // **********************************************************************   
    // **********************************************************************
    
} // *** end class

?>