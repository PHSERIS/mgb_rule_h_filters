# Rule H Filters - which can run a subset of records by some choice criteria in a project context.

An external module to run the RULE H processing on a selected grouping of records.
********************************************************************************

## Version

Version 1.3.4

********************************************************************************

## Release notes

1.3.4 Enhancement correction: remove js/jquery.min.js from JS files as unneeded and to comply with consortium submission process.

1.3.3 Enhancement correction: Fix a couple direct calls to use standard REDCap calls.

1.3.2 Bug Fix: PSALM fixes and adjustments.

1.3.1 Fix to use turbo which will also bypass using Form as a criteria related to Speed boost option.

1.3.0 Feature improvement optimize DAG and Time record selection process.  Speed boost option.

1.2.1 Feature improvements. Paging of record selection via DAG lists. Change duallistbox to not jump to top after selection. Sends an optional email to user when done. START and FINISH log tags to make easier to parse log. Duration process time.

1.2.0 Features and Enhancements.

1.1.2 Modifications - Fix object ref use this instead of module in the class.

1.1.1 Modifications - Fix CheckMarx reported trivial issues.

1.1.0 Modifications - Fix Repeating data handling. Other code touch up.

1.0.0 Initial Development - Building Rule H Filters handling for On Demand.

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

### Batch and Spooling

* Purpose of the two (Batch and Spooling): allow feeding small numbers of records to Rule H process to manage completing within time limitations.

* Batch: a subset of the list of records that are possible with a given selection criteria: DAGs, Timeframe, Forms, Events.
    - setting: **BATCH SIZE Number of records to pull**
        - value 0: select entire list
        - value any positive number: limit selection size to this number
    - list of records that fit your search selection choices, possibly taking small sections of the whole list (batch portion or entire list), which then hand to, the spooling process.
    - processing will move across the list, in batch size portions, until complete walk through the list.

* Spooling: using a full list or a batch portion, run Rule H processing on sections of the batch ( whether it is a subset or a full list ).
    - setting: **SPOOL SIZE CHUNK Spooling Chunk Size**
        - value none: defaults to 100
        - value any positive number: size of chunk to process.
    - given the list of records, process small amounts, chunks, of the given list. And run through the given list in chunk sizes until done.

* Batch (full or portion) feeds to Spooling which divides the portion into smaller pieces to feed to Rule H process allowing timely processing. 
    - divide a pie into slices, divide a slice into forkfuls to chew.  Feeding the full pie to chew in one gulp will choke the system.  Taking smaller bites, allows eating the whole pie in practical effort and time.

### Spooling

The selected records will be processed in groups, spooling through the total number of records.

Example: 1000 records chosen in your selection results.  A first batch of 100 records will be processed, then the next 100, until all done.

This is server side processed and you will not see feedback.  However, you can monitor the **Logging** for the project and see the progress.

The spool size is 100 by Default and can be set in the config settings: "Spooling Chunk Size" (enter a number)

Spooling can be switched OFF (it is on by Default), see: "Spooling Switch OFF".  It is not recommended to switch spooling off.  Spooling gives us more manageable processing to avoid overtaxing the RULE H core processor.

### Emailing Notice when done

* Two options:
    1. Configure in the settings an email address.
        - and set the flag to send email
    2. On the process screen, enter an email address.
        - Notify me when DONE send EMAIL to address: Email Address here
        - using the screen email will OVERRIDE the configure setting
        - also if the field is used will automatically set the flag to send an email
    - if an email is used, when the Spooling process is finished, will send an email to the address upon completion.
        - handy to have a notice when completed so you would not have to watch the process, especially for long process times.
    - email is sent for processing only, will not send email for previews.

### Speed Boost Option

* It may help improve speed performance if check the Speed Boost config option
    * Note: does not support DDE.

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

* Inspired by MGB REDCap Team. Lynn Simpson, Dimitar S. Dimitrov, Eduardo Morales

