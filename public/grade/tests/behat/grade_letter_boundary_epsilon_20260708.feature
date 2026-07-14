@core @core_grades
Feature: Floating-point epsilon on letter grade boundary comparisons in gradebook version 20260708.
  In order to ensure that the floating-point epsilon fix is applied correctly
  As a developer
  I need to confirm that unfrozen courses apply the fix while frozen courses preserve the old behaviour.

  @javascript
  Scenario: An unfrozen course applies the epsilon fix so a grade at the boundary gets the higher letter.
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | student1 | Student | 1 | student1@example.com |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And the following "activities" exist:
      | activity | course | idnumber | name | intro | grade |
      | assign | C1 | a1 | Test assignment | Submit something! | 10 |
    And I am on the "Course 1" "grades > course grade settings" page logged in as "teacher1"
    And I set the following fields to these values:
      | Grade display type | Letter |
    And I press "Save changes"
    And I navigate to "View > Grader report" in the course gradebook
    And I turn editing mode on
    And I give the grade "8.7" to the user "Student 1" for the grade item "Test assignment"
    And I press "Save changes"
    Then the following should exist in the "user-grades" table:
      | -1-       |  -2-                    | -3- | -4- |
      | Student 1 |  student1@example.com   | B+  | B+  |

  @javascript
  Scenario: A course frozen at version 20260708 skips the epsilon fix so a grade at the boundary gets the lower letter.
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And gradebook calculations for the course "C1" are frozen at version "20260708"
    And the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | student1 | Student | 1 | student1@example.com |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And the following "activities" exist:
      | activity | course | idnumber | name | intro | grade |
      | assign | C1 | a1 | Test assignment | Submit something! | 10 |
    And I am on the "Course 1" "grades > course grade settings" page logged in as "teacher1"
    And I set the following fields to these values:
      | Grade display type | Letter |
    And I press "Save changes"
    And I navigate to "View > Grader report" in the course gradebook
    And I turn editing mode on
    And I give the grade "8.7" to the user "Student 1" for the grade item "Test assignment"
    And I press "Save changes"
    Then the following should exist in the "user-grades" table:
      | -1-       |  -2-                    | -3- | -4- |
      | Student 1 |  student1@example.com   | B   | B   |
