<?php

return [
    'MatrixSynapseIntegrator' => [
        'matrixDomain' => 'matrix.'.getenv("DOMAIN_SUFFIX"),
        'homeServerUrl' => 'https://matrix'.getenv("DOMAIN_SUFFIX"),
        'matrixSynapseSharedSecretAuth' => false,
        'corporalSharedAuthToken' => 'SynapseSharedSecretAuthenticatorTineSharedSecret',
        'corporalUserId' => '@corporal:matrix.'.getenv("DOMAIN_SUFFIX"),
    ],
];