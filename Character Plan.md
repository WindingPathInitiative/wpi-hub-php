#Goal
* Sporebound characters are currently made at characters.sporebound.com
* User hub is available at members.windingpath.club

Goal #1: let users at the end of the sporebound character wizard save their character to a character db on the user hub
Goal #2: let users submit character to sporebound game for approval
Goal #3: let users submit downtimes for advancements and approval

# Tables
## Character table
* ID
* Name
* user_id
* org_unit_id
* character_json
  * Starting character
  * Improvements added on
## Improvements table
* ID
  * character_id
  * date added
  * date approved
  * improvements JSON
    * Contains both advancements and downtime changes

