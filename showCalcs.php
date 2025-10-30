<?php
namespace MGB\RuleHFiltersExternalModule;

// restrict api to module use only, protects from random unwanted hits
if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"RuleHFiltersExternalModule") == false ) { exit(); }

use ExternalModules\ExternalModules;
use Calculate;

global $Proj;

$projectId = $Proj->project_id;

$module->RuleHFiltersInitializer($projectId);

// config settings when you need to
//$module->showConfigSettings();

$listing = scanForCalcs($Proj);

$m = testhere($module);

$listing['testobj'] = $m;

$records[] = 1;
$excluded = null;
$excluded = $m->exclusionList($Proj);

if ($m->errFlag) {
    $module->log($m->errMsg);
}

$calcfields = $listing['calcfields'];

$thisSavedCalc = Calculate::saveCalcFields($records, $calcfields, 'all', $excluded);
$listing['thisSavedCalc'] = $thisSavedCalc;

$module->showJson($listing, true);

function scanForCalcs($Proj) 
{
    $list = [];    

    $numCalcFields = 0;
    foreach ($Proj->metadata as $attr) {
        if ($attr['element_type'] == 'calc') {
            $numCalcFields++;
            $list['calcfields'][] = $attr['field_name'];
        }
    }
    
    return $list;
}

function testhere($module)
{
    $x = new CalcScannerSupport();
    
    $x->setModule($module);
    
    return $x;
}

// ***** ***** ******
// ***** ***** ******
// ***** ***** ******

class CalcScannerSupport
{
    public $errMsg = '';
    public $errFlag = false;
    
    public $module;
    
    public function setModule($module)
    {
        $this->module = $module;
    }

	/**
	 * Calculates values of multiple calc fields and returns array with field name as key
	 * with both existing value and calculated value
	 * @param array $calcFields Array of calc fields to calculate (if contains non-calc fields, they will be removed automatically) - if an empty array, then assumes ALL fields in project.
	 * @param array $records Array of records to perform the calculations for (if an empty array, then assumes ALL records in project).
	 */
	public static function calculateMultipleFields($records=array(), $calcFields=array(), $returnIncorrectValuesOnly=false,
												   $current_event_id=null, $group_id=null, $Proj2=null, $bypassFunctionCache=true)
	{
		// Get Proj object
		if ($Proj2 == null && defined("PROJECT_ID")) {
			global $Proj;
		} else {
			$Proj = $Proj2;
		}
		// Project has repeating forms/events?
		$hasRepeatingFormsEvents = $Proj->hasRepeatingFormsEvents();
		// Validate $current_event_id
		if (!is_numeric($current_event_id)) $current_event_id = 'all';
		// Validate as a calc field. If not a calc field, remove it.
		$calcFieldsNew = $calcFieldsOrder = array();
		if (!is_array($calcFields) || empty($calcFields)) $calcFields = array_keys($Proj->metadata);
		foreach ($calcFields as $this_field) {
			if (isset($Proj->metadata[$this_field])) {
				$isCalcField = ($Proj->metadata[$this_field]['element_type'] == 'calc');
				$isCalcDateField = (!$isCalcField && self::isCalcDateField($Proj->metadata[$this_field]['misc']));
				$isCalcTextField = (!$isCalcField && !$isCalcDateField && self::isCalcTextField($Proj->metadata[$this_field]['misc']));
				// Add to array of calc fields
				if ($isCalcField) {
					$calcFieldsNew[$this_field] = $Proj->metadata[$this_field]['element_enum'];
					$calcFieldsOrder[$this_field] = $Proj->metadata[$this_field]['field_order'];
				} elseif ($isCalcDateField) {
					$calcFieldsNew[$this_field] = self::buildCalcDateEquation($Proj->metadata[$this_field]);
					$calcFieldsOrder[$this_field] = $Proj->metadata[$this_field]['field_order'];
				} elseif ($isCalcTextField) {
					$calcFieldsNew[$this_field] = self::buildCalcTextEquation($Proj->metadata[$this_field]);
					$calcFieldsOrder[$this_field] = $Proj->metadata[$this_field]['field_order'];
					// If calctext field is a date/datetime, then add left(x,y) inside calc to force the right length (in case the value is a full Y-M-D H:M:S - e.g., certain Smart Variables)
					if ($Proj->metadata[$this_field]['element_validation_type'] != "") {
						if (strpos($Proj->metadata[$this_field]['element_validation_type'], 'datetime_seconds_') === 0) {
							$calcFieldsNew[$this_field] = substr(str_replace("calctext(", "calctext(left(", $calcFieldsNew[$this_field]), 0, -1) . ",19))";
						} else if (strpos($Proj->metadata[$this_field]['element_validation_type'], 'datetime_') === 0) {
							$calcFieldsNew[$this_field] = substr(str_replace("calctext(", "calctext(left(", $calcFieldsNew[$this_field]), 0, -1) . ",16))";
						} else if (strpos($Proj->metadata[$this_field]['element_validation_type'], 'date_') === 0) {
							$calcFieldsNew[$this_field] = substr(str_replace("calctext(", "calctext(left(", $calcFieldsNew[$this_field]), 0, -1) . ",10))";
						}
					}
				}
			}
		}
		$calcFields = $calcFieldsNew;
		// Make sure calc fields are in the correct order
		array_multisort($calcFieldsOrder, SORT_NUMERIC, $calcFields);
		unset($calcFieldsNew, $calcFieldsOrder);
		// To be the most efficient with longitudinal projects, determine all the events being used by all records
		// in $records (this wittles down the possible events utilized in case there are lots of calcs to process).
		if ($Proj->longitudinal) {
			$getDataParams = array('project_id'=>$Proj->project_id, 'records'=>$records, 'field'=>$Proj->table_pk, 'returnEmptyEvents'=>false);
			$viableRecordEvents = Records::getData($getDataParams);
			$viableEvents = array();
			foreach ($viableRecordEvents as $this_record=>$event_data) {
				foreach (array_keys($event_data) as $this_event_id) {
					if ($this_event_id == 'repeat_instances') {
						foreach (array_keys($event_data['repeat_instances']) as $this_event_id) {
							$viableEvents[$this_event_id] = true;
						}
					} else {
						$viableEvents[$this_event_id] = true;
					}
				}
			}
		}
		// Get unique event names (with event_id as key)
		$events = $Proj->getUniqueEventNames();
		$eventNameToId = array_flip($events);
		$eventsUtilizedAllFields = $logicContainsSmartVariablesFields = array();
		// Create anonymous PHP functions from calc eqns
		$fieldToLogicFunc = $logicFuncToArgs = $logicFuncToCode = array();
		// Loop through all calc fields
		foreach ($calcFields as $this_field=>$this_logic)
		{
			$this_logic_orig = $this_logic;
			// If logic contains smart variables, then we'll need to do the logic parsing *per item* rather than at the beginning
			$logicContainsSmartVariables = Piping::containsSpecialTags($this_logic);
			if ($logicContainsSmartVariables) {
				$logicContainsSmartVariablesFields[] = $this_field;
			}
			// Format calculation to PHP format
			$this_logic = self::formatCalcToPHP($this_logic, $Proj);
			// Array to collect list of which events are utilized the logic
			$eventsUtilized = array();
			if ($Proj->longitudinal) {
				// Longitudinal
				foreach (array_keys(getBracketedFields($this_logic_orig, true, true, false)) as $this_field2)
				{
					// Check if has dot (i.e. has event name included)
					if (strpos($this_field2, ".") !== false) {
						list ($this_event_name, $this_field2) = explode(".", $this_field2, 2);
						// Deal with X-event-name
						if ($this_event_name == 'first-event-name') {
							$this_event_id = $Proj->getFirstEventIdInArmByEventId($current_event_id, $Proj->metadata[$this_field2]['form_name']);
						} elseif ($this_event_name == 'last-event-name') {
							$this_event_id = $Proj->getLastEventIdInArmByEventId($current_event_id, $Proj->metadata[$this_field2]['form_name']);
						} elseif ($this_event_name == 'previous-event-name') {
							$this_event_id = $Proj->getPrevEventId($current_event_id, $Proj->metadata[$this_field2]['form_name']);
						} elseif ($this_event_name == 'next-event-name') {
							$this_event_id = $Proj->getNextEventId($current_event_id, $Proj->metadata[$this_field2]['form_name']);
						} elseif ($this_event_name == 'event-name') {
							$this_event_id = $current_event_id;
						} else {
							// Get the event_id
							$this_event_id = array_search($this_event_name, $events);
						}
						// Add event_id to $eventsUtilized array
						if (is_numeric($this_event_id))	{
							// Add this event_id
							$eventsUtilized[$this_event_id] = true;
							// If the current event is used, then make ALL events as utilized where this field's form is designated
							if ($current_event_id == $this_event_id) {
								foreach ($Proj->getEventsFormDesignated($Proj->metadata[$this_field]['form_name'], array($current_event_id)) as $this_event_id2) {
									$eventsUtilized[$this_event_id2] = true;
								}
							}
						}
					} else {
						// Add event/field to $eventsUtilized array
						$eventsUtilized[$current_event_id] = true;
					}
				}
			} else {
				// Classic
				$eventsUtilized[$Proj->firstEventId] = true;
			}
			// Add to $eventsUtilizedAllFields
			$eventsUtilizedAllFields = $eventsUtilizedAllFields + $eventsUtilized;
			// If classic or if using ALL events in longitudinal, then loop through all events to get this logic for ALL events
			$eventsUtilizedLogic = array();
			if (!$Proj->longitudinal) {
				// Classic
				$eventsUtilizedLogic[$Proj->firstEventId] = $this_logic;
			} else {
				// Longitudinal: Loop through each event and add
				foreach (array_keys($Proj->eventInfo) as $this_event_id) {
					// Make sure this calc field is utilized on this event for this record(s)
					if (isset($viableEvents[$this_event_id]) && isset($Proj->eventsForms[$this_event_id]) && is_array($Proj->eventsForms[$this_event_id]) && in_array($Proj->metadata[$this_field]['form_name'], $Proj->eventsForms[$this_event_id])) {
						$eventsUtilizedLogic[$this_event_id] = LogicTester::logicPrependEventName($this_logic, $Proj->getUniqueEventNames($this_event_id), $Proj);
					}
				}
			}
			// If there is an issue in the logic, then return an error message and stop processing
			foreach ($eventsUtilizedLogic as $this_event_id=>$this_loop_logic) {
				/** NOT SURE WHAT THIS BLOCK OF CODE DID, BUT IT CAUSED ISSUES OF SKIPPING EVENTS
				// If longitudinal AND saving a form/survey
				if ($Proj->longitudinal && is_numeric($current_event_id)) {
					// Set event name string to search for in the logic
					$event_name_keyword = "[".$Proj->getUniqueEventNames($current_event_id)."][";
					// If the logic does not contain the current event name at all, then it is not relevant, so skip it
					if (strpos($this_loop_logic, $event_name_keyword) === false) {
						continue;
					}
				}
				*/
				$funcName = null;
				$args = array();
				if ($logicContainsSmartVariables) {
					// Set placeholder for Smart Vars since they will have to be evaluated for each item
					$fieldToLogicFunc[$this_event_id][$this_field] = '';
				} else {
					try {
						// Instantiate logic parse
						$parser = new LogicParser();
						list($funcName, $argMap) = $parser->parse($this_loop_logic, $eventNameToId, true, true, false, false, $bypassFunctionCache);
						$logicFuncToArgs[$funcName] = $argMap;
						// if (isDev()) $logicFuncToCode[$funcName] = $parser->generatedCode;
						$fieldToLogicFunc[$this_event_id][$this_field] = $funcName;
					}
					catch (Exception $e) {
						//if (isDev()) print "<br>$this_field) ".$e->getMessage();
						unset($calcFields[$this_field]);
					}
				}
			}
		}
		// Return fields/values in $calcs array
		$calcs = array();
		if (!empty($calcFields)) {
			// GET ALL FIELDS USED IN EQUATIONS
			$dependentFields = getDependentFields(array_keys($calcFields), true, false, true, $Proj2);
			// If any calc fields or dependent fields exist on a repeating form or event, then add its form's status field for getData() also
			if ($Proj->hasRepeatingFormsEvents()) {
				foreach (array_merge(array_keys($calcFields), $dependentFields) as $this_field) {
					if (!isset($Proj->metadata[$this_field])) continue;
					$this_field_form = $Proj->metadata[$this_field]['form_name'];
					// If field is on a repeating instrument, then add its form complete field
					if ($Proj->isRepeatingFormAnyEvent($this_field_form)) {
						$dependentFields[] = $this_field_form . "_complete";
						continue;
					}
					// If field is on a repeating event, then add its form complete field
					if ($Proj->longitudinal) {
						foreach ($Proj->eventsForms as $this_event_id => $these_forms) {
							if (in_array($this_field_form, $these_forms) && $Proj->isRepeatingEvent($this_event_id)) {
								$dependentFields[] = $this_field_form . "_complete";
								break;
							}
						}
					}
				}
				$dependentFields = array_unique($dependentFields);
			}
			// Get data for all calc fields and all their dependent fields
			$recordData = Records::getData($Proj->project_id, 'array', $records, array_merge(array($Proj->table_pk), array_keys($calcFields), $dependentFields),
							(isset($eventsUtilizedAllFields['all']) ? array_keys($Proj->eventInfo) : array_keys($eventsUtilizedAllFields)),
							$group_id, false, false, false, false, false, false, false, false, false, array(),
							false, false, false, false, false, false, 'EVENT', false, false, true);
			// If project has multiple arms, get list of records in each arm
			$recordsPerArm = $Proj->multiple_arms ? Records::getRecordListPerArm($Proj->project_id, array_keys($recordData)) : array();
			// Loop through all calc values in $recordData
			foreach ($recordData as $record=>&$this_record_data1) {
				foreach ($this_record_data1 as $event_id=>$this_event_data) {
					// Is repeating instruments/event? If not, set up like repeating instrument so that all is consistent for looping.
					if ($event_id != 'repeat_instances') {
						// Create array to simulate the repeat instance data structure for looping
						$this_event_data = array($event_id=>array(""=>array(""=>$this_event_data)));
					}
					// Loop through event/repeat_instrument/repeat_instance
					foreach ($this_event_data as $event_id=>$attr1) {
						// New check to skip null events for a record
						if ($Proj->longitudinal && !isset($viableRecordEvents[$record][$event_id])
							&& !isset($viableRecordEvents[$record]['repeat_instances'][$event_id])) continue;
						// In a multi-arm project, if the record does not yet exist in this arm, then skip (we do not want to create the record via auto-calcs)
						if ($Proj->multiple_arms && !isset($recordsPerArm[$Proj->eventInfo[$event_id]['arm_num']][$record])) {
							continue;
						}
						// Look through smaller structures
						foreach ($attr1 as $repeat_instrument=>$attr2) {
							foreach ($attr2 as $repeat_instance=>$attr3) {
								// Loop through ONLY calc fields in each event
								foreach (array_keys($calcFields) as $field) {
									// If this field on this event does not have a corresponding function set for the calc, then skip (nothing to do)
									if (!isset($fieldToLogicFunc[$event_id][$field])) continue;
									// If has repeating forms/events, then see if this field is relevant for this event/form
									if ($hasRepeatingFormsEvents) {
										// Get field's form
										$fieldForm = $Proj->metadata[$field]['form_name'];
										// If field is not relevant for this event/form, then skip
										if ($repeat_instrument == "" && $repeat_instance == "" && $Proj->isRepeatingForm($event_id, $fieldForm)) {
											continue;
										} elseif ($repeat_instrument != "" && $repeat_instance != ""
											&& (!$Proj->isRepeatingForm($event_id, $fieldForm) || $repeat_instrument != $fieldForm)) {
											continue;
										}
									}
									// Get saved calc field value
									if ($repeat_instance == "") {
										$savedCalcVal = $this_record_data1[$event_id][$field];
									} else {
										$savedCalcVal = $this_record_data1['repeat_instances'][$event_id][$repeat_instrument][$repeat_instance][$field];
									}
									// If project is longitudinal, make sure field is on a designated event
									if ($Proj->longitudinal && !in_array($Proj->metadata[$field]['form_name'], $Proj->eventsForms[$event_id])) continue;
									// Get the anonymous PHP function to use for this item
                                   $funcName = null;
                                   if (in_array($field, $logicContainsSmartVariablesFields)) {
                                       // Calc contains Smart Variables, so generate new function on the fly for this item
										try {
											// Instantiate logic parse
											$parser = new LogicParser();
                                            $logicThisItem = $calcFields[$field];
                                            $logicThisItem = Piping::pipeSpecialTags($logicThisItem, $Proj->project_id, $record, $event_id, $repeat_instance, null, true, null, $Proj->metadata[$field]['form_name'], false, false, false, true, false, false, true);
                                            if ($Proj->longitudinal) {
                                                $logicThisItem = LogicTester::logicPrependEventName($logicThisItem, $Proj->getUniqueEventNames($event_id), $Proj);
                                            }
											list($funcName, $argMap) = $parser->parse($logicThisItem, $eventNameToId, true, true, false, false, true);
											$logicFuncToArgs[$funcName] = $argMap;
											$fieldToLogicFunc[$event_id][$field] = $funcName;
										}
										catch (Exception $e) {
											unset($calcFields[$field]);
											continue;
										}
									} else {
										// Run regular function
										$funcName = $fieldToLogicFunc[$event_id][$field];
									}
									if ($funcName === null) continue;
									// Calculate what SHOULD be the calculated value
									$thisInstanceArgMap = $logicFuncToArgs[$funcName];
									// If we're in a repeating instance, then add the instance number to the arg map for all repeating fields
									// that don't already have a specified instance number in the arg map.
									if ($repeat_instance != "" && is_array($thisInstanceArgMap)) {
										foreach ($thisInstanceArgMap as &$theseArgs) {
											// If there is no instance number for this arm map field, then proceed
											if ($theseArgs[3] == "") {
												$thisInstanceArgEventId = ($theseArgs[0] == "") ? $event_id : $theseArgs[0];
												$thisInstanceArgEventId = is_numeric($thisInstanceArgEventId) ? $thisInstanceArgEventId : $Proj->getEventIdUsingUniqueEventName($thisInstanceArgEventId);
												$thisInstanceArgField = $theseArgs[1];
												$thisInstanceArgFieldForm = $Proj->metadata[$thisInstanceArgField]['form_name'];
												// If this event or form/event is repeating event/instrument, the add the current instance number to arg map
												if ( // Is a valid repeating instrument?
													($repeat_instrument != '' && $thisInstanceArgFieldForm == $repeat_instrument && $Proj->isRepeatingForm($thisInstanceArgEventId, $thisInstanceArgFieldForm))
													// Is a valid repeating event?
													|| ($repeat_instrument == '' && $Proj->isRepeatingEvent($thisInstanceArgEventId)))
													// NOTE: The commented line below was causing calcs not to be calculated if referencing a field on a repeating event whose form was not designated for the event
													// || ($repeat_instrument == '' && $Proj->isRepeatingEvent($thisInstanceArgEventId) && in_array($thisInstanceArgFieldForm, $Proj->eventsForms[$thisInstanceArgEventId])))
												{
													$theseArgs[3] = $repeat_instance;
												}
											}
										}
										unset($theseArgs);
									}
									$calculatedCalcVal = LogicTester::evaluateCondition(null, $this_record_data1, $funcName, $thisInstanceArgMap, $Proj2);
									// Change the value in $this_record_data for this record-event-field to the calculated value in case other calcs utilize it
									if ($repeat_instance == "") {
										$recordData[$record][$event_id][$field] = $this_record_data1[$event_id][$field] = $calculatedCalcVal;
									} else {
										$recordData[$record]['repeat_instances'][$event_id][$repeat_instrument][$repeat_instance][$field] =
											$this_record_data1['repeat_instances'][$event_id][$repeat_instrument][$repeat_instance][$field] = $calculatedCalcVal;
									}
									// Now compare the saved value with the calculated value
									$is_correct = !($calculatedCalcVal !== false && $calculatedCalcVal."" != $savedCalcVal."");
									// Precision Check: If both are floating point numbers and within specific range of each other, then leave as-is
									if (!$is_correct) {
										// Convert temporarily to strings
										$calculatedCalcVal2 = $calculatedCalcVal."";
										$savedCalcVal2 = $savedCalcVal."";
										// Neither must be blank AND one must have decimal
										if ($calculatedCalcVal2 != "" && $savedCalcVal2 != "" && is_numeric($calculatedCalcVal2) && is_numeric($savedCalcVal2)) {
											// Get position of decimal
											$calculatedCalcVal2Pos = strpos($calculatedCalcVal2, ".");
											if ($calculatedCalcVal2Pos === false) {
												$calculatedCalcVal2 .= ".0";
												$calculatedCalcVal2Pos = strpos($calculatedCalcVal2, ".");
											}
											$savedCalcVal2Pos = strpos($savedCalcVal2, ".");
											if ($savedCalcVal2Pos === false) {
												$savedCalcVal2 .= ".0";
												$savedCalcVal2Pos = strpos($savedCalcVal2, ".");
											}
											// If numbers have differing precision, then round both to lowest precision of the two and compare
											$precision1 = strlen(substr($calculatedCalcVal2, $calculatedCalcVal2Pos+1));
											$precision2 = strlen(substr($savedCalcVal2, $savedCalcVal2Pos+1));
											$precision3 = ($precision1 < $precision2) ? $precision1 : $precision2;
											// Check if they are the same number after rounding
											$is_correct = (round($calculatedCalcVal, $precision3)."" == round($savedCalcVal, $precision3)."");
										}
									}
									// If flag is set to only return incorrect values, then go to next value if current value is correct
									if ($returnIncorrectValuesOnly && $is_correct) continue;
									// Add to array
									$calcs[$record][$event_id][$repeat_instrument][$repeat_instance][$field]
										= array('saved'=>$savedCalcVal."", 'calc'=>$calculatedCalcVal."", 'c'=>$is_correct);
								}
							}
						}
					}
				}
				// Remove data as we go
				unset($recordData[$record]);
			}
			unset($this_record_data1);
		}
		// Return array of values
		return $calcs;
	}


	/**
	 * For specific records and calc fields given, perform calculations to update those fields' values via server-side scripting.
	 * @param array $calcFields Array of calc fields to calculate (if contains non-calc fields, they will be removed automatically) - if an empty array, then assumes ALL fields in project.
	 * @param array $records Array of records to perform the calculations for (if an empty array, then assumes ALL records in project).
	 * @param array $excludedRecordEventFields Array of record-event-fieldname (as keys) to exclude when saving values.
	 */
	public static function saveCalcFields($records=array(), $calcFields=array(), $current_event_id='all',
										  $excludedRecordEventFields=array(), $Proj2=null, $dataLogging=true, $group_id = null, $bypassFunctionCache=true)
	{
		// Get Proj object
		if ($Proj2 == null && defined("PROJECT_ID")) {
			global $Proj, $user_rights;
			$group_id = (isset($user_rights['group_id'])) ? $user_rights['group_id'] : null;
		} else {
			$Proj = $Proj2;
		}
		// Validate $current_event_id
		if (!is_numeric($current_event_id)) $current_event_id = 'all';
		// Return number of calculations that were updated/saved
		$calcValuesUpdated = 0;
		// Perform calculations on ALL calc fields over ALL records, and return those that are incorrect
		$calcFieldData = self::calculateMultipleFields($records, $calcFields, true, $current_event_id, $group_id, $Proj2, $bypassFunctionCache);
		if (!empty($calcFieldData)) {
			// Loop through any excluded record-event-fields and remove them from array
			foreach ($excludedRecordEventFields as $record=>$this_record_data) {
				foreach ($this_record_data as $event_id=>$this_event_data) {
					foreach ($this_event_data as $repeat_instrument=>$attr1) {
						foreach ($attr1 as $repeat_instance=>$attr2) {
							foreach (array_keys($attr2) as $field) {
								if ($repeat_instance < 1) $repeat_instance = "";
								if (isset($calcFieldData[$record][$event_id][$repeat_instrument][$repeat_instance][$field])) {
									// Remove it
									unset($calcFieldData[$record][$event_id][$repeat_instrument][$repeat_instance][$field]);
								}
							}
							if (empty($calcFieldData[$record][$event_id][$repeat_instrument][$repeat_instance])) unset($calcFieldData[$record][$event_id][$repeat_instrument][$repeat_instance]);
						}
						if (empty($calcFieldData[$record][$event_id][$repeat_instrument])) unset($calcFieldData[$record][$event_id][$repeat_instrument]);
					}
					if (empty($calcFieldData[$record][$event_id])) unset($calcFieldData[$record][$event_id]);
				}
				if (empty($calcFieldData[$record])) unset($calcFieldData[$record]);
			}
			// Loop through all calc values in $calcFieldData and format to data array format
			$calcDataArray = array();
			foreach ($calcFieldData as $record=>&$this_record_data) {
				foreach ($this_record_data as $event_id=>&$this_event_data) {
					foreach ($this_event_data as $repeat_instrument=>&$attr1) {
						foreach ($attr1 as $repeat_instance=>&$attr2) {
							foreach ($attr2 as $field=>$attr) {
								if ($repeat_instance == "") {
									// Normal data structure
									$calcDataArray[$record][$event_id][$field] = $attr['calc'];
								} else {
									// Repeating data structure
									$calcDataArray[$record]['repeat_instances'][$event_id][$repeat_instrument][$repeat_instance][$field] = $attr['calc'];
								}
							}
						}
					}
				}
				unset($calcFieldData[$record]);
			}
			// Save the new calculated values
			$saveResponse = Records::saveData($Proj->project_id, 'array', $calcDataArray, 'overwrite', 'YMD', 'flat', $group_id, $dataLogging,
											  false, true, true, false, array(), false, true, true);
			// Set number of calc values updated
			if (empty($saveResponse['errors'])) {
				$calcValuesUpdated = $saveResponse['item_count'];
			} else {
				$calcValuesUpdated = $saveResponse['errors'];
			}
		}
		// Return number of calculations that were updated/saved
		return $calcValuesUpdated;
	}

    /**
     * exclusionList - get Exclusion list of calc fields that are marked as excluded.
     */
//    public function exclusionList($projectId = 0) 
    public function exclusionList($Proj) 
    {
        $projectId = $Proj->project_id;
        
        if ($projectId == 0) {
            return null;
        }

        $excluded = array();
        //global $Proj, $longitudinal;
        global $longitudinal;

        $hasRepeatingFormsEvents = $Proj->hasRepeatingFormsEvents();

        // NOTE: rule H is case 'pd-10':  and thus "10" used here.  A hard coded value you say, well, REDCap has it cast in code stone pervasively this way, so we can rely on this value being fixed.
        $sql = 'SELECT record, event_id, field_name, instance FROM redcap_data_quality_status WHERE pd_rule_id = 10 AND project_id = ? AND exclude = 1';
        
        $exclusions = $this->sqlQueryAndExecute($sql, [$projectId]);
        
        if (!$exclusions) {
            return $excluded;
        }
        
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
     * sqlQueryAndExecute - encapsulate some of the repeative details.  pass one param or many params.
     */
    private function sqlQueryAndExecute($sql, $params = null)
    {
        $queryResult = null;
        
        try {
            $query = $this->module->createQuery();
            
            $this->queryHandle = $query;
            
            if ($params == null) {
                $params = [];
            }

            if ($query === false) {
                $error = db_error();
                $this->errFlag = true;
                $this->errMsg = 'ERROR: ' . $sql . ' err:' . $error;

                return false;
            }

            $query->add($sql, $params);

            $queryResult = $query->execute();
          
            return $queryResult;
      }
      catch (Throwable $e){
        $this->errFlag = true;
        $this->errMsg = 'ERROR: ' . $sql . ' err:' . $e->__toString();
      }

      return false;
    }
         
} // ** END CLASS CalcScannerSupport


?>
