<?php

namespace APP\plugins\generic\swordv3\classes;

use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldText;
use PKP\components\forms\FormComponent;
use PKP\context\Context;

class ServiceForm extends FormComponent
{
    public const FORM_SWORDV3_SERVICE = 'swordv3service';
    public $id = self::FORM_SWORDV3_SERVICE;
    public $method = 'PUT';

    public function __construct(string $action, array $locales, Context $context, ?array $data = null)
    {
        $this->action = $action;
        $this->locales = $locales;

        $this->addField(new FieldText('name', [
            'label' => __('plugins.generic.swordv3.service.name'),
            'isRequired' => true,
            'value' => $data ? $data['name'] : '',
        ]));

        $this->addField(new FieldText('url', [
            'label' => __('plugins.generic.swordv3.service.url'),
            'isRequired' => true,
            'value' => $data ? $data['url'] : '',
        ]));

        $this->addField(new FieldOptions('authMode', [
            'label' => __('plugins.generic.swordv3.service.authMode'),
            'type' => 'radio',
            'isRequired' => true,
            'options' => [
                ['value' => 'Basic', 'label' => __('plugins.generic.swordv3.service.authMode.basic')],
                ['value' => 'APIKey', 'label' => __('plugins.generic.swordv3.service.authMode.apiKey')],
            ],
            'value' => $data ? $data['authMode'] : 'Basic',
        ]));

        $this->addField(new FieldText('username', [
            'label' => __('plugins.generic.swordv3.service.username'),
            'isRequired' => true,
            'value' => $data && isset($data['username']) ? $data['username'] : '',
            'showWhen' => ['authMode', 'Basic'],
        ]));

        $this->addField(new FieldText('password', [
            'label' => __('plugins.generic.swordv3.service.password'),
            'isRequired' => true,
            'inputType' => 'password',
            'value' => $data && isset($data['password']) ? $data['password'] : '',
            'optIntoEdit' => isset($data['password']),
            'optIntoEditLabel' => __('common.edit'),
            'showWhen' => ['authMode', 'Basic'],
        ]));

        $this->addField(new FieldText('apiKey', [
            'label' => __('plugins.generic.swordv3.service.apiKey'),
            'isRequired' => true,
            'inputType' => 'password',
            'value' => $data && isset($data['apiKey']) ? $data['apiKey'] : '',
            'optIntoEdit' => isset($data['apiKey']),
            'optIntoEditLabel' => __('common.edit'),
            'showWhen' => ['authMode', 'APIKey'],
        ]));
    }
}
