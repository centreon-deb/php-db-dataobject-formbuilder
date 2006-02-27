<?php
/**
 *
 */

require_once('HTML/QuickForm/static.php');

if ( substr(phpversion(),0,1) != 5) {
    if (!function_exists('clone')) {
        // emulate clone  - as per php_compact, slow but really the correct behaviour..
        eval('function clone($t) { $r = $t; if (method_exists($r,"__clone")) { $r->__clone(); } return $r; }');
    }
}

/**
 * A few caveats: this element *must* be either created by a call to addElement on your main form (with the
 * construction parameters, not an element object) or you must call setParentForm on the element. If you
 * don't, the rules on the subform won't work. Here's the two usages (assuming $form is the main form
 * and $subForm is the sub-form):
 * 
 * require_once('HTML/QuickForm/SubForm.php');
 * $form->addElement('subForm', 'subFormElementName', 'Sub Form Label', $subForm);
 * //NOTE: with this version $subForm is now a copy in the element so changing
 * //  $subForm now will not change the form within the element
 * 
 * OR
 * 
 * require_once('HTML/QuickForm/SubForm.php');
 * $el =& HTML_QuickForm::createElement('subFormElementName', 'Sub Form Label', $subForm);
 * $el->setParentForm($form);
 * $form->addElement($el);
 * 
 * This also uses a few hacks which access HTML_QuickForm internals which is a no-no, but it's the only
 * way I could get unfreeze and setPersistentFreeze to work as HTML_QuickForm doesn't implement these
 * functions (perhaps these should be added?). This also only works with the default QF renderer, but
 * it shouldn't be too hard to fix it.
 * 
 * This *should* also work for subforms within subforms. ;-)
 *
 * The following are quick instructions on how to get a dynamic subform
 * working (i.e. a subform which is displayed / hidden by JS and
 * conditionally validated).
 *
 * Add a hidden field which holds when the sub form is displayed.
 * $form->addElement('hidden', 'subFormDisplayed');
 * 
 * Use this CSS class:
 * .hidden {
 *   overflow: hidden;
 *   visibility: hidden;
 *   display: none;
 * }
 * 
 * Apply that class to a div surrounding the SubForm (I use an altered
 * elementTemplate for QF). You also need a link which calls the
 * javascript below (I've added it to the template for simplicity).
 * Also, only hide it if the sub form was not displayed (if the
 * validation fails you need to redisplay the form).
 *
 * $renderer =& HTML_QuickForm::defaultRenderer();
 * $renderer->setElementTemplate(str_replace('{element}',
 *                                           '<a href="javascript:void();" onclick="showSubFormElement()">Show Sub Form</a>
 * <div class="'.($_REQUEST['subFormDisplayed'] ? '' : 'hidden')." id="idForElementDiv">{element}</div>',
 *                                           $renderer->_elementTemplate),
 *                               'subFormElement');
 * 
 * 
 * Add the JavaScript with the function somewhere in your code.
 *
 * <script language="javascript">
 * function newCorrectiveAction() {
 *   if(document.getElementById("idForElementDiv").className == "hidden") {
 *     document.getElementById("idForElementDiv").className = "";
 *     document.getElementById("subFormDisplayed").value = "1";
 *   } else {
 *     document.getElementById("idForElementDiv").className = "hidden";
 *     document.getElementById("subFormDisplayed").value = "0";
 *   }
 * }
 * </script>
 * 
 * Now add the sub form element and also make sure to set up the
 * conditional validation. (assuming $subForm is your completed sub
 * form. Note that there may be reference problems here, it's best to
 * have the sub form finished before creating the element).
 *
 * require_once('HTML/QuickForm/SubForm.php');
 * function subFormDisplayed($values) {
 *   return $values['subFormDisplayed'] == 1;
 * }
 * $el =& HTML_QuickForm::createElement('subForm', 'subFormElement', '', $subForm);
 * $el->setPreValidationCallback('subFormDisplayed');
 */
class HTML_QuickForm_SubForm extends HTML_QuickForm_static {
    var $_subForm;
    var $_parentForm;
    var $_name;
    var $_preValidationCallback;
    var $_clientValidationSet = false;

    function HTML_QuickForm_SubForm($name=null, $label=null, $form=null)
    {
        if ($form !== null) {
            $this->setForm($form);
        }
        HTML_QuickForm_static::HTML_QuickForm_static($name, $label);
    }

    function setForm(&$form)
    {
        $this->_subForm =& $form;
        $this->_checkForEnctype();
    }


    function accept(&$renderer, $required = null, $error = null)
    {
        $this->_renderer = clone($renderer);
        $renderer->renderElement($this, $required, $error);
    }

    /**
     * renders the element
     *
     * @return string the HTML for the element
     */
    function toHtml()
    {
        if (!isset($this->_renderer) || !is_a($this->_renderer, 'HTML_QuickForm_Renderer_Default')) {
            $this->_renderer = clone(HTML_QuickForm::defaultRenderer());
        }
        $this->_renderer->_html =
            $this->_renderer->_hiddenHtml =
            $this->_renderer->_groupTemplate = 
            $this->_renderer->_groupWrap = '';
        $this->_renderer->_groupElements = array();
        $this->_renderer->_inGroup = false;
        $this->_renderer->setFormTemplate(preg_replace('!</?form[^>]*>!', '', $this->_renderer->_formTemplate));
        $this->_subForm->accept($this->_renderer);
        return $this->_renderer->toHtml();
    }

    function freeze()
    {
        parent::freeze();
        $this->_subForm->freeze();
    }

    function unfreeze()
    {
        parent::unfreeze();
        foreach (array_keys($this->_subForm->_elements) as $key) {
            $this->_subForm->_elements[$key]->unfreeze();
        }
    }

    function setPersistantFreeze($persistant = false)
    {
        parent::setPersistantFreeze($persistant);
        foreach (array_keys($this->subForm->_elements) as $key) {
            $this->_subForm->_elements[$key]->setPersistantFreeze($persistant);
        }
    }

    function exportValue(&$submitValues, $assoc = false)
    {
        return $this->_subForm->exportValues();
    }

    function setParentForm(&$form)
    {
        $this->_parentForm =& $form;
        $this->_parentForm->addFormRule(array(&$this, 'checkSubFormRules'));
        $this->_ruleRegistered = true;
        $this->_checkForEnctype();
    }

    /**
     * If set, the pre validation callback will be called before the sub-form's validation is checked.
     * This is meant to allow the developer to turn off sub-form validation for optional forms.
     */
    function setPreValidationCallback($callback = null) {
        $this->_preValidationCallback = $callback;
    }

    function checkSubFormRules($values)
    {
        if ((!isset($this->_preValidationCallback)
             || !is_callable($this->_preValidationCallback)
             || call_user_func($this->_preValidationCallback, $values))
            && !$this->_subForm->validate()) {
            return array($this->getName() => 'Please fix the errors below');
        } else {
            return true;
        }
    }

    /**
     * Sets this element's name
     *
     * @param string name
     */
    function setName($name)
    {
        $this->_name = $name;
    }

    /**
     * Gets this element's name
     *
     * @return string name
     */
    function getName()
    {
        return $this->_name;
    }

    /**
     * Called by HTML_QuickForm whenever form event is made on this element
     *
     * @param     string  Name of event
     * @param     mixed   event arguments
     * @param     object  calling object
     * @access    public
     * @return    bool    true
     */
    function onQuickFormEvent($event, $arg, &$caller)
    {
        if (is_a($caller, 'html_quickform')) {
            $this->setParentForm($caller);
            if ($event != 'createElement'
                && !$this->_clientValidationSet) {
                if ($caller->elementExists($this->getName())) {
                    $caller->addRule($this->getName(),
                                     '',
                                     'subFormRule',
                                     array('form' => &$this->_subForm),
                                     'client');
                } else {
                    //echo $event.'<br/>';
                    $name = preg_replace('/(.*)__subForm/', '\1', $this->getName());
                    $caller->_rules[$name][] = array(
                                                     'type'        => 'subFormRule',
                                                     'format'      => array('form' => &$this->_subForm,
                                                                            'element' => &$this),
                                                     'message'     => '',
                                                     'validation'  => 'client',
                                                     'reset'       => false,
                                                     'dependent'   => $name
                                                     );
                    /*foreach ($caller->_elements as $el) {
                        echo get_class($el).'<br/>';
                    }
                    $caller->addGroupRule($name,
                                          '',
                                          'subFormRule',
                                          array('form' => &$this->_subForm),
                                          1,
                                          'client');*/
                }
                $this->_clientValidationSet = true;
            }
        }

        switch ($event) {
        case 'updateValue':
            $this->_subForm->_submitValues = $caller->_submitValues;
            $this->_subForm->setDefaults($caller->_defaultValues);
            $this->_subForm->setConstants($caller->_constantValues);
            break;
        default:
            parent::onQuickFormEvent($event, $arg, $caller);
            break;
        }
        return true;
    }

    function _checkForEnctype() {
        if ($this->_subForm && $this->_parentForm) {
            if ($this->_subForm->getAttribute('enctype') == 'multipart/form-data') {
                $this->_parentForm->updateAttributes(array('enctype' => 'multipart/form-data'));
                $this->_parentForm->setMaxFileSize();
            }
            /*foreach (array_keys($this->_subForm->_elements) as $key) {
                if (is_a($this->_subForm->_elements[$key], 'HTML_QuickForm_file')) {
                    $this->_parentForm->updateAttributes(array('enctype' => 'multipart/form-data'));
                    $this->_parentForm->setMaxFileSize();
                }
            }*/
        }
    }
}

require_once('HTML/QuickForm.php');
//if (class_exists('HTML_QuickForm')) {
HTML_QuickForm::registerElementType('subForm', __FILE__, 'HTML_QuickForm_SubForm');
//}

require_once('HTML/QuickForm/Rule.php');
require_once('HTML/QuickForm/RuleRegistry.php');
class HTML_QuickForm_Rule_SubForm extends HTML_QuickForm_Rule {
    function validate($value) {
        return true;
    }

    function getValidationScript($options = null) {
        //print_r_html($options);
        //echo ' validate_'.$options['form']->_attributes['id'].'(frm) ';
        $js = $options['form']->getValidationScript();
        preg_match('/_qfMsg = \'\';\s+(.*)\s+if \(_qfMsg != \'\'\)/s', $js, $matches);
        return array('
if (frm.elements["'.preg_replace('/(.*)__subForm/', '\1', $options['element']->getName()).'"].value == "--New Value--") {
alert(frm.elements[\'genre_id_genre__name\'].value);
  '.$matches[1].'
}
', 'false');
    }

    function register() {
        $rr =& HTML_QuickForm_RuleRegistry::singleton();
        $rr->registerRule('subFormRule',
                          '',//'function',
                          'HTML_QuickForm_Rule_SubForm'
                          //'HTML_QuickForm_Rule_SubForm',
                          //__FILE__
                          );
    }
}
HTML_QuickForm_Rule_SubForm::register();

?>