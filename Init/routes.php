<?php

namespace Okay\Modules\Sviat\Ukrposhta;

return [
    'Sviat_Ukrposhta_cart_cities' => [
        'slug' => 'ajax/ukrposhta/cities',
        'to_front' => true,
        'params' => [
            'controller' => __NAMESPACE__ . '\Controllers\CartController',
            'method' => 'cities',
        ],
    ],
    'Sviat_Ukrposhta_cart_offices' => [
        'slug' => 'ajax/ukrposhta/offices',
        'to_front' => true,
        'params' => [
            'controller' => __NAMESPACE__ . '\Controllers\CartController',
            'method' => 'offices',
        ],
    ],
    'Sviat_Ukrposhta_cart_quote' => [
        'slug' => 'ajax/ukrposhta/quote',
        'to_front' => true,
        'params' => [
            'controller' => __NAMESPACE__ . '\Controllers\CartController',
            'method' => 'quote',
        ],
    ],
];
