<?php
/**
 * This package helps with type and foreign key aware rapid development of forms
 * as well as many options to allow the advanced features to be used in a production
 * environment.
 *
 * All the settings for FormBuilder must be in a section [DB_DataObject_FormBuilder]
 * within the DataObject.ini file (or however you've named it).
 * If you stuck to the DB_DataObject example in the doc, you'll read in your
 * config like this:
 * <code>
 *  $config = parse_ini_file('DataObject.ini', true);
 *  foreach ($config as $class => $values) {
 *      $options = &PEAR::getStaticProperty($class, 'options');
 *      $options = $values;
 *  }
 * </code>
 * Unfortunately, DataObject will overwrite FormBuilder's settings when first instantiated,
 * so you'll have to add another line after that:
 * <code>
 *  $_DB_DATAOBJECT_FORMBUILDER['CONFIG'] = $config['DB_DataObject_FormBuilder'];
 * </code>
 * Now you're ready to go!
 *
 * You can also set any option through your DB_DataObject derived classes by
 * appending 'fb_' to the option name. Ex: 'fb_fieldLabels'. This is the
 * preferred way of setting DataObject-specific options.
 *
 * You may also set all options manually by setting them in the DO ir FB objects.
 *
 * You may also set the options in an FB derived class, but this isn't as well
 * supported.
 *
 * In addition, there are special methods you can define in your DataObject classes for even more control.
 * <ul>
 *  <li>preGenerateForm(&$formBuilder):
 *   This method will be called before the form is generated. Use this to change
 *   property values or options in your DataObject. This is the normal plave to
 *   set up fb_preDefElements. Note: the $formBuilder object passed in has not
 *   yet copied the options from the DataObject into it. If you plan to use the
 *   functions in FB in this method, call populateOptions() on it first.
 *  </li>
 *  <li>postGenerateForm(&$form, &$formBuilder):
 *   This method will be called after the form is generated. The form is passed in by reference so you can
 *   alter it. Use this method to add, remove, or alter elements in the form or the form itself.
 *  </li>
 *  <li>preProcessForm(&$values, &$formBuilder):
 *   This method is called just before FormBuilder processes the submitted form data. The values are sent
 *   by reference in the first parameter as an associative array. The key is the element name and the value
 *   the submitted value. You can alter the values as you see fit (md5 on passwords, for example).
 *  </li>
 *  <li>postProcessForm(&$values, &$formBuilder):
 *   This method is called just after FormBuilder processed the submitted form data. The values are again
 *   sent by reference. This method could be used to inform the user of changes, alter the DataObject, etc.
 *  </li>
 *  <li>getForm($action, $target, $formName, $method, &$formBuilder):
 *   If this function exists, it will be used instead of FormBuilder's internal form generation routines
 *   Use this only if you want to create the entire form on your own.
 *  </li>
 * </ul>
 *
 * Note for PHP5-users: These properties have to be public! In general, you can
 * override all settings from the .ini file by setting similarly-named properties
 * in your DataObject classes.
 *
 * <b>Most basic usage:</b>
 * <code>
 *  $do =& new MyDataObject();
 *  // Insert "$do->get($some_id);" here to edit an existing object instead of making a new one
 *  $fg =& DB_DataObject_FormBuilder::create($do);
 *  $form =& $fg->getForm();
 *  if ($form->validate()) {
 *      $form->process(array(&$fg,'processForm'), false);
 *      $form->freeze();
 *  }
 *  $form->display();
 * </code>
 *
 * For more information on how to use the DB_DataObject or HTML_QuickForm packages
 * themselves, please see the excellent documentation on http://pear.php.net/.
 *
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   DB
 * @package    DB_DataObject_FormBuilder
 * @author     Markus Wolff <mw21st@php.net>
 * @author     Justin Patrin <papercrane@reversefold.com>
 * @copyright  1997-2005 The PHP Group
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    $Id: FormBuilder.php,v 1.150 2005/04/11 22:46:58 justinpatrin Exp $
 * @link       http://pear.php.net/package/DB_DataObject_FormBuilder
 * @see        DB_DataObject, HTML_QuickForm
 */

// Import requirements
require_once ('DB/DataObject.php');

// Constants used for forceQueryType()
define ('DB_DATAOBJECT_FORMBUILDER_QUERY_AUTODETECT',    0);
define ('DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEINSERT',   1);
define ('DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEUPDATE',   2);
define ('DB_DATAOBJECT_FORMBUILDER_QUERY_FORCENOACTION', 3);

// Constants used for cross/triple links
define ('DB_DATAOBJECT_FORMBUILDER_CROSSLINK',   1048576);
define ('DB_DATAOBJECT_FORMBUILDER_TRIPLELINK',  2097152);
define ('DB_DATAOBJECT_FORMBUILDER_ENUM',        4194304);
define ('DB_DATAOBJECT_FORMBUILDER_REVERSELINK', 8388608);
define ('DB_DATAOBJECT_FORMBUILDER_GROUP',      16777216);

// Error code constants
define ('DB_DATAOBJECT_FORMBUILDER_ERROR_UNKNOWNDRIVER', 4711);
define ('DB_DATAOBJECT_FORMBUILDER_ERROR_NODATAOBJECT',  4712);

/**
 * This is the main class for FormBuilder. It does most of the real work.
 *
 * This class deals with all output agnostic portions of work, although
 * there are still some bits of HTML generation and such to be cleaned out.
 *
 * You cannot use this class on its own. You must use a driver. The correct
 * way to instantiate a driver is:
 * <code>
 * $do =& DB_DataObject::factory('someTable');
 * $options = array('someOption' +> 'someValue');
 * $formBuilder =& DB_DataObject_FormBuilder::create($do, $options, 'DriverName');
 * </code>
 * The easiest form, if you need to set no options via the create() and you wish
 * to use HTML_QuickForm is:
 * <code>
 * $do =& DB_DataObject::factory('someTable');
 * $formBuilder =& DB_DataObject_FormBuilder::create($do);
 * </code>
 *
 * @see DB_DataObject_FormBuilder_QuickForm
 */
class DB_DataObject_FormBuilder
{
    //PROTECTED vars
    /**
     * If you want to use the generator on an existing form object, pass it
     * to the factory method within the options array, element name: 'form'
     * (who would have guessed?)
     *
     * @access protected
     * @see DB_DataObject_Formbuilder()
     */
    var $_form = false;

    /**
     * Contains the last validation errors, if validation checking is enabled.
     *
     * @access protected
     */
    var $_validationErrors = false;

    /**
     * Used to determine which action to perform with the submitted data in processForm()
     *
     * @access protected
     */
    var $_queryType = DB_DATAOBJECT_FORMBUILDER_QUERY_AUTODETECT;
    
    /**
     * If false, FormBuilder will use the form object from $_form as a basis for the new
     * form: It will just add elements to the existing form object, not generate a new one.
     * If true, FormBuilder will generate a new form object, create all elements as needed for
     * the given DataObject, then strip the elements from the exiting form object in $_form
     * and add it to the newly generated form object.
     *
     * @access protected
     */
    var $_appendForm = false;
    


    //PUBLIC vars
    /**
     * Add a header to the form - if set to true, the form will
     * have a header element as the first element in the form.
     *
     * @access public
     * @see formHeaderText
     */
    var $addFormHeader = true;

    /**
     * Text for the form header. If not set, the name of the database
     * table this form represents will be used.
     *
     * @access public
     * @see addFormHeader
     */
    var $formHeaderText = null;

    /**
     * Text that is displayed as an error message if a validation rule
     * is violated by the user's input. Use %s to insert the field name.
     *
     * @access public
     * @see requiredRuleMessage
     */
    var $ruleViolationMessage = '%s: The value you have entered is not valid.';
    
    /**
     * Text that is displayed as an error message if a required field is
     * left empty. Use %s to insert the field name.
     *
     * @access public
     * @see ruleViolationMessage
     */
    var $requiredRuleMessage = 'The field %s is required.';

    /**
     * If set to TRUE, the current DataObject's validate method is being called
     * before the form data is processed. If errors occur, no insert/update operation
     * will be made on the database. Use getValidationErrors() to retrieve the reasons
     * for a failure.
     * Defaults to FALSE.
     *
     * @access public
     */
    var $validateOnProcess = false;

    /**
     * The language used in date fields. See documentation of HTML_Quickform's
     * date element for more information.
     *
     * @see HTML_QuickForm_date
     */
    var $dateFieldLanguage = 'en';
    
    /**
     * Callback method to convert a date from the format it is stored
     * in the database to the format used by the QuickForm element that
     * handles date values. Must have a format usable with call_user_func().
     */
    var $dateFromDatabaseCallback = array('DB_DataObject_FormBuilder','_date2array');
    
    /**
     * Callback method to convert a date from the format used by the QuickForm
     * element that handles date values to the format the database can store it in. 
     * Must have a format usable with call_user_func().
     */
    var $dateToDatabaseCallback = array('DB_DataObject_FormBuilder','_array2date');
    
    /**
     * A format string that represents the display settings for QuickForm date elements.
     * Example: "d-m-Y". See QuickForm documentation for details on format strings.
     * Legal letters to use in the format string that work with FormBuilder are:
     * d,m,Y,H,i,s
     */
    var $dateElementFormat = 'd-m-Y';

    /**
     * A format string that represents the display settings for QuickForm time elements.
     * Example: "H:i:s". See QuickForm documentation for details on format strings.
     * Legal letters to use in the format string that work with FormBuilder are:
     * d,m,Y,H,i,s
     */
    var $timeElementFormat = 'H:i:s';

    /**
     * A format string that represents the display settings for QuickForm datetime elements.
     * Example: "d-m-Y H:i:s". See QuickForm documentation for details on format strings.
     * Legal letters to use in the format string that work with FormBuilder are:
     * d,m,Y,H,i,s
     */
    var $dateTimeElementFormat = 'd-m-Y H:i:s';

    /**
     * This is for the future support of string date formats other than ISO, but
     * currently, that's the only supported one. Set to 1 for ISO, other values
     * may be available later on.
     */
    var $dbDateFormat = 1;

    /**
     * These fields will be used when displaying a link record. The fields
     * listed will be seperated by ", ". If you specify a link field as a
     * display field and linkDisplayLevel is not 0, the link will be followed
     * and the display fields of the record linked to displayed within parenthesis.
     * 
     * For example, say we have these tables:
     * 
     * [person]
     * 
     * name = 130
     * gender_id = 129
     * 
     * [gender]
     * id = 129
     * name = 130
     * 
     * 
     * this link:
     * 
     * [person]
     * gender_id = gender:id
     * 
     * 
     * and this data:
     * Person:
     * name: "Justin Patrin"
     * gender_id: 1
     * Gender:
     * id: 1
     * name: "male"
     * 
     * If person's display fields are:
     * <?php
     * class DataObject_Person extends DB_DataObject {
     *   //...
     *   var $fb_linkDisplayFields = array('name', 'gender_id');
     * }
     * ?>
     * 
     * and gender's display fields are:
     * <?php
     * class DataObject_Gender extends DB_DataObject {
     * //...
     *   var $fb_linkDisplayFields = array('name');
     * }
     * ?>
     * 
     * and we set linkDisplayLevel to 0, the person record will be displayed as:
     * "Justin Patrin, 1"
     * 
     * If we set linkDisplayLevel to 1, the person record will be displayed as:
     * "Justin Patrin, (male)"
     */
    var $linkDisplayFields = array();

    /**
     * The fields to be used for sorting the options of an auto-generated link
     * element. You can specify ASC and DESC in these options as well:
     * <?php
     * class DataObject_SomeTable extends DB_DataObject {
     *   //...
     *   var $fb_linkOrderFields = array('field1', 'field2 DESC');
     * }
     * ?>
     * 
     * You may also want to escape the field names if they are reserved words in
     * the database you're using:
     * <?php
     * class DataObject_SomeTable extends DB_DataObject {
     *   //...
     *   function preGenerateForm() {
     *     $db = $this->getDatabaseConnection();
     *     $this->fb_linkOrderFields = array($db->quoteIdentifier('config'),
     *                                       $db->quoteIdentifier('select').' DESC');
     *   }
     * }
     * ?>
     */
    var $linkOrderFields = array();

    /**
     * If set to true, all links which are renderered as select boxes (see linkElementTypes)
     * will have a "--New Value--" option on the bottom which displays a form for the linked
     * to table for creating a new record. Upon submit, the sub-form will be checked for
     * validity as normal and, if valid, will be used to enter a new record in the linked-to
     * table which this record will now link to.
     *
     * If set to false, all link select boxes will only have entered options (as normal)
     * If set to true, all link select boxes will have a New Value entry
     * You may also set this to be an array with the names of the link fields to create
     *   New Value entries for.
     */
    var $linkNewValue = array();

    /**
     * The caption of the submit button, if created.
     */
    var $submitText = 'Submit';

    /**
     * If set to false, no submit button will be created for your forms. Useful when
     * used together with QuickForm_Controller when you already have submit buttons
     * for next/previous page. By default, a button is being generated.
     */
    var $createSubmit = true;

    /**
     * Array of field labels. The key of the element is the field name. Use this if
     * you want to keep the auto-generated elements, but still define your
     * own labels for them.
     */
    var $fieldLabels = array();

    /**
     * Array of fields to render elements for. If a field is not given, it will not
     * be rendered. If empty, all fields will be rendered (except, normally, the
     * primary key).
     */
    var $fieldsToRender = array();

    /**
     * Array of fields which the user can edit. If a field is rendered but not
     * specified in this array, it will be frozen. Ignored if not given.
     */
    var $userEditableFields = array();
    
    /**
     * Array of fields for which no auto-rules may be generated.
     * If set to string "__ALL__", no rules are generated for any field.
     */
    var $excludeFromAutoRules = array();

    /**
     * Array of groups to put certain elements in. The key is an element name, the
     * value is the group to put the element in.
     */
    var $preDefGroups = array();

    /**
     * Indexed array of element names. If defined, this will determine the order
     * in which the form elements are being created. This is useful if you're
     * using QuickForm's default renderer or dynamic templates and the order of
     * the fields in the database doesn't match your needs.
     */
    var $preDefOrder = array();

    /**
     * Array of user-defined QuickForm elements that will be used for the field
     * matching the array key. If no match is found, the element for that field
     * will be auto-generated. Make your element objects either in the
     * preGenerateForm() method or in the getForm() method. Use
     * HTML_QuickForm::createElement() to do this.
     *
     * If you wish to put in a group of elements in place of a single element, 
     * you can put an array in preDefElements instead of a single element. The
     * name of the group will be the name of the replaced element.
     */
    var $preDefElements = array();

    /**
     * An array of the link or date fields which should have an empty option added to the
     * select box. This is only a valid option for fields which link to another
     * table or date fields.
     */
    var $selectAddEmpty = array();

    /**
     * An string to put in the "empty option" added to select fields
     */
    var $selectAddEmptyLabel = '';

    /**
     * By default, hidden fields are generated for the primary key of a
     * DataObject. This behaviour can be deactivated by setting this option to
     * false.
     */
    var $hidePrimaryKey = true;

    /**
     * A simple array of field names indicating which of the fields in a particular
     * table/class are actually to be treated as textareas. This is an unfortunate
     * workaround that is neccessary because the DataObject generator script does
     * not make a difference between any other datatypes than string and integer.
     * When it does, this can be dropped.
     */
    var $textFields = array();

    /**
     * A simple array of field names indicating which of the fields in a particular
     * table/class are actually to be treated date fields. This is an unfortunate
     * workaround that is neccessary because the DataObject generator script does
     * not make a difference between any other datatypes than string and integer.
     * When it does, this can be dropped.
     */
    var $dateFields = array();

    /**
     * A simple array of field names indicating which of the fields in a particular
     * table/class are actually to be treated time fields. This is an unfortunate
     * workaround that is neccessary because the DataObject generator script does
     * not make a difference between any other datatypes than string and integer.
     * When it does, this can be dropped.
     */
    var $timeFields = array();

    /**
     * Array to configure the type of the link elements. By default, a select box
     * will be used. The key is the name of the link element. The value is 'radio'
     * or 'select'. If you choose 'radio', radio buttons will be made instead of
     * a select box.
     */
    var $linkElementTypes = array();

    /**
     * A simple array of fields names which should be treated as ENUMs. A select
     * box will be created with the enum options. If you add this field to the
     * linkElementTypes array and give it a 'radio' type, you will get radio buttons
     * instead.
     *
     * The default handler for enums is only tested in mysql. If you are using a
     * different DB backend, use enumOptionsCallback or enumOptions.
     */
    var $enumFields = array();

    /**
     * A valid callback which will return the options in a simple array of strings
     * for an enum field given the table and field names. 
     */
    var $enumOptionsCallback = array();

    /**
     * An array which holds enum options for specific fields. Each key should be a
     * field in the current table and each value holds a an array of strings which
     * are the possible values for the enum. This will only be used if the field is
     * listed in enumFields.
     */
    var $enumOptions = array();

    /**
     * An array which holds the field names of those fields which are booleans.
     * They will be displayed as checkboxes.
     */
    var $booleanFields = array();

    /**
     * The text to put between crosslink elements.
     */
    var $crossLinkSeparator = '<br/>';

    /**
     * If this is set to 1 or above, links will be followed in the display fields
     * and the display fields of the record linked to will be used for display.
     * If this is set to 2, links will be followed in the linked record as well.
     * This can be set to any number of links you wish but could easily slow down
     * your application if set to more than 1 or 2 (but only if you have links in
     * your display fields that go that far ;-)). For a more in-depth example, see
     * the docs for linkDisplayFields.
     */
    var $linkDisplayLevel = 0;

    /**
     * The crossLinks array holds data pertaining to many-many links. If you
     * have a table which links two tables together, you can use this to
     * automatically create a set of checkboxes or a multi-select on your form.
     * The simplest way of using this is:
     * <code>
     * <?php
     * class DataObject_SomeTable extends DB_DataObject {
     * //...
     *   var $fb_crossLinks = array(array('table' => 'crossLinkTable'));
     * }
     * ?>
     * </code>
     * Where crossLinkTable is the name of the linking table. You can have as
     * many cross-link entries as you want. Try it with just the table ewntry
     * first. If it doesn't work, you can specify the fields to use as well.
     * <code>
     * 'fromField' => 'linkFieldToCurrentTable' //This is the field which links to the current (from) table
     * 'toField' => 'linkFieldToLinkedTable' //This is the field which links to the "other" (to) table
     * </code>
     * To get a multi-select add a 'type' key which it set to 'select'.
     * <code>
     * <?php
     * class DataObject_SomeTable extends DB_DataObject {
     *     //...
     *     var $fb_crossLinks = array(array('table' => 'crossLinkTable', 'type' => 'select'));
     * }
     * ?>
     * </code>
     * An example: I have a user table and a group table, each with a primary
     * key called id. There is a table called user_group which has fields user_id
     * and group_id which are set up as links to user and group. Here's the
     * configuration array that could go in both the user DO and the group DO:
     * <code>
     * <?php
     * $fb_crossLinks = array(array('table' => 'user_group'));
     * ?>
     * </code>
     * Here is the full configuration for the user DO:
     * <code>
     * <?php
     * $fb_crossLinks = array(array('table' => 'user_group',
     *                              'fromField' => 'user_id',
     *                              'toField' => 'group_id'));
     * ?>
     * </code>
     * And the full configuration for the group DO:
     * <code>
     * <?php
     * $fb_crossLinks = array(array('table' => 'user_group',
     *                              'fromField' => 'group_id',
     *                              'toField' => 'user_id'));
     * ?>
     * </code>
     * 
     * You can also specify the seperator between the elements with crossLinkSeperator.
     */
    var $crossLinks = array();

    /**
     * You can also specify extra fields to edit in the a crossLink table with
     * this option. For example, if the user_group table mentioned above had
     * another field called 'role' which was a text field, you could specify it
     * like this in the user_group DataObject class:
     * <code>
     * <?php
     * class DataObject_User_group extends DB_DataObject {
     *   //normal stuff here
     *  
     *   var $fb_crossLinkExtraFields = array('role');
     * }
     * ?>
     * </code>
     *
     * This would cause a text box to show up next to each checkbox in the
     * user_group section of the form for the field 'role'.
     *
     * You can specify as many fields as you want in the 'extraFields' array.
     *
     * Note: If you specify a linked field in 'extraFields' you'll get a select
     * box just like when you do a normal link field in a FormBuilder form. :-)
     */
    var $crossLinkExtraFields = array();

    /**
     * Holds triple link data.
     *   The tripleLinks array can be used to display checkboxes for "triple-links". A triple link is set
     *   up with a table which links to three different tables. These will show up as a table of checkboxes
     *   The initial setting (table) is the same as for crossLinks. The field configuration keys (if you
     *   need them) are:
     *   <code>
     *    'fromField'
     *    'toField1'
     *    'toField2'
     *   </code>
     */
    var $tripleLinks = array();

    /**
     * Holds reverseLink configuration.
     * A reverseLink is a table which links back to the current table. For
     * example, let say we have a "gender" table which has Male and Female in it
     * and a "person" table which has the fields "name", which holds the person's
     * name and "genre_id" which links to the genre. If you set up a form for the
     * gender table, the person table can be a reverseLink. The setup in the
     * gender table would look like this:
     * <code>
     * <?php
     * class DataObject_Gender extends DB_DataObject {
     * //normal stuff here
     * 
     *   var $fb_reverseLinks = array(array('table' => 'person'));
     * }
     * ?>
     * </code>
     * Now a list of people will be shown in the gender form with a checkbox next
     * to it which is checked if the record currently links to the one you're
     * editing. In addition, some special text will be added to the end of the
     * label for the person record if it's linked to another gender.
     * 
     * Say we have a person record with the name "Justin Patrin" which is linked
     * to the gender "Male". If you view the form for the gender "Male", the
     * checkbox next to "Justin Patrin" will be checked. If you choose the
     * "Female" gender the checkbox will be unchecked and it will say:
     * Justin Patrin - currently linked to - Male
     * 
     * If the link field is set as NOT NULL then FormBuilder will not process
     * and unchecked checkbox unless you specify a default value to set the link
     * to. If null is allowed, the link will be set to NULL. To specify a default
     * value:
     * <code>
     * <?php
     * class DataObject_Gender extends DB_DataObject {
     * //normal stuff here
     * 
     *   var $fb_reverseLinks = array(array('table' => 'person',
     *                                      'defaultLinkValue' => 5));
     * }
     * ?>
     * </code>
     * Now if a checkbox is unchecked the link field will be set to 5...whatever
     * that means. Be careful here as you need to make sure you enter the correct
     * value here (probably the value of the primary key of the record you want
     * to link to by default).
     * 
     * You may also set the text which is displayed between the record and the
     * currently linked to record.
     * <code>
     * <?php
     * class DataObject_Gender extends DB_DataObject {
     *     //normal stuff here
     * 
     *   var $fb_reverseLinks = array(array('table' => 'person',
     *                                      'linkText' => ' is currently listed as a '));
     * }
     * ?>
     * </code>
     * If you select "Female" the Justin Patrin entry would now say:
     * Justin Patrin__ is currently listed as a Male__
     */
    var $reverseLinks = array();

    /**
     * If set to true, validation rules will also be client side.
     */
    var $clientRules = false;

    /**
     * A string to prepend to element names. Together with elementNamePostfix, this option allows you to
     * alter the form element names that FormBuilder uses to create and process elements. The main use for
     * this is to combine multiple forms into one. For example, if you wanted to use multiple FB forms for
     * the same table within one actual HTML form you could do something like this:
     * <?php
     * $do = DB_DataObject::factory('table');
     * $fb = DB_DataObject_FormBuilder::create($do);
     * $fb->elementNamePrefix = 'formOne';
     * $form = $fb->getForm();
     * 
     * $do2 = DB_DataObject::factory('table');
     * $fb2 = DB_DataObject_FormBuilder::create($do2);
     * $fb->elementNamePrefix = 'formTwo';
     * $fb->useForm($form);
     * $form = $fb->getForm();
     * 
     * //normal processing here
     * ?>
     * 
     * If you assume that "table: has one field, "name", then the resultant form will have two elements:
     * "formOnename" and "formTwoname".
     *
     * Please note: You *cannot* use '[' or ']' anywhere in the prefix or postfix. Doing so
     * will cause FormBuilder to not be able to process the form.
     */
    var $elementNamePrefix = '';

    /**
     * A postfix to put after element names in the form
     * @see DB_DataObject_FormBuilder::elementNamePrefix
     */
    var $elementNamePostfix = '';

    /**
     * Whether or not to use call-time-pass-by-reference when calling DataObject callbacks
     */
    var $useCallTimePassByReference = false;

    /**
     * DB_DataObject_FormBuilder::create()
     *
     * Factory method. As this is meant as an abstract class, it is the only supported
     * method to make a new object instance. Pass the DataObject-derived class you want to
     * build a form from as the first parameter. Use the second to pass additional options.
     *
     * Options can be:
     * - 'ruleViolationMessage' : See description of similarly-named class property
     * - 'requiredRuleMessage' : See description of similarly-named class property
     * - 'addFormHeader' : See description of similarly-named class property
     * - 'formHeaderText' : See description of similarly-named class property
     *
     * The third parameter is the name of a driver class. A driver class will take care of
     * the actual form generation. This way it's possible to have FormBuilder build different
     * forms for different types of output media from the same set of DataObjects.
     *
     * Currently available driver classes:
     * - QuickForm (stable)
     * - XUL (experimental!)
     *
     * @param object $do      The DB_DataObject-derived object for which a form shall be built
     * @param array $options  An optional associative array of options.
     * @param string $driver  Optional: Name of the driver class for constructing the form object. Default: QuickForm.
     * @access public
     * @returns object        DB_DataObject_FormBuilder or PEAR_Error object
     */
    function &create(&$do, $options = false, $driver = 'QuickForm')
    {
        if (!is_a($do, 'db_dataobject')) {
            $err =& PEAR::raiseError('DB_DataObject_FormBuilder::create(): Object does not extend DB_DataObject.',
                                     DB_DATAOBJECT_FORMBUILDER_ERROR_NODATAOBJECT);
            return $err;
        }
        
        @include_once('DB/DataObject/FormBuilder/'.$driver.'.php');
        $className = 'db_dataobject_formbuilder_'.strtolower($driver);
        if (class_exists($className)) {
            $obj = &new $className($do, $options);
            return $obj;
        }
        $err =& PEAR::raiseError('DB_DataObject_FormBuilder::create(): Driver class "'.$className.'" not found.',
                                 DB_DATAOBJECT_FORMBUILDER_ERROR_UNKNOWNDRIVER);
        return $err;        
    }


    /**
     * DB_DataObject_FormBuilder::DB_DataObject_FormBuilder()
     *
     * The class constructor.
     *
     * @param object $do      The DB_DataObject-derived object for which a form shall be built
     * @param array $options  An optional associative array of options.
     * @access public
     */
    function DB_DataObject_FormBuilder(&$do, $options = false)
    {
        // Set default callbacks first!
        $this->dateToDatabaseCallback = array(&$this, '_array2date');
        $this->dateFromDatabaseCallback = array(&$this, '_date2array');
        $this->enumOptionsCallback = array(&$this, '_getEnumOptions');
        
        // Read in config
        $vars = get_object_vars($this);
        if (isset($GLOBALS['_DB_DATAOBJECT_FORMBUILDER']['CONFIG'])) {
            //read all config options into member vars
            foreach ($GLOBALS['_DB_DATAOBJECT_FORMBUILDER']['CONFIG'] as $key => $value) {
                if (in_array($key, $vars) && $key[0] != '_') {
                    $this->$key = $value;
                }
            }
        }
        if (is_array($options)) {
            reset($options);
            while (list($key, $value) = each($options)) {
                if (in_array($key, $vars) && $key[0] != '_') {
                    $this->$key = $value;
                }
            }
        }
        
        $defVars = get_class_vars(get_class($this));
        foreach($defVars as $member => $value) {
            if (is_array($value) && isset($this->$member) && is_string($this->$member)) {
                $this->$member = $this->_explodeArrString($this->$member);
            }
        }
        
        // Check whether we now got valid callbacks for some callback properties,
        // otherwise correct them
        foreach(array('dateFromDatabaseCallback', 'dateToDatabaseCallback', 'enumOptionsCallback') as $callback) {
            if (!is_callable($this->$callback)) {
                if (is_array($this->$callback)
                    && count($this->$callback) == 1
                    && is_callable($this->{$callback}[0])) {
                    // Probably got messed up by _explodeArrString()
                    $this->$callback = $this->{$callback}[0];
                } else {
                    $this->$callback = $defVars[$callback];
                }
            }   
        }
        
        $this->_do = &$do;
    }

    /**
     * Gets the primary key field name for a DataObject
     * Looks for $do->_primary_key, $do->sequenceKey(), then $do->keys()
     *
     * @param DB_DataObject the DataObject to get the primary key of
     * @return string the name of the primary key or false if none is found
     */
    function _getPrimaryKey(&$do) {
        if (isset($do->_primary_key) && strlen($do->_primary_key)) {
            return $do->_primary_key;
        } elseif (($seq = $do->sequenceKey()) && isset($seq[0]) && strlen($seq[0])) {
            return $seq[0];
        } else {
            if (($keys = $do->keys()) && isset($keys[0]) && strlen($keys[0])) {
                return $keys[0];
            }
        }
        $this->debug('Error: Primary Key not found for table '.$do->tableName());
        return false;
    }

    /**
     * DB_DataObject_FormBuilder::_getEnumOptions()
     * Gets the possible values for an enum field from the DB. This is only tested in
     * mysql and will likely break on all other DB backends.
     *
     * @param string Table to query on
     * @param string Field to get enum options for
     * @return array array of strings, each being a possible value for th eenum field
     */    
    function _getEnumOptions($table, $field) {
        $db = $this->_do->getDatabaseConnection();
        if (isset($GLOBALS['_DB_DATAOBJECT']['CONFIG']['quote_identifiers']) && $GLOBALS['_DB_DATAOBJECT']['CONFIG']['quote_identifiers']) {
            $table = $db->quoteIdentifier($table);
        }
        $option = $db->getRow('SHOW COLUMNS FROM '.$table.' LIKE '.$db->quoteSmart($field), DB_FETCHMODE_ASSOC);
        if (PEAR::isError($option)) {
            return PEAR::raiseError('There was an error querying for the enum options for field "'.$field.'". You likely need to use enumOptionsCallback.', null, null, null, $option);
        }
        $option = substr($option['Type'], strpos($option['Type'], '(') + 1);
        $option = substr($option, 0, strrpos($option, ')') - strlen($option));
        $split = explode(',', $option);
        $options = array();
        $option = '';
        for ($i = 0; $i < sizeof($split); ++$i) {
            $option .= $split[$i];
            if (substr_count($option, "'") % 2 == 0) {
                $option = trim(trim($option), "'");
                $options[$option] = $option;
                $option = '';
            }
        }
        return $options;
    }

    /**
     * DB_DataObject_FormBuilder::_generateForm()
     *
     * Builds a simple HTML form for the current DataObject. Internal function, called by
     * the public getForm() method. You can override this in child classes if needed, but
     * it's also possible to leave this as it is and just override the getForm() method
     * to simply fine-tune the auto-generated form object (i.e. add/remove elements, alter
     * options, add/remove rules etc.).
     * If a key with the same name as the current field is found in the fb_preDefElements
     * property, the QuickForm element object contained in that array will be used instead
     * of auto-generating a new one. This allows for complete step-by-step customizing of
     * your forms.
     *
     * Note for date fields: HTML_QuickForm allows passing of an options array to the
     * HTML_QuickForm_date element. You can define your own options array for date elements
     * in your DataObject-derived classes by defining a method "dateOptions($fieldName)".
     * FormBuilder will call that method whenever it encounters a date field and expects to
     * get back a valid options array.
     *
     * @param string $action   The form action. Optional. If set to false (default), PHP_SELF is used.
     * @param string $target   The window target of the form. Optional. Defaults to '_self'.
     * @param string $formName The name of the form, will be used in "id" and "name" attributes. If set to false (default), the class name is used
     * @param string $method   The submit method. Defaults to 'post'.
     * @return object
     * @access protected
     * @author Markus Wolff <mw21st@php.net>
     * @author Fabien Franzen <atelierfabien@home.nl>
     */    
    function &_generateForm($action = false, $target = '_self', $formName = false, $method = 'post')
    {
        if ($formName === false) {
            $formName = strtolower(get_class($this->_do));
        }
        if ($action === false) {
            $action = $_SERVER['PHP_SELF'];   
        }

        // Retrieve the form object to use (may depend on the current renderer)
        $form =& $this->_createFormObject($formName, $method, $action, $target);

        // Initialize array with default values
        //$formValues = $this->_do->toArray();

        // Add a header to the form - set addFormHeader property to false to prevent this
        $this->_addFormHeader($form);

        // Go through all table fields and create appropriate form elements
        $keys = $this->_do->keys();

        // Reorder elements if requested, will return _getFieldsToRender if no reordering is needed
        $elements = $this->_reorderElements();

        //get elements to freeze
        $user_editable_fields = $this->_getUserEditableFields();
        if (is_array($user_editable_fields)) {
            $elements_to_freeze = array_diff(array_keys($elements), $user_editable_fields);
        } else {
            $elements_to_freeze = array();
        }

        if (!is_array($links = $this->_do->links())) {
            $links = array();
        }
        $pk = $this->_getPrimaryKey($this->_do);
        $rules = array();
        foreach ($elements as $key => $type) {
            // Check if current field is primary key. And primary key hiding is on. If so, make hidden field
            if (in_array($key, $keys) && $this->hidePrimaryKey == true) {
                $formValues[$key] = $this->_do->$key;
                $element =& $this->_createHiddenField($key);
            } else {
                unset($element);
                // Try to determine field types depending on object properties
                $notNull = $type & DB_DATAOBJECT_NOTNULL;
                if (in_array($key, $this->dateFields)) {
                    $type = DB_DATAOBJECT_DATE;
                } elseif (in_array($key, $this->timeFields)) {
                    $type = DB_DATAOBJECT_TIME;
                } elseif (in_array($key, $this->textFields)) {
                    $type = DB_DATAOBJECT_TXT;
                } elseif (in_array($key, $this->enumFields)) {
                    $type = DB_DATAOBJECT_FORMBUILDER_ENUM;
                } elseif (in_array($key, $this->booleanFields)) {
                    $type = DB_DATAOBJECT_BOOL;
                }
                if (isset($this->preDefElements[$key]) 
                    && (is_object($this->preDefElements[$key]) || is_array($this->preDefElements[$key]))) {
                    // Use predefined form field, IMPORTANT: This may depend on the used renderer!!
                    $element =& $this->preDefElements[$key];
                } elseif (isset($links[$key])) {
                    // If this field links to another table, display selectbox or radiobuttons
                    $opt = $this->getSelectOptions($key, false, !$notNull);
                    if (isset($this->linkElementTypes[$key]) && $this->linkElementTypes[$key] == 'radio') {
                        $element =& $this->_createRadioButtons($key, $opt);
                    } else {
                        $element =& $this->_createSelectBox($key, $opt);
                    }
                    unset($opt);
                }

                // No predefined object available, auto-generate new one
                $elValidator = false;
                $elValidRule = false;

                // Auto-detect field types depending on field's database type
                switch (true) {
                case ($type & DB_DATAOBJECT_BOOL):
                    $formValues[$key] = $this->_do->$key;
                    if (!isset($element)) {
                        $element =& $this->_createCheckbox($key, null, null, $this->getFieldLabel($key));
                    }
                    break;
                case ($type & DB_DATAOBJECT_INT):
                    $formValues[$key] = $this->_do->$key;
                    if (!isset($element)) {
                        $element =& $this->_createIntegerField($key);
                        $elValidator = 'numeric';
                    }
                    break;
                case (($type & DB_DATAOBJECT_DATE) && ($type & DB_DATAOBJECT_TIME)):
                    $this->debug('DATE & TIME CONVERSION using callback for element '.$key.' ('.$this->_do->$key.')!', 'FormBuilder');
                    $formValues[$key] = call_user_func($this->dateFromDatabaseCallback, $this->_do->$key);
                    if (!isset($element)) {
                        $element =& $this->_createDateTimeElement($key);  
                    }
                    break;  
                case ($type & DB_DATAOBJECT_DATE):
                    $this->debug('DATE CONVERSION using callback for element '.$key.' ('.$this->_do->$key.')!', 'FormBuilder');
                    $formValues[$key] = call_user_func($this->dateFromDatabaseCallback, $this->_do->$key);
                    if (!isset($element)) {
                        $element =& $this->_createDateElement($key);
                    }
                    break;
                case ($type & DB_DATAOBJECT_TIME):
                    $this->debug('TIME CONVERSION using callback for element '.$key.' ('.$this->_do->$key.')!', 'FormBuilder');
                    $formValues[$key] = call_user_func($this->dateFromDatabaseCallback, $this->_do->$key);
                    if (!isset($element)) {
                        $element =& $this->_createTimeElement($key);
                    }
                    break;
                case ($type & DB_DATAOBJECT_TXT):
                    $formValues[$key] = $this->_do->$key;
                    if (!isset($element)) {
                        $element =& $this->_createTextArea($key);
                    }
                    break;
                case ($type & DB_DATAOBJECT_STR):
                    $formValues[$key] = $this->_do->$key;
                    if (!isset($element)) {
                        // If field content contains linebreaks, make textarea - otherwise, standard textbox
                        if (isset($this->_do->$key) && strlen($this->_do->$key) && strstr($this->_do->$key, "\n")) {
                            $element =& $this->_createTextArea($key);
                        } else {
                            $element =& $this->_createTextField($key);
                        }
                    }
                    break;
                case ($type & DB_DATAOBJECT_FORMBUILDER_CROSSLINK):
                    unset($element);
                    // generate crossLink stuff
                    /*if ($pk === false) {
                        return PEAR::raiseError('A primary key must exist in the base table when using crossLinks.');
                    }*/
                    $crossLink = $this->crossLinks[$key];
                    $groupName  = '__crossLink_' . $crossLink['table'];
                    unset($crossLinksDo);
                    $crossLinksDo =& DB_DataObject::factory($crossLink['table']);
                    if (PEAR::isError($crossLinksDo)) {
                        die($crossLinksDo->getMessage());
                    }
                    
                    if (!is_array($crossLinksLinks = $crossLinksDo->links())) {
                        $crossLinksLinks = array();
                    }

                    list($linkedtable, $linkedfield) = explode(':', $crossLinksLinks[$crossLink['toField']]);
                    list($fromtable, $fromfield) = explode(':', $crossLinksLinks[$crossLink['fromField']]);
                    //if ($fromtable !== $this->_do->tableName()) error?
                    $all_options      = $this->_getSelectOptions($linkedtable, false, false, false, $linkedfield);
                    $selected_options = array();
                    if (isset($this->_do->$fromfield)) {
                        $crossLinksDo->{$crossLink['fromField']} = $this->_do->$fromfield;
                        if (method_exists($this->_do, 'preparelinkeddataobject')) {
                            if ($this->useCallTimePassByReference) {
                                eval('$this->_do->prepareLinkedDataObject(&$crossLinksDo, $key);');
                            } else {
                                $this->_do->prepareLinkedDataObject($crossLinksDo, $key);
                            }
                        }
                        if ($crossLinksDo->find() > 0) {
                            while ($crossLinksDo->fetch()) {
                                $selected_options[$crossLinksDo->{$crossLink['toField']}] = clone($crossLinksDo);
                            }
                        }
                    }
                    if (isset($crossLink['type']) && $crossLink['type'] == 'select') {
                        unset($element);
                        $element =& $this->_createSelectBox($groupName, $all_options, true);
                        $formValues[$groupName] = array_keys($selected_options); // set defaults later
                        
                    // ***X*** generate checkboxes
                    } else {
                        $element = array();
                        $rowNames = array();
                        $colNames = array('');
                        foreach ($all_options as $optionKey => $value) {
                            $crossLinksElement = $this->_createCheckbox($groupName.'['.$optionKey.']', $value, $optionKey);
                            $elementNamePrefix = $this->elementNamePrefix.$groupName.'__'.$optionKey.'_';
                            $elementNamePostfix = '_'.$this->elementNamePostfix;//']';
                            if (isset($selected_options[$optionKey])) {
                                if (!isset($formValues[$groupName])) {
                                    $formValues[$groupName] = array();
                                }
                                $formValues[$groupName][$optionKey] = $optionKey;
                            }
                            if (isset($crossLinksDo->fb_crossLinkExtraFields)) {
                                $row = array(&$crossLinksElement);
                                if (isset($selected_options[$optionKey])) {
                                    $extraFieldDo = $selected_options[$optionKey];
                                } else {
                                    unset($extraFieldDo);
                                    $extraFieldDo =& DB_DataObject::factory($crossLink['table']);
                                }
                                unset($tempFb);
                                $tempFb =& DB_DataObject_FormBuilder::create($extraFieldDo);
                                $extraFieldDo->fb_fieldsToRender = $crossLinksDo->fb_crossLinkExtraFields;
                                $extraFieldDo->fb_elementNamePrefix = $elementNamePrefix;
                                $extraFieldDo->fb_elementNamePostfix = $elementNamePostfix;
                                $extraFieldDo->fb_linkNewValue = false;
                                $this->_extraFieldsFb[$elementNamePrefix.$elementNamePostfix] =& $tempFb;
                                $tempForm = $tempFb->getForm();
                                foreach ($crossLinksDo->fb_crossLinkExtraFields as $extraField) {
                                    if ($tempForm->elementExists($elementNamePrefix.$extraField.$elementNamePostfix)) {
                                        $tempEl =& $tempForm->getElement($elementNamePrefix.$extraField.$elementNamePostfix);
                                        $colNames[$extraField] = $tempEl->getLabel();
                                    } else {
                                        $tempEl =& $this->_createStaticField($elementNamePrefix.$extraField.$elementNamePostfix,
                                                                             'Error - element not found for extra field '.$extraField);
                                    }
                                    $row[] =& $tempEl;
                                    if (!isset($formValues[$groupName.'__extraFields'])) {
                                        $formValues[$groupName.'__extraFields'] = array();
                                    }
                                    if (!isset($formValues[$groupName.'__extraFields'][$optionKey])) {
                                        $formValues[$groupName.'__extraFields'][$optionKey] = array();
                                    }
                                    $formValues[$groupName.'__extraFields'][$optionKey][$extraField] = $tempEl->getValue();
                                    unset($tempEl);
                                }
                                $element[] = $row;
                                unset($tempFb, $tempForm, $extraFieldDo, $row);
                                $rowNames[] = '<label for="'.$crossLinksElement->getAttribute('id').'">'.$value.'</label>';
                                $crossLinksElement->setText('');
                            } else {
                                $element[] = $crossLinksElement;
                            }
                            unset($crossLinksElement);
                        }
                        if (isset($crossLinksDo->fb_crossLinkExtraFields)) {
                            $this->_addElementTableToForm($form, $groupName, array_values($colNames), $rowNames, $element);
                        } else {
                            $this->_addElementGroupToForm($form, $element, $groupName, $this->crossLinkSeparator);
                        }
                        unset($element);
                        unset($rowNames);
                        unset($colNames);
                    }
                    break;
                case ($type & DB_DATAOBJECT_FORMBUILDER_TRIPLELINK):
                    unset($element);
                    /*if ($pk === false) {
                        return PEAR::raiseError('A primary key must exist in the base table when using tripleLinks.');
                    }*/
                    $tripleLink = $this->tripleLinks[$key];
                    $elName  = '__tripleLink_' . $tripleLink['table'];
                    $freeze = array_search($elName, $elements_to_freeze);
                    unset($tripleLinkDo);
                    $tripleLinkDo =& DB_DataObject::factory($tripleLink['table']);
                    if (PEAR::isError($tripleLinkDo)) {
                        die($tripleLinkDo->getMessage());
                    }
                    
                    if (!is_array($tripleLinksLinks = $tripleLinkDo->links())) {
                        $tripleLinksLinks = array();
                    }
                    
                    list($linkedtable1, $linkedfield1) = explode(':', $tripleLinksLinks[$tripleLink['toField1']]);
                    list($linkedtable2, $linkedfield2) = explode(':', $tripleLinksLinks[$tripleLink['toField2']]);
                    list($fromtable, $fromfield) = explode(':', $tripleLinksLinks[$tripleLink['fromField']]);
                    //if ($fromtable !== $this->_do->tableName()) error?
                    $all_options1 = $this->_getSelectOptions($linkedtable1, false, false, false, $linkedfield1);
                    $all_options2 = $this->_getSelectOptions($linkedtable2, false, false, false, $linkedfield2);
                    $selected_options = array();
                    if (isset($this->_do->$fromfield)) {
                        $tripleLinkDo->{$tripleLink['fromField']} = $this->_do->$fromfield;
                        if (method_exists($this->_do, 'preparelinkeddataobject')) {
                            if ($this->useCallTimePassByReference) {
                                eval('$this->_do->prepareLinkedDataObject(&$tripleLinkDo, $key);');
                            } else {
                                $this->_do->prepareLinkedDataObject($tripleLinkDo, $key);
                            }
                        }
                        if ($tripleLinkDo->find() > 0) {
                            while ($tripleLinkDo->fetch()) {
                                $selected_options[$tripleLinkDo->{$tripleLink['toField1']}][] = $tripleLinkDo->{$tripleLink['toField2']};
                            }
                        }
                    }
                    $columnNames = array();
                    foreach ($all_options2 as $key2 => $value2) {
                        $columnNames[] = $value2;
                    }
                    $rows = array();
                    $rowNames = array();
                    $formValues[$key] = array();
                    foreach ($all_options1 as $key1 => $value1) {
                        $rowNames[] = $value1;
                        $row = array();
                        foreach ($all_options2 as $key2 => $value2) {
                            unset($tripleLinksElement);
                            $tripleLinksElement = $this->_createCheckbox($elName.'['.$key1.']['.$key2.']',
                                                                         '',
                                                                         $key2
                                                                         //false,
                                                                         //$freeze
                                                                         );
                            if (isset($selected_options[$key1])) {
                                if (in_array($key2, $selected_options[$key1])) {
                                    if (!isset($formValues['__tripleLink_'.$tripleLink['table']][$key1])) {
                                        $formValues['__tripleLink_'.$tripleLink['table']][$key1] = array();
                                    }
                                    $formValues['__tripleLink_'.$tripleLink['table']][$key1][$key2] = $key2;
                                }
                            }
                            $row[] =& $tripleLinksElement;
                        }
                        $rows[] =& $row;
                        unset($row);
                    }
                    $this->_addElementTableToForm($form, $elName, $columnNames, $rowNames, $rows);
                    unset($columnNames, $rowNames, $rows);
                    break;
                case ($type & DB_DATAOBJECT_FORMBUILDER_ENUM):
                    $formValues[$key] = $this->_do->$key;
                    if (!isset($element)) {
                        if (isset($this->enumOptions[$key])) {
                            $options = array();
                            foreach ($this->enumOptions[$key] as $value) {
                                $options[$value] = $value;
                            }
                        } else {
                            $options = call_user_func($this->enumOptionsCallback, $this->_do->__table, $key);
                            if (PEAR::isError($options)) {
                                return $options;
                            }
                        }
                        if (in_array($key, $this->selectAddEmpty) || !$notNull) {
                            $options = array_merge(array('' => $this->selectAddEmptyLabel), $options);
                        }
                        if (!$options) {
                            return PEAR::raiseError('There are no options defined for the enum field "'.$key.'". You may need to set the options in the enumOptions option or use your own enumOptionsCallback.');
                        }
                        $element = array();
                        if (isset($this->linkElementTypes[$key]) && $this->linkElementTypes[$key] == 'radio') {
                            foreach ($options as $option) {
                                $element =& $this->_createRadioButtons($key, $options);
                            }
                        } else {
                            $element =& $this->_createSelectBox($key, $options);
                        }
                        unset($options);
                    }
                    break;
                case ($type & DB_DATAOBJECT_FORMBUILDER_REVERSELINK):
                    unset($element);
                    $element = array();
                    $elName = '__reverseLink_'.$this->reverseLinks[$key]['table'].'_'.$this->reverseLinks[$key]['field'];
                    unset($do);
                    $do =& DB_DataObject::factory($this->reverseLinks[$key]['table']);
                    if (method_exists($this->_do, 'preparelinkeddataobject')) {
                        if ($this->useCallTimePassByReference) {
                            eval('$this->_do->prepareLinkedDataObject(&$do, $key);');
                        } else {
                            $this->_do->prepareLinkedDataObject($do, $key);
                        }
                    }
                    if (!is_array($rLinks = $do->links())) {
                        $rLinks = array();
                    }
                    $rPk = $this->_getPrimaryKey($do);
                    //$rFields = $do->table();
                    list($lTable, $lField) = explode(':', $rLinks[$this->reverseLinks[$key]['field']]);
                    $formValues[$elName] = array();
                    if ($do->find()) {
                        while ($do->fetch()) {
                            $label = $this->getDataObjectString($do);
                            if ($do->{$this->reverseLinks[$key]['field']} == $this->_do->$lField) {
                                $formValues[$elName][$do->$rPk] = $do->$rPk;
                            } elseif ($rLinked =& $do->getLink($this->reverseLinks[$key]['field'])) {
                                $label .= '<b>'.$this->reverseLinks[$key]['linkText'].$this->getDataObjectString($rLinked).'</b>';
                            }
                            $element[] =& $this->_createCheckbox($elName.'['.$do->$rPk.']', $label, $do->$rPk);
                        }
                    }
                    $this->_addElementGroupToForm($form, $element, $elName, $this->crossLinkSeparator);
                    unset($element);
                    break;
                case ($type & DB_DATAOBJECT_FORMBUILDER_GROUP):
                    unset($element);
                    $element =& $this->_createHiddenField($key.'__placeholder');
                    break;
                default:
                    $formValues[$key] = $this->_do->$key;
                    if (!isset($element)) {
                        $element =& $this->_createTextField($key);
                    }
                } // End switch
                //} // End else                
                if ($elValidator !== false) {
                    if (!isset($rules[$key])) {
                        $rules[$key] = array();
                    }
                    $rules[$key][] = array('validator' => $elValidator,
                                           'rule' => $elValidRule,
                                           'message' => $this->ruleViolationMessage);
                } // End if
                                        
            } // End else
                    
            //GROUP OR ELEMENT ADDITION
            if (isset($this->preDefGroups[$key]) && !($type & DB_DATAOBJECT_FORMBUILDER_GROUP)) {
                $group = $this->preDefGroups[$key];
                $groups[$group][] = $element;
            } elseif (isset($element)) {
                if (is_array($element)) {
                    $this->_addElementGroupToForm($form, $element, $key);
                } else {
                    $this->_addElementToForm($form, $element);
                }
            } // End if
            
           //SET AUTO-RULES IF NOT DEACTIVATED FOR THIS OR ALL ELEMENTS
           if ($this->excludeFromAutoRules != '__ALL__' && !in_array($key, $this->excludeFromAutoRules)) {
                //ADD REQURED RULE FOR NOT_NULL FIELDS
                if ((!in_array($key, $keys) || $this->hidePrimaryKey == false)
                    && ($notNull)
                    && !in_array($key, $elements_to_freeze)
                    && !($type & DB_DATAOBJECT_BOOL)) {
                    
                    $this->_setFormElementRequired($form, $key);
                    $this->debug('Adding required rule for '.$key);
                }
    
                // VALIDATION RULES
                if (isset($rules[$key])) {
                    $this->_addFieldRulesToForm($form, $rules[$key], $key);
                    $this->debug("Adding rule '$rules[$key]' to $key");
                }
            } else {
                $this->debug($key.' excluded from auto-rules');
            }
        } // End foreach

        if ($this->linkNewValue) {
            $this->_addRuleForLinkNewValues($form);
        }

        // Freeze fields that are not to be edited by the user
        $this->_freezeFormElements($form, $elements_to_freeze);
        
        //GROUP SUBMIT
        $flag = true;
        if (isset($this->preDefGroups['__submit__'])) {
            $group = $this->preDefGroups['__submit__'];
            if (count($groups[$group]) > 1) {
                $groups[$group][] =& $this->_createSubmitButton('__submit__', $this->submitText);
                $flag = false;
            } else {
                $flag = true;
            }   
        }
        
        //GROUPING  
        if (isset($groups) && is_array($groups)) { //apply grouping
            reset($groups);
            while (list($grp, $elements) = each($groups)) {
                if (count($elements) == 1) {  
                    $this->_addElementToForm($form, $elements[0]);
                    $this->_moveElementBefore($form, $this->_getElementName($elements[0]), $grp.'__placeholder');
                } elseif (count($elements) > 1) {
                    $this->_addElementGroupToForm($form, $elements, $grp, '&nbsp;');
                    $this->_moveElementBefore($form, $grp, $grp.'__placeholder');
                }
            }       
        }

        //ELEMENT SUBMIT
        if ($flag == true && $this->createSubmit == true) {
            $this->_addSubmitButtonToForm($form, '__submit__', $this->submitText);
        }
        
        //APPEND EXISTING FORM ELEMENTS
        if (is_a($this->_form, 'html_quickform') && $this->_appendForm == true) {
            // There somehow needs to be a new method in QuickForm that allows to fetch
            // a list of all element names currently registered in a form. Otherwise, there
            // will be need for some really nasty workarounds once QuickForm adopts PHP5's
            // new encapsulation features.
            reset($this->_form->_elements);
            while (list($elNum, $element) = each($this->_form->_elements)) {
                $this->_addElementToForm($form, $element);
            }
        }

        // Assign default values to the form
        $fixedFormValues = array();
        foreach ($formValues as $key => $value) {
            $fixedFormValues[$this->getFieldName($key)] = $value;
        }
        $this->_setFormDefaults($form, $fixedFormValues);
        return $form;
    }


    /**
     * Gets the name of the field to use in the form.
     *
     * @param  string field's name
     * @return string field name to use with form
     */
    function getFieldName($fieldName) {
        if (($pos = strpos($fieldName, '[')) !== false) {
            $fieldName = substr($fieldName, 0, $pos).$this->elementNamePostfix.substr($fieldName, $pos);
        } else {
            $fieldName .= $this->elementNamePostfix;
        }
        return $this->elementNamePrefix.$fieldName;
    }

    
    /**
     * DB_DataObject_FormBuilder::_explodeArrString()
     *
     * Internal method, will convert string representations of arrays as used in .ini files
     * to real arrays. String format example:
     * key1:value1,key2:value2,key3:value3,...
     *
     * @param string $str The string to convert to an array
     * @access protected
     * @return array
     */
    function _explodeArrString($str) {
        $ret = array();
        $arr = explode(',', $str);
        foreach ($arr as $mapping) {
            if (strstr($mapping, ':')) {
                $map = explode(':', $mapping);
                $ret[$map[0]] = $map[1];   
            } else {
                $ret[] = $mapping;
            }
        }
        return $ret;
    }


    /**
     * DB_DataObject_FormBuilder::_reorderElements()
     *
     * Changes the order in which elements are being processed, so that
     * you can use QuickForm's default renderer or dynamic templates without
     * being dependent on the field order in the database.
     *
     * Make a class property named "fb_preDefOrder" in your DataObject-derived classes
     * which contains an array with the correct element order to use this feature.
     *
     * @return array  Array in correct order or same as _getFieldsToRender if preDefOrder is not set
     * @access protected
     * @author Fabien Franzen <atelierfabien@home.nl>
     */
    function _reorderElements() {
        $elements = $this->_getFieldsToRender();
        if ($this->preDefOrder) {
            $this->debug('<br/>...reordering elements...<br/>');
            $table = $this->_do->table();

            foreach ($this->preDefOrder as $elem) {
                if (isset($elements[$elem])) {
                    $ordered[$elem] = $elements[$elem]; //key=>type
                    if (isset($this->preDefGroups[$elem])
                        && !in_array($this->preDefGroups[$elem], $ordered)
                        && !in_array($this->preDefGroups[$elem], $this->preDefOrder)) {
                        $ordered[$this->preDefGroups[$elem]] = DB_DATAOBJECT_FORMBUILDER_GROUP;
                    }
                } elseif (!isset($table[$elem])) {
                    $this->debug('<br/>...reorder not supported for invalid element(key) "'.$elem.'"...<br/>');
                    //return false;
                }
            }

            $ordered = array_merge($ordered, array_diff_assoc($elements, $ordered));

            return $ordered;
        } else {
            $this->debug('<br/>...reorder not supported, fb_preDefOrder is not set or is not an array, returning _getFieldsToRender...<br/>');
            return $elements;
        }
    }

    /**
     * Returns an array of crosslink and triplelink elements for use the same as
     * DB_DataObject::table().
     *
     * @return array the key is the name of the cross/triplelink element, the value
     *  is the type
     */
    function _getSpecialElementNames() {
        $ret = array();
        foreach ($this->tripleLinks as $tripleLink) {
            $ret['__tripleLink_'.$tripleLink['table']] = DB_DATAOBJECT_FORMBUILDER_TRIPLELINK;
        }
        foreach ($this->crossLinks as $crossLink) {
            $ret['__crossLink_'.$crossLink['table']] = DB_DATAOBJECT_FORMBUILDER_CROSSLINK;
        }
        foreach ($this->reverseLinks as $reverseLink) {
            $ret['__reverseLink_'.$reverseLink['table'].'_'.$reverseLink['field']] = DB_DATAOBJECT_FORMBUILDER_REVERSELINK;
        }
        foreach ($this->preDefGroups as $group) {
            $ret[$group] = DB_DATAOBJECT_FORMBUILDER_GROUP;
        }
        return $ret;
    }
    
    
    /**
     * DB_DataObject_FormBuilder::useForm()
     *
     * Sometimes, it might come in handy not just to create a new QuickForm object,
     * but to work with an existing one. Using FormBuilder together with
     * HTML_QuickForm_Controller or HTML_QuickForm_Page is such an example ;-)
     * If you do not call this method before the form is generated, a new QuickForm
     * object will be created (default behaviour).
     *
     * @param $form     object  A HTML_QuickForm object (or extended from that)
     * @param $append   boolean If TRUE, the form will be appended to the one generated by FormBuilder. If false, FormBuilder will just add its own elements to this form. 
     * @return boolean  Returns false if the passed object was not a HTML_QuickForm object or a QuickForm object was already created
     * @access public
     */
    function useForm(&$form, $append = false)
    {
        if (is_a($form, 'html_quickform') && !is_object($this->_form)) {
            $this->_form =& $form;
            $this->_appendForm = $append;
            return true;
        }
        return false;
    }
    



    /**
     * DB_DataObject_FormBuilder::getFieldLabel()
     *
     * Returns the label for the given field name. If no label is specified,
     * the fieldname will be returned with ucfirst() applied.
     *
     * @param $fieldName  string  The field name
     * @return string
     * @access public
     */
    function getFieldLabel($fieldName)
    {
        if (isset($this->fieldLabels[$fieldName])) {
            return $this->fieldLabels[$fieldName];
        }
        return ucfirst($fieldName);
    }

    /**
     * DB_DataObject_FormBuilder::getDataObjectString()
     *
     * Returns a string which identitfies this dataobject.
     * If multiple display fields are given, will display them all seperated by ", ".
     * If a display field is a foreign key (link) the display value for the record it
     * points to will be used as long as the linkDisplayLevel has not been reached.
     * Its display value will be surrounded by parenthesis as it may have multiple
     * display fields of its own.
     *
     * May be called statically.
     *
     * Will use display field configurations from these locations, in this order:
     * 1) $displayFields parameter
     * 2) the fb_linkDisplayFields member variable of the dataobject
     * 3) the linkDisplayFields member variable of this class (if not called statically)
     * 4) all fields returned by the DO's table() function
     *
     * @param DB_DataObject the dataobject to get the display value for, must be populated
     * @param mixed   field to use to display, may be an array with field names or a single field.
     *    Will only be used for this DO, not linked DOs. If you wish to set the display fields
     *    all DOs the same, set the option in the FormBuilder class instance.
     * @param int     the maximum link display level. If null, $this->linkDisplayLebel will be used
     *   if it exists, otherwise 3 will be used. {@see DB_DataObject_FormBuilder::linkDisplayLevel}
     * @param int     the current recursion level. For internal use only.
     * @return string select display value for this field
     * @access public
     */
    function getDataObjectString(&$do, $displayFields = false, $linkDisplayLevel = null, $level = 1) {
        if ($linkDisplayLevel === null) {
            $linkDisplayLevel = (isset($this) && isset($this->linkDisplayLevel)) ? $this->linkDisplayLevel : 3;
        }
        if (!is_array($links = $do->links())) {
            $links = array();
        }
        if ($displayFields === false) {
            if (isset($do->fb_linkDisplayFields)) {
                $displayFields = $do->fb_linkDisplayFields;
            } elseif (isset($this) && isset($this->linkDisplayFields) && $this->linkDisplayFields) {
                $displayFields = $this->linkDisplayFields;
            }
            if (!$displayFields) {
                $displayFields = array_keys($do->table());
            }
        }
        $ret = '';
        $first = true;
        foreach ($displayFields as $field) {
            if ($first) {
                $first = false;
            } else {
                $ret .= ', ';
            }
            if (isset($do->$field)) {
                if ($linkDisplayLevel > $level && isset($links[$field])
                   && ($subDo = $do->getLink($field))) {
                    if (isset($this) && is_a($this, 'DB_DataObject_FormBuilder')) {
                        $ret .= '('.$this->getDataObjectString($subDo, false, $linkDisplayLevel, $level + 1).')';
                    } else {
                        $ret .= '('.DB_DataObject_FormBuilder::getDataObjectString($subDo, false, $linkDisplayLevel, $level + 1).')';
                    }
                } else {
                    $ret .= $do->$field;
                }
            }
        }
        return $ret;
    }

    /**
     * DB_DataObject_FormBuilder::getSelectOptions()
     *
     * Returns an array of options for use with the HTML_QuickForm "select" element.
     * It will try to fetch all related objects (if any) for the given field name and
     * build the array.
     * For the display name of the option, it will try to use
     * the settings in the database.formBuilder.ini file. If those are not found,
     * the linked object's property "fb_linkDisplayFields". If that one is not present,
     * it will try to use the global configuration setting "linkDisplayFields".
     * Can also be called with a second parameter containing the name of the display
     * field - this will override all other settings.
     * Same goes for "linkOrderFields", which determines the field name used for
     * sorting the option elements. If neither a config setting nor a class property
     * of that name is set, the display field name will be used.
     *
     * @param string $field         The field to fetch the links from. You should make sure the field actually *has* links before calling this function (see: DB_DataObject::links())
     * @param string $displayFields  (Optional) The name of the field used for the display text of the options
     * @param bool   $selectAddEmpty (Optional) If true, an empty option will be added to the list of options
     *                                          If false, the selectAddEmpty member var will be checked
     * @return array strings representing all of the records in the table $field links to.
     * @access public
     */
    function getSelectOptions($field, $displayFields = false, $selectAddEmpty = false)
    {
        if (empty($this->_do->_database)) {
            // TEMPORARY WORKAROUND !!! Guarantees that DataObject config has
            // been loaded and all link information is available.
            $this->_do->keys();   
        }
        if (!is_array($links = $this->_do->links())) {
            $links = array();
        }
        $link = explode(':', $links[$field]);

        $res = $this->_getSelectOptions($link[0],
                                        $displayFields,
                                        $selectAddEmpty || in_array($field, $this->selectAddEmpty),
                                        $field,
                                        $link[1]);

        if ($res !== false) {
            return $res;
        }

        $this->debug('Error: '.get_class($opts).' does not inherit from DB_DataObject');
        return array();
    }

    /**
     * Internal function to get the select potions for a table.
     *
     * @param string The table to get the select display strings for.
     * @param array  array of diaply fields to use. Will default to the FB or DO options.
     * @param bool   If set to true, there will be an empty option in the returned array.
     * @param string the field in the current table which we're getting options for
     * @param string the field to use for the value of the options. Defaults to the PK of the $table
     *
     * @return array strings representing all of the records in $table.
     * @access protected
     */
    function _getSelectOptions($table, $displayFields = false, $selectAddEmpty = false, $field = false, $valueField = false) {
        $opts =& DB_DataObject::factory($table);
        if (is_a($opts, 'db_dataobject')) {
            if ($valueField === false) {
                $valueField = $this->_getPrimaryKey($opts);
            }
            if (strlen($valueField)) {
                if ($displayFields === false) {
                    if (isset($opts->fb_linkDisplayFields)) {
                        $displayFields = $opts->fb_linkDisplayFields;
                    } elseif ($this->linkDisplayFields){
                        $displayFields = $this->linkDisplayFields;
                    } else {
                        $displayFields = array($valueField);
                    }
                }

                if (isset($opts->fb_linkOrderFields)) {
                    $orderFields = $opts->fb_linkOrderFields;
                } elseif ($this->linkOrderFields){
                    $orderFields = $this->linkOrderFields;
                } else {
                    $orderFields = $displayFields;
                }
                $orderStr = '';
                $first = true;
                foreach ($orderFields as $col) {
                    if ($first) {
                        $first = false;
                    } else {
                        $orderStr .= ', ';
                    }
                    $orderStr .= $col;
                }
                if ($orderStr) {
                    $opts->orderBy($orderStr);
                }
                $list = array();
                
                // FIXME!
                if ($selectAddEmpty) {
                    $list[''] = $this->selectAddEmptyLabel;
                }
                if (method_exists($this->_do, 'preparelinkeddataobject')) {
                    if ($this->useCallTimePassByReference) {
                        eval('$this->_do->prepareLinkedDataObject(&$opts, $field);');
                    } else {
                        $this->_do->prepareLinkedDataObject($opts, $field);
                    }
                }
                // FINALLY, let's see if there are any results
                if ($opts->find() > 0) {
                    while ($opts->fetch()) {
                        $list[$opts->$valueField] = $this->getDataObjectString($opts, $displayFields);
                    }
                }

                return $list;
            } else {
                return array();
            }
        }
        $this->debug('Error: '.get_class($opts).' does not inherit from DB_DataObject');
        return array();
    }

    /**
     * DB_DataObject_FormBuilder::populateOptions()
     *
     * Populates public member vars with fb_ equivalents in the DataObject.
     */
    function populateOptions() {
        $badVars = array('linkDisplayFields', 'linkOrderFields');
        foreach (get_object_vars($this) as $var => $value) {
            if ($var[0] != '_' && !in_array($var, $badVars) && isset($this->_do->{'fb_'.$var})) {
                $this->$var = $this->_do->{'fb_'.$var};
            }
        }
        foreach ($this->crossLinks as $key => $crossLink) {
            $groupName  = '__crossLink_' . $crossLink['table'];
            unset($do);
            $do =& DB_DataObject::factory($crossLink['table']);
            if (PEAR::isError($do)) {
                return PEAR::raiseError('Cannot load dataobject for table '.$crossLink['table'].' - '.$do->getMessage());
            }
                
            if (!is_array($links = $do->links())) {
                $links = array();
            }
                
            if (isset($crossLink['fromField'])) {
                $fromField = $crossLink['fromField'];
            } else {
                unset($fromField);
            }
            if (isset($crossLink['toField'])) {
                $toField = $crossLink['toField'];
            } else {
                unset($toField);
            }
            if (!isset($toField) || !isset($fromField)) {
                foreach ($links as $field => $link) {
                    list($linkTable, $linkField) = explode(':', $link);
                    if (!isset($fromField) && $linkTable == $this->_do->__table) {
                        $fromField = $field;
                    } elseif (!isset($toField) && (!isset($fromField) || $linkField != $fromField)) {
                        $toField = $field;
                    }
                }
            }
            unset($this->crossLinks[$key]);
            $this->crossLinks[$groupName] = array_merge($crossLink,
                                                        array('fromField' => $fromField,
                                                              'toField' => $toField));
        }
        foreach ($this->tripleLinks as $key => $tripleLink) {
            $elName  = '__tripleLink_' . $tripleLink['table'];
            //$freeze = array_search($elName, $elements_to_freeze);
            unset($do);
            $do =& DB_DataObject::factory($tripleLink['table']);
            if (PEAR::isError($do)) {
                die($do->getMessage());
            }
                
            if (!is_array($links = $do->links())) {
                $links = array();
            }
                
            if (isset($tripleLink['fromField'])) {
                $fromField = $tripleLink['fromField'];
            } else {
                unset($fromField);
            }
            if (isset($tripleLink['toField1'])) {
                $toField1 = $tripleLink['toField1'];
            } else {
                unset($toField1);
            }
            if (isset($tripleLink['toField2'])) {
                $toField2 = $tripleLink['toField2'];
            } else {
                unset($toField2);
            }
            if (!isset($toField2) || !isset($toField1) || !isset($fromField)) {
                foreach ($links as $field => $link) {
                    list($linkTable, $linkField) = explode(':', $link);
                    if (!isset($fromField) && $linkTable == $this->_do->__table) {
                        $fromField = $field;
                    } elseif (!isset($toField1) && (!isset($fromField) || $linkField != $fromField)) {
                        $toField1 = $field;
                    } elseif (!isset($toField2) && (!isset($fromField) || $linkField != $fromField) && $linkField != $toField1) {
                        $toField2 = $field;
                    }
                }
            }
            unset($this->tripleLinks[$key]);
            $this->tripleLinks[$elName] = array_merge($tripleLink,
                                                      array('fromField' => $fromField,
                                                            'toField1' => $toField1,
                                                            'toField2' => $toField2));
        }
        foreach ($this->reverseLinks as $key => $reverseLink) {
            if (!isset($reverseLink['field'])) {
                unset($do);
                $do =& DB_DataObject::factory($reverseLink['table']);
                if (!is_array($links = $do->links())) {
                    $links = array();
                }
                foreach ($links as $field => $link) {
                    list($linkTable, $linkField) = explode(':', $link);
                    if ($linkTable == $this->_do->__table) {
                        $reverseLink['field'] = $field;
                        break;
                    }
                }
            }
            $elName  = '__reverseLink_'.$reverseLink['table'].'_'.$reverseLink['field'];
            if (!isset($reverseLink['linkText'])) {
                $reverseLink['linkText'] = ' - currently linked to - ';
            }
            unset($this->reverseLinks[$key]);
            $this->reverseLinks[$elName] = $reverseLink;
        }
        if (is_array($this->linkNewValue)) {
            $newArr = array();
            foreach ($this->linkNewValue as $field) {
                $newArr[$field] = $field;
            }
            $this->linkNewValue = $newArr;
        } else {
            if ($this->linkNewValue) {
                $this->linkNewValue = array();
                if (is_array($links = $this->_do->links())) {
                    foreach ($links as $link => $to) {
                        $this->linkNewValue[$link] = $link;
                    }
                }
            } else {
                $this->linkNewValue = array();
            }
        }
    }

    /**
     * DB_DataObject_FormBuilder::getForm()
     *
     * Returns a HTML form that was automagically created by _generateForm().
     * You need to use the get() method before calling this one in order to
     * prefill the form with the retrieved data.
     *
     * If you have a method named "preGenerateForm()" in your DataObject-derived class,
     * it will be called before _generateForm(). This way, you can create your own elements
     * there and add them to the "fb_preDefElements" property, so they will not be auto-generated.
     *
     * If you have your own "getForm()" method in your class, it will be called <b>instead</b> of
     * _generateForm(). This enables you to have some classes that make their own forms completely
     * from scratch, without any auto-generation. Use this for highly complex forms. Your getForm()
     * method needs to return the complete HTML_QuickForm object by reference.
     *
     * If you have a method named "postGenerateForm()" in your DataObject-derived class, it will
     * be called after _generateForm(). This allows you to remove some elements that have been
     * auto-generated from table fields but that you don't want in the form.
     *
     * Many ways lead to rome.
     *
     * @param string $action   The form action. Optional. If set to false (default), $_SERVER['PHP_SELF'] is used.
     * @param string $target   The window target of the form. Optional. Defaults to '_self'.
     * @param string $formName The name of the form, will be used in "id" and "name" attributes.
     *                         If set to false (default), the class name is used, prefixed with "frm"
     * @param string $method   The submit method. Defaults to 'post'.
     * @return object
     * @access public
     */
    function &getForm($action = false, $target = '_self', $formName = false, $method = 'post')
    {
        if (method_exists($this->_do, 'pregenerateform')) {
            if ($this->useCallTimePassByReference) {
                eval('$this->_do->preGenerateForm(&$this);');
            } else {
                $this->_do->preGenerateForm($this);
            }
        }
        $this->populateOptions();
        if (method_exists($this->_do, 'getform')) {
            if ($this->useCallTimePassByReference) {
                eval('$obj = $this->_do->getForm($action, $target, $formName, $method, &$this);');
            } else {
                $obj = $this->_do->getForm($action, $target, $formName, $method, $this);
            }
        } else {
            $obj = &$this->_generateForm($action, $target, $formName, $method);
        }
        if (method_exists($this->_do, 'postgenerateform')) {
            if ($this->useCallTimePassByReference) {
                eval('$this->_do->postGenerateForm(&$obj, &$this);');
            } else {
                $this->_do->postGenerateForm($obj, $this);
            }
        }
        return($obj);   
    }


    /**
     * DB_DataObject_FormBuilder::_date2array()
     *
     * Takes a string representing a date or a unix timestamp and turns it into an
     * array suitable for use with the QuickForm data element.
     * When using a string, make sure the format can be handled by the PEAR::Date constructor!
     *
     * Beware: For the date conversion to work, you must at least use the letters "d", "m" and "Y" in
     * your format string (see "dateElementFormat" option). If you want to enter a time as well,
     * you will have to use "H", "i" and "s" as well. Other letters will not work! Exception: You can
     * also use "M" instead of "m" if you want plain text month names.
     *
     * @param mixed $date   A unix timestamp or the string representation of a date, compatible to strtotime()
     * @return array
     * @access protected
     */
    function _date2array($date)
    {
        $da = array();
        if (is_string($date)) {
            if (preg_match('/^\d+:\d+(:\d+|)(\s+[ap]m|)$/i', $date)) {
                $date = date('Y-m-d ').$date;
                $getDate = false;
            } else {
                $getDate = true;
            }
            include_once('Date.php');
            $dObj = new Date($date);
            if ($getDate) {
                $da['d'] = $dObj->getDay();
                $da['l'] = $da['D'] = $dObj->getDayOfWeek();
                $da['m'] = $da['M'] = $da['F'] = $dObj->getMonth();
                $da['Y'] = $da['y'] = $dObj->getYear();
            }
            $da['H'] = $dObj->getHour();
            $da['h'] = $da['H'] % 12;
            if ($da['h'] == 0) {
                $da['h'] = 12;
            }
            $da['i'] = $dObj->getMinute();
            $da['s'] = $dObj->getSecond();
            if ($da['H'] >= 12) {
                $da['a'] = 'pm';
                $da['A'] = 'PM';
            } else {
                $da['a'] = 'am';
                $da['A'] = 'AM';
            }
            unset($dObj);
        } else {
            if (is_int($date)) {
                $time = $date;
            } else {
                $time = time();
            }
            $da['d'] = date('d', $time);
            $da['l'] = $da['D'] = date('w', $time);
            $da['m'] = $da['M'] = $da['F'] = date('m', $time);
            $da['Y'] = $da['y'] = date('Y', $time);
            $da['H'] = date('H', $time);
            $da['h'] = date('h', $time);
            $da['i'] = date('i', $time);
            $da['s'] = date('s', $time);
            $da['a'] = date('a', $time);
            $da['A'] = date('A', $time);
        }
        $this->debug('<i>_date2array():</i> from '.$date.' to '.serialize($da).' ...');
        return $da;
    }


    /**
     * DB_DataObject_FormBuilder::_array2date()
     *
     * Takes a date array as used by the QuickForm date element and turns it back into
     * a string representation suitable for use with a database date field (format 'YYYY-MM-DD').
     * If second parameter is true, it will return a unix timestamp instead. //FRANK: Not at this point it wont
     *
     * Beware: For the date conversion to work, you must at least use the letters "d", "m" and "Y" in
     * your format string (see "dateElementFormat" option). If you want to enter a time as well,
     * you will have to use "H", "i" and "s" as well. Other letters will not work! Exception: You can
     * also use "M" instead of "m" if you want plain text month names.
     *
     * @param array $date   An array representation of a date, as user in HTML_QuickForm's date element
     * @param boolean $timestamp  Optional. If true, return a timestamp instead of a string. Defaults to false.
     * @return mixed
     * @access protected
     */
    function _array2date($dateInput, $timestamp = false)
    {
        if (isset($dateInput['M'])) {
            $month = $dateInput['M'];
        } elseif (isset($dateInput['m'])) {
            $month = $dateInput['m'];   
        } elseif (isset($dateInput['F'])) {
            $month = $dateInput['F'];
        }
        if (isset($dateInput['Y'])) {
            $year = $dateInput['Y'];
        } elseif (isset($dateInput['y'])) {
            $year = $dateInput['y'];
        }
        if (isset($dateInput['H'])) {
            $hour = $dateInput['H'];
        } elseif (isset($dateInput['h'])) {
            $hour = $dateInput['h'];
        }
        if (isset($dateInput['a'])) {
            $ampm = $dateInput['a'];
        } elseif (isset($dateInput['A'])) {
            $ampm = $dateInput['A'];
        }
        $strDate = '';
        if (isset($year) || isset($month) || isset($dateInput['d'])) {
            if (isset($year) && ($len = strlen($year)) > 0) {
                if ($len < 2) {
                    $year = '0'.$year;
                }
                if ($len < 4) {
                    $year = substr(date('Y'), 0, 2).$year;
                }
            } else {
                $year = '0000';
            }
            if(isset($month) && ($len = strlen($month)) > 0) {
                if ($len < 2) {
                    $month = '0'.$month;
                }
            } else {
                $month = '00';
            }
            if (isset($dateInput['d']) && ($len = strlen($dateInput['d'])) > 0) {
                if ($len < 2) {
                    $dateInput['d'] = '0'.$dateInput['d'];
                }
            } else {
                $dateInput['d'] = '00';
            }
            $strDate .= $year.'-'.$month.'-'.$dateInput['d'];
        }
        if (isset($hour) || isset($dateInput['i']) || isset($dateInput['s'])) {
            if (isset($hour) && ($len = strlen($hour)) > 0) {
                if ($len < 2) {
                    $hour = '0'.$hour;
                }
            } else {
                $hour = '00';
            }
            if (isset($dateInput['i']) && ($len = strlen($dateInput['i'])) > 0) {
                if ($len < 2) {
                    $dateInput['i'] = '0'.$dateInput['i'];
                }
            } else {
                $dateInput['i'] = '00';
            }
            if (!empty($strDate)) {
                $strDate .= ' ';
            }
            $strDate .= $hour.':'.$dateInput['i'];
            if (isset($dateInput['s']) && ($len = strlen($dateInput['s'])) > 0) {
                $strDate .= ':'.($len < 2 ? '0' : '').$dateInput['s'];
            }
            if (isset($ampm) && strlen($ampm) > 0) {
                $strDate .= ' '.$ampm;
            }
        }
        $this->debug('<i>_array2date():</i>'.serialize($dateInput).' to '.$strDate.' ...');
        return $strDate;
    }

    /**
     * DB_DataObject_FormBuilder::validateData()
     *
     * Makes a call to the current DataObject's validate() method and returns the result.
     *
     * @return mixed
     * @access public
     * @see DB_DataObject::validate()
     */
    function validateData()
    {
        $this->_validationErrors = $this->_do->validate();
        return $this->_validationErrors;
    }

    /**
     * DB_DataObject_FormBuilder::getValidationErrors()
     *
     * Returns errors from data validation. If errors have occured, this will be
     * an array with the fields that have errors, otherwise a boolean.
     *
     * @return mixed
     * @access public
     * @see DB_DataObject::validate()
     */
    function getValidationErrors()
    {
        return $this->_validationErrors;
    }


    /**
     * DB_DataObject_FormBuilder::processForm()
     *
     * This will take the submitted form data and put it back into the object's properties.
     * If the primary key is not set or NULL, it will be assumed that you wish to insert a new
     * element into the database, so DataObject's insert() method is invoked.
     * Otherwise, an update() will be performed.
     * <i><b>Careful:</b> If you're using natural keys or cross-referencing tables where you don't have
     * one dedicated primary key, this will always assume that you want to do an update! As there
     * won't be a matching entry in the table, no action will be performed at all - the reason
     * for this behaviour can be very hard to detect. Thus, if you have such a situation in one
     * of your tables, simply override this method so that instead of the key check it will try
     * to do a SELECT on the table using the current settings. If a match is found, do an update.
     * If not, do an insert.</i>
     * This method is perfect for use with QuickForm's process method. Example:
     * <code>
     * if ($form->validate()) {
     *     $form->freeze();
     *     $form->process(array(&$formGenerator,'processForm'), false);
     * }
     * </code>
     *
     * If you wish to enforce a special type of query, use the forceQueryType() method.
     *
     * Always remember to pass your objects by reference - otherwise, if the operation was
     * an insert, the primary key won't get updated with the new database ID because processForm()
     * was using a local copy of the object!
     *
     * If a method named "preProcessForm()" exists in your derived class, it will be called before
     * processForm() starts doing its magic. The data that has been submitted by the form
     * will be passed to that method as a parameter.
     * Same goes for a method named "postProcessForm()", with the only difference - you might
     * have guessed this by now - that it's called after the insert/update operations have
     * been done. Use this for filtering data, notifying users of changes etc.pp. ...
     *
     * @param array $values   The values of the submitted form
     * @param string $queryType If the standard query behaviour ain't good enough for you, you can force a certain type of query
     * @return boolean        TRUE if database operations were performed, FALSE if not
     * @access public
     */
    function processForm($values)
    {
        if ($this->elementNamePrefix !== '' || $this->elementNamePostfix !== '') {
            $origValues = $values;
            $values = $this->_getMyValues($values);
        }
        $this->debug('<br>...processing form data...<br>');
        if (method_exists($this->_do, 'preprocessform')) {
            if ($this->useCallTimePassByReference) {
                eval('$this->_do->preProcessForm(&$values, &$this);');
            } else {
                $this->_do->preProcessForm($values, $this);
            }
        }
        $editableFields = $this->_getUserEditableFields();
        $tableFields = $this->_do->table();
        if (!is_array($links = $this->_do->links())) {
            $links = array();
        }

        foreach ($values as $field => $value) {
            $this->debug('Field '.$field.' ');
            // Double-check if the field may be edited by the user... if not, don't
            // set the submitted value, it could have been faked!
            if (in_array($field, $editableFields)) {
                if (isset($tableFields[$field])) {
                    if (($tableFields[$field] & DB_DATAOBJECT_DATE) || in_array($field, $this->dateFields)) {
                        $this->debug('DATE CONVERSION for using callback from '.$value.' ...');
                        $value = call_user_func($this->dateToDatabaseCallback, $value);
                    } elseif (($tableFields[$field] & DB_DATAOBJECT_TIME) || in_array($field, $this->timeFields)) {
                        $this->debug('TIME CONVERSION for using callback from '.$value.' ...');
                        $value = call_user_func($this->dateToDatabaseCallback, $value);
                    } elseif (is_array($value)) {
                        if (isset($value['tmp_name'])) {
                            $this->debug(' (converting file array) ');
                            $value = $value['name'];
                        //JUSTIN
                        //This is not really a valid assumption IMHO. This should only be done if the type is
                        // date or the field is in dateFields
                        /*} else {
                            $this->debug("DATE CONVERSION using callback from $value ...");
                            $value = call_user_func($this->dateToDatabaseCallback, $value);*/
                        }
                    }
                    if (isset($links[$field])) {
                        if ($value === '') {
                            $this->debug('Casting to NULL');
                            require_once('DB/DataObject/Cast.php');
                            $value = DB_DataObject_Cast::sql('NULL');
                        }
                    }
                    $this->debug('is substituted with "'.print_r($value, true).'".<br/>');

                    // See if a setter method exists in the DataObject - if so, use that one
                    if (method_exists($this->_do, 'set' . $field)) {
                        $this->_do->{'set'.$field}($value);
                    } else {
                        // Otherwise, just set the property 'normally'...
                        $this->_do->$field = $value;
                    }
                } else {
                    $this->debug('is not a valid field.<br/>');
                }
            } else {
                $this->debug('is defined not to be editable by the user!<br/>');
            }
        }
        foreach ($this->booleanFields as $boolField) {
            if (!isset($values[$boolField])) {
                $this->_do->$boolField = 0;
            }
        }
        foreach ($tableFields as $field => $type) {
            if ($type & DB_DATAOBJECT_BOOL && !isset($values[$field])) {
                $this->_do->$field = 0;
            }
        }

        $dbOperations = true;
        if ($this->validateOnProcess === true) {
            $this->debug('Validating data... ');
            if (is_array($this->validateData())) {
                $dbOperations = false;
            }
        }

        $pk = $this->_getPrimaryKey($this->_do);
            
        // Data is valid, let's store it!
        if ($dbOperations) {

            //take care of linkNewValues
            if (isset($values['__DB_DataObject_FormBuilder_linkNewValue_'])) {
                foreach ($values['__DB_DataObject_FormBuilder_linkNewValue_'] as $elName => $subTable) {
                    if ($values[$elName] == '--New Value--') {
                        $this->_prepareForLinkNewValue($elName, $subTable);
                        $this->_linkNewValueForms[$elName]->process(array(&$this->_linkNewValueFBs[$elName], 'processForm'), false);
                        $subPk = $this->_linkNewValueFBs[$elName]->_getPrimaryKey($this->_linkNewValueDOs[$elName]);
                        $this->_do->$elName = $values[$elName] = $this->_linkNewValueDOs[$elName]->$subPk;
                    }
                }
            }

            $action = $this->_queryType;
            if ($this->_queryType == DB_DATAOBJECT_FORMBUILDER_QUERY_AUTODETECT) {
                // Could the primary key be detected?
                if ($pk === false) {
                    // Nope, so let's exit and return false. Sorry, you can't store data using
                    // processForm with this DataObject unless you do some tweaking :-(
                    $this->debug('Primary key not detected - storing data not possible.');
                    return false;   
                }
                
                $action = DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEUPDATE;
                if (!isset($this->_do->$pk) || !strlen($this->_do->$pk)) {
                    $action = DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEINSERT;
                }
            }
            
            switch ($action) {
                case DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEINSERT:
                    $id = $this->_do->insert();
                    $this->debug('ID ('.$pk.') of the new object: '.$id.'<br/>');
                    break;
                case DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEUPDATE:
                    $this->_do->update();
                    $this->debug('Object updated.<br/>');
                    break;
            }

            // process tripleLinks
            foreach ($this->tripleLinks as $tripleLink) {
                unset($do);
                $do =& DB_DataObject::factory($tripleLink['table']);

                $fromField = $tripleLink['fromField'];
                $toField1 = $tripleLink['toField1'];
                $toField2 = $tripleLink['toField2'];

                if (isset($values['__tripleLink_'.$tripleLink['table']])) {
                    $rows = $values['__tripleLink_'.$tripleLink['table']];
                } else {
                    $rows = array();
                }
                $links = $do->links();
                list ($linkTable, $linkField) = explode(':', $links[$fromField]);
                $do->$fromField = $this->_do->$linkField;
                $do->selectAdd();
                $do->selectAdd($toField1);
                $do->selectAdd($toField2);
                if ($doKey = $this->_getPrimaryKey($do)) {
                    $do->selectAdd($doKey);
                }
                if (method_exists($this->_do, 'preparelinkeddataobject')) {
                    if ($this->useCallTimePassByReference) {
                        eval('$this->_do->prepareLinkedDataObject(&$do, \'__tripleLink_\'.$tripleLink[\'table\']);');
                    } else {
                        $this->_do->prepareLinkedDataObject($do, '__tripleLink_'.$tripleLink['table']);
                    }
                }
                if ($do->find()) {
                    $oldFieldValues = array();
                    while ($do->fetch()) {
                        if (isset($rows[$do->$toField1]) && in_array($do->$toField2, $rows[$do->$toField1])) {
                            $oldFieldValues[$do->$toField1][$do->$toField2] = true;
                        } else {
                            $do->delete();
                        }
                    }
                }

                if (count($rows) > 0) {
                    foreach ($rows as $rowid => $row) {
                        if (count($row) > 0) {
                            foreach ($row as $fieldvalue) {
                                if (!isset($oldFieldValues[$rowid]) || !isset($oldFieldValues[$rowid][$fieldvalue])) {
                                    unset($do);
                                    $do =& DB_DataObject::factory($tripleLink['table']);
                                    $do->$fromField = $this->_do->$linkField;
                                    $do->$toField1 = $rowid;
                                    $do->$toField2 = $fieldvalue;
                                    $do->insert();
                                }
                            }
                        }
                    }
                }
            }
            
            //process crossLinks
            foreach ($this->crossLinks as $crossLink) {
                unset($do);
                $do =& DB_DataObject::factory($crossLink['table']);

                $fromField = $crossLink['fromField'];
                $toField = $crossLink['toField'];

                if (isset($values['__crossLink_'.$crossLink['table']])) {
                    if ($crossLink['type'] == 'select') {
                        $fieldvalues = array();
                        foreach ($values['__crossLink_'.$crossLink['table']] as $value) {
                            $fieldvalues[$value] = $value;
                        }
                    } else {
                        $fieldvalues = $values['__crossLink_'.$crossLink['table']];
                    }
                } else {
                    $fieldvalues = array();
                }

                /*if (isset($values['__crossLink_'.$crossLink['table'].'__extraFields'])) {
                        $extraFieldValues = $values['__crossLink_'.$crossLink['table'].'__extraFields'];
                    } else {
                        $extraFieldValues = array();
                    }*/

                $links = $do->links();
                list ($linkTable, $linkField) = explode(':', $links[$fromField]);
                $do->$fromField = $this->_do->$linkField;
                $do->selectAdd();
                $do->selectAdd($toField);
                $do->selectAdd($fromField);
                if ($doKey = $this->_getPrimaryKey($do)) {
                    $do->selectAdd($doKey);
                }
                if (method_exists($this->_do, 'preparelinkeddataobject')) {
                    if ($this->useCallTimePassByReference) {
                        eval('$this->_do->prepareLinkedDataObject(&$do, \'__crossLink_\'.$crossLink[\'table\']);');
                    } else {
                        $this->_do->prepareLinkedDataObject($do, '__crossLink_'.$crossLink['table']);
                    }
                }
                if ($do->find()) {
                    $oldFieldValues = array();
                    while ($do->fetch()) {
                        if (isset($fieldvalues[$do->$toField])) {
                            $oldFieldValues[$do->$toField] = clone($do);
                        } else {
                            $do->delete();
                        }
                    }
                }
                if (count($fieldvalues) > 0) {
                    foreach ($fieldvalues as $fieldvalue) {
                        $crossLinkPrefix = $this->elementNamePrefix.'__crossLink_'.$crossLink['table'].'__'.$fieldvalue.'_';
                        $crossLinkPostfix = '_'.$this->elementNamePostfix;
                        if (isset($oldFieldValues[$fieldvalue])) {
                            if (isset($do->fb_crossLinkExtraFields)
                                && (!isset($crossLink['type']) || ($crossLink['type'] !== 'select'))) {
                                $this->_extraFieldsFb[$crossLinkPrefix.$crossLinkPostfix]->processForm(isset($origValues)
                                                                                                       ? $origValues
                                                                                                       : $values);
                                /*$do = $oldFieldValues[$fieldvalue];
                                    $update = false;
                                    foreach ($do->fb_crossLinkExtraFields as $extraField) {
                                        if ($do->$extraField !== $extraFieldValues[$fieldvalue][$extraField]) {
                                            $update = true;
                                            $do->$extraField = $extraFieldValues[$fieldvalue][$extraField];
                                        }
                                    }
                                    if ($update) {
                                        $do->update();
                                    }*/
                            }
                        } else {
                            if (isset($do->fb_crossLinkExtraFields)
                                && (!isset($crossLink['type']) || ($crossLink['type'] !== 'select'))) {
                                $insertValues = isset($origValues) ? $origValues : $values;
                                $insertValues[$crossLinkPrefix.$fromField.$crossLinkPostfix] = $this->_do->$linkField;
                                $insertValues[$crossLinkPrefix.$toField.$crossLinkPostfix] = $fieldvalue;
                                $this->_extraFieldsFb[$crossLinkPrefix.$crossLinkPostfix]->fieldsToRender[] = $fromField;
                                $this->_extraFieldsFb[$crossLinkPrefix.$crossLinkPostfix]->fieldsToRender[] = $toField;
                                $this->_extraFieldsFb[$crossLinkPrefix.$crossLinkPostfix]->processForm($insertValues);
                                /*foreach ($do->fb_crossLinkExtraFields as $extraField) {
                                        $do->$extraField = $extraFieldValues[$do->$toField][$extraField];
                                    }*/
                            } else {
                                unset($do);
                                $do =& DB_DataObject::factory($crossLink['table']);
                                $do->$fromField = $this->_do->$linkField;
                                $do->$toField = $fieldvalue;
                                $do->insert();
                            }
                        }
                    }
                }
            }

            foreach ($this->reverseLinks as $reverseLink) {
                $elName = '__reverseLink_'.$reverseLink['table'].'_'.$reverseLink['field'];
                unset($do);
                $do =& DB_DataObject::factory($reverseLink['table']);
                if (method_exists($this->_do, 'preparelinkeddataobject')) {
                    if ($this->useCallTimePassByReference) {
                        eval('$this->_do->prepareLinkedDataObject(&$do, $key);');
                    } else {
                        $this->_do->prepareLinkedDataObject($do, $key);
                    }
                }
                if (!is_array($rLinks = $do->links())) {
                    $rLinks = array();
                }
                $rPk = $this->_getPrimaryKey($do);
                $rFields = $do->table();
                list($lTable, $lField) = explode(':', $rLinks[$reverseLink['field']]);
                if ($do->find()) {
                    while ($do->fetch()) {
                        unset($newVal);
                        if (isset($values[$elName][$do->$rPk])) {
                            if ($do->{$reverseLink['field']} != $this->_do->$lField) {
                                $do->{$reverseLink['field']} = $this->_do->$lField;
                                $do->update();
                            }
                        } elseif ($do->{$reverseLink['field']} == $this->_do->$lField) {
                            if (isset($reverseLink['defaultLinkValue'])) {
                                $do->{$reverseLink['field']} = $reverseLink['defaultLinkValue'];
                                $do->update();
                            } else {
                                if ($rFields[$reverseLink['field']] & DB_DATAOBJECT_NOTNULL) {
                                    //ERROR!!
                                    $this->debug('Checkbox in reverseLinks unset when link field may not be null');
                                } else {
                                    require_once('DB/DataObject/Cast.php');
                                    $do->{$reverseLink['field']} = DB_DataObject_Cast::sql('NULL');
                                    $do->update();
                                }
                            }
                        }
                    }
                }
            }
        }

        if (method_exists($this->_do, 'postprocessform')) {
            if ($this->useCallTimePassByReference) {
                eval('$this->_do->postProcessForm(&$values, &$this);');
            } else {
                $this->_do->postProcessForm($values, $this);
            }
        }

        return $dbOperations;
    }


    /**
     * Takes a multi-dimentional array and flattens it. If a value in the array is an array,
     * its keys are added as [key] to the original key.
     * Ex:
     * array('a' => 'a',
     *       'b' => array('a' => 'a',
     *                    'b' => array('a' => 'a',
     *                                 'b' => 'b')),
     *       'c' => 'c')
     * becomes
     * array('a' => 'a',
     *       'b[a]' => 'a',
     *       'b[b][a]' => 'a',
     *       'b[b][b]' => 'b',
     *       'c' => 'c')
     *
     * @param  array the array to convert
     * @return array the flattened array
     */
    /*function _multiArrayToSingleArray($arr) {
        do {
            $arrayFound = false;
            foreach ($arr as $key => $val) {
                if (is_array($val)) {
                    unset($arr[$key]);
                    foreach ($val as $key2 => $val2) {
                        $arr[$key.'['.$key2.']'] = $val2;
                    }
                    $arrayFound = true;
                }
            }
        } while ($arrayFound);
        return $arr;
    }*/


    /**
     * Takes a full request array and extracts the values for this formBuilder instance.
     * Removes the element name prefix and postfix
     * Will only return values whose key is prefixed with the prefix and postfixed by the postfix
     *
     * @param  array array from $_REQUEST
     * @return array array indexed by real field name
     */
    function _getMyValues(&$arr) {
        //$arr = $this->_multiArrayToSingleArray($arr);
        $retArr = array();
        $prefixLen = strlen($this->elementNamePrefix);
        $postfixLen = strlen($this->elementNamePostfix);
        foreach ($arr as $key => $val) {
            if ($prefixLen) {
                if (substr($key, 0, $prefixLen) == $this->elementNamePrefix) {
                    $key = substr($key, $prefixLen);
                } else {
                    $key = false;
                }
            }
            if ($key !== false && $postfixLen) {
                if (substr($key, -$postfixLen) == $this->elementNamePostfix) {
                    $key = substr($key, 0, -$postfixLen);
                } else {
                    $key = false;
                }
            }
            if ($key !== false) {
                $retArr[$key] = $val;
            }
        }
        return $retArr;
    }

    
    /**
     * DB_DataObject_FormBuilder::forceQueryType()
     *
     * You can force the behaviour of the processForm() method by passing one of
     * the following constants to this method:
     *
     * - DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEINSERT:
     *   The submitted data will always be INSERTed into the database
     * - DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEUPDATE:
     *   The submitted data will always be used to perform an UPDATE on the database
     * - DB_DATAOBJECT_FORMBUILDER_QUERY_FORCENOACTION:
     *   The submitted data will overwrite the properties of the DataObject, but no
     *   action will be performed on the database.
     * - DB_DATAOBJECT_FORMBUILDER_QUERY_AUTODETECT:
     *   The processForm() method will try to detect for itself if an INSERT or UPDATE
     *   query has to be performed. This will not work if no primary key field can
     *   be detected for the current DataObject. In this case, no action will be performed.
     *   This is the default behaviour.
     *
     * @param integer $queryType The type of the query to be performed. Please use the preset constants for setting this.
     * @return boolean
     * @access public
     */
    function forceQueryType($queryType = DB_DATAOBJECT_FORMBUILDER_QUERY_AUTODETECT)
    {
        switch ($queryType) {
            case DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEINSERT:
            case DB_DATAOBJECT_FORMBUILDER_QUERY_FORCEUPDATE:
            case DB_DATAOBJECT_FORMBUILDER_QUERY_FORCENOACTION:
            case DB_DATAOBJECT_FORMBUILDER_QUERY_AUTODETECT:
                $this->_queryType = $queryType;
                return true;
                break;
            default:
                return false;
        }
    }


    /**
     * DB_DataObject_FormBuilder::debug()
     *
     * Outputs a debug message, if the debug setting in the DataObject.ini file is
     * set to 1 or higher.
     *
     * @param string $message  The message to printed to the browser
     * @access public
     * @see DB_DataObject::debugLevel()
     */
    function debug($message)
    {
        if (DB_DataObject::debugLevel() > 0) {
            echo '<pre><b>FormBuilder:</b> '.$message."</pre>\n";
        }
    }
    
    /**
     * DB_DataObject_FormBuilder::_getFieldsToRender()
     *
     * If the "fb_fieldsToRender" property in a DataObject is not set, all fields
     * will be rendered as form fields.
     * When the property is set, a field will be rendered only if:
     * 1. it is a primary key
     * 2. it's explicitly requested in $do->fb_fieldsToRender
     *
     * @access private
     * @return array   The fields that shall be rendered
     */
    function _getFieldsToRender()
    {
        $table = $this->_do->table();
        if (!is_array($table) || !$table) {
            $this->debug('ERROR: DataObject has no fields reported by table(), something is definately wrong');
            $table = array();
        }
        $all_fields = array_merge($table, $this->_getSpecialElementNames());
        if ($this->fieldsToRender) {
            $fieldsToRender =& $this->fieldsToRender;
        }
        // a little workaround to get an array like [FIELD_NAME] => FIELD_TYPE (for use in _generateForm)
        // maybe there's some better way to do this:
        $result = array();

        $key_fields = $this->_do->keys();
        if (!is_array($key_fields) || !$key_fields) {
            $this->debug('WARNING: DataObject has no keys reported by keys()');            
            $key_fields = array();
        }

        foreach ($all_fields as $key => $value) {
            if (!isset($fieldsToRender)
                || in_array($key, $key_fields)
                || in_array($key, $fieldsToRender)) {
                $result[$key] = $all_fields[$key];
                if (isset($this->preDefGroups[$key])
                    && !in_array($this->preDefGroups[$key], $result)) {
                    $result[$this->preDefGroups[$key]] = DB_DATAOBJECT_FORMBUILDER_GROUP;
                }
            }
        }

        if (count($result) > 0) {
            return $result;
        }
        return $all_fields;
    }
    
    
    /**
     * DB_DataObject_FormBuilder::_getUserEditableFields()
     *
     * Normally, all fields in a form are editable by the user. If you want to
     * make some fields uneditable, you have to set the "fb_userEditableFields" property
     * with an array that contains the field names that actually can be edited.
     * All other fields will be freezed (which means, they will still be a part of
     * the form, and they values will still be displayed, but only as plain text, not
     * as form elements).
     *
     * @access private
     * @return array   The fields that shall be editable.
     */
    function _getUserEditableFields()
    {
        // if you don't want any of your fields to be editable by the user, set fb_userEditableFields to
        // "array()" in your DataObject-derived class
        if ($this->userEditableFields) {
            return $this->userEditableFields;
        }
        // all fields may be updated by the user since fb_userEditableFields is not set
        if ($this->fieldsToRender) {
            return $this->fieldsToRender;
        }
        return array_keys($this->_getFieldsToRender());
    }
    
}

?>