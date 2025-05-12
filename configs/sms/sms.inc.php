<?php
// sms gateway config
return [
    'sms' => [
        'sms_adapters' => [
            'adapter_configs' => [[
                'name' => 'sms1',
                'adapter_class' => 'Tinebase_Model_Sms_MockAdapter',
                'adapter_config' => [
                    'url' => 'https://shoo.tld/restapi/message',
                    'body' => '{"encoding":"auto","body":"{{ message }}","originator":"{{ app.branding.title }}","recipients":["{{ cellphonenumber }}"],"route":"2345"}',
                    'method' => 'POST',
                    'headers' => [
                        'Auth-Bearer' => 'unittesttokenshaaaaalalala'
                    ],
                ],
            ]],
        ],
    ],
];
