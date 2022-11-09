# Rule H Filters - which can run a subset of records by some choice criteria in a project context.

An external module to run the RULE H processing on a selected grouping of records.
********************************************************************************

## Version

Version 1.1.0

********************************************************************************

## Getting Started

Activate the EM in your project.

********************************************************************************

## Operation

In your project, choose the side menu item, RULE H: Filters Processing MENU, and proceed.

On the screen, open each section (DAGs, Time, Form, Event), choose the elements you want.

Open each section by choosing it. (also which Closes the previous section)

The **-> ->** arrows will move all to the selection box.

The **<- <-** will remove them from the selection box.

1. **DAG**s, select a DAG item, several, or ALL of them.  

1. **Time Frame**, either the **PAST 24 hours**, or the **PAST 7 days**, or **neither**.
> Limits to what records may have been processed or touched in that time.
>
> The button **Reset Time Frame** will clear the radio button selected item.

1. **Form**, select one or more forms, which may have records related with data in the form.

1. **Events**, select one or more events, which may have records related with data in that event.

The combination of each (DAGs, Time, Forms, Events), will narrow down, as a group of records.
By default, the criteria is AND logic, so any common records amongst the selected sections will be grouped.

### Grouping logics

A config setting for the project can switch to OR Logic. see: Merge Type of DAGs, Time, Forms, Events
>
>	Unchecked is AND Logic (Default)
>
>	Checked is OR Logic

Example (by record IDs): 

**Selected Records**

> DAGs: 1,2,3,4,5

> Time:  2,3

> Forms: 2,3,4,9,12

**AND Logic**

> Result:  2,3

> **TOTAL is TWO records.**

**OR Logic**

> Result: 1,2,3,4,5,9,12

> **TOTAL is SEVEN records.**

### Preview and Process

1. To view what records are in your resulting selection, choose, **Record List PREVIEW**.
> A listing of RECORDs will be shown.  If the list is large, a scrollable window contains the listing.

2. To initiate processing, choose, **Update calcs now: SUBMIT TO PROCESS**
> The result count of processed records will show.

NOTE: Any records which have exclusions, will be honored for the RULE H processing.  Meaning, if a record calc is marked as excluded, that will be excluded from the listing when processed.  YES, if a calc is excluded, it excludes the WHOLE record.


### Spooling

The selected records will be processed in groups, spooling through the total number of records.

Example: 1000 records chosen in your selection results.  A first batch of 100 records will be processed, then the next 100, until all done.

This is server side processed and you will not see feedback.  However, you can monitor the **Logging** for the project and see the progress.

The spool size is 100 by Default and can be set in the config settings: "Spooling Chunk Size" (enter a number)

Spooling can be switched OFF (it is on by Default), see: "Spooling Switch OFF".  It is not recommended to switch spooling off.  Spooling gives us more manageable processing to avoid overtaxing the RULE H core processor.

### Notes

1. The **SHOW section LIST IDS** are more for diagnostic purposes, and shows a list of the values being used in each selection criteria.

********************************************************************************

## Deployment

Regular external module enable and configuration.


********************************************************************************
## Authors

* **David Heskett** - *Initial work* 

## License

This project is licensed under the MIT License - see the [LICENSE](?prefix=mgb_rule_h_filters&page=LICENSE.md) file for details

## Acknowledgments

* Inspired by MGB RISC REDCap Team. Lynn Simpson, Dimitar S. Dimitrov, Eduardo Morales, David Clark

