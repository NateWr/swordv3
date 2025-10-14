<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\journal\Journal;
use PKP\components\forms\FieldText;
use PKP\components\forms\FormComponent;

class ServiceForm extends FormComponent
{
    public const FORM_SWORDV3_SERVICE = 'swordv3service';
    public $id = self::FORM_SWORDV3_SERVICE;
    public $method = 'PUT';

    /**
     * Constructor
     *
     * @param string $action URL to submit the form to
     * @param array $locales Supported locales
     * @param Journal $context Journal to change settings for
     */
    public function __construct($action, $locales, $context)
    {
        $this->action = $action;
        $this->locales = $locales;

        $this->addField(new FieldText('name', [
            'label' => 'Service name',
            'isRequired' => true,
            'value' => '',
        ]));
    }
}
