<?php

// restrict api to module use only, protects from random unwanted hits
if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"RuleHFiltersExternalModule") == false ) { exit(); }

// config settings when you need to
$module->showConfigSettings();

?>
