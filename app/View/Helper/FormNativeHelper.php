<?php
App::uses('FormHelper', 'View/Helper');

class FormNativeHelper extends FormHelper
{
    protected function _getInput($args)
    {
        if ($args['type'] === 'dateNative') {
            return $this->date($args['fieldName'], $args['options']);
        } else {
            return parent::_getInput($args);
        }
    }
}
