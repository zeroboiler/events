<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;

describe('ConditionEngine deep nested dot-notation', function (): void {
    $engine = new ConditionEngine;

    test('it evaluates 4-level deep nested fields', function () use ($engine): void {
        $conditions = [
            'user.profile.settings.notifications.email' => true,
        ];
        $payload = [
            'user' => [
                'profile' => [
                    'settings' => [
                        'notifications' => [
                            'email' => true,
                        ],
                    ],
                ],
            ],
        ];

        expect($engine->matches($conditions, $payload))->toBeTrue();
    });

    test('it returns false when deep nested field is missing', function () use ($engine): void {
        $conditions = [
            'a.b.c.d.e.f' => 'exists',
        ];
        $payload = [
            'a' => [
                'b' => [
                    'c' => [
                        'd' => [
                            'e' => 'wrong',
                        ],
                    ],
                ],
            ],
        ];

        expect($engine->matches($conditions, $payload))->toBeFalse();
    });

    test('it handles 5-level nesting with comparison operators', function () use ($engine): void {
        $conditions = [
            'level1.level2.level3.level4.level5' => ['>', 10],
        ];
        $payload = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'level5' => 25,
                        ],
                    ],
                ],
            ],
        ];

        expect($engine->matches($conditions, $payload))->toBeTrue();
    });

    test('it handles nested field with array operator', function () use ($engine): void {
        $conditions = [
            'order.items[0].sku' => ['=', 'PROD-001'],
        ];
        $payload = [
            'order' => [
                'items' => [
                    '0' => [
                        'sku' => 'PROD-001',
                        'qty' => 5,
                    ],
                ],
            ],
        ];

        // This will return false because the dot-notation key uses [0] which
        // won't be found as a literal key in the nested array.
        // The test documents the current behavior.
        expect($engine->matches($conditions, $payload))->toBeFalse();
    });

    test('it handles multiple deep nested conditions simultaneously', function () use ($engine): void {
        $conditions = [
            'a.b.c' => 'deep1',
            'x.y.z.w' => 'deep2',
            'root' => 'top',
        ];
        $payload = [
            'root' => 'top',
            'a' => ['b' => ['c' => 'deep1']],
            'x' => ['y' => ['z' => ['w' => 'deep2']]],
        ];

        expect($engine->matches($conditions, $payload))->toBeTrue();
    });

    test('it handles null at intermediate nesting level', function () use ($engine): void {
        $conditions = [
            'user.address.city' => 'Istanbul',
        ];
        $payload = [
            'user' => [
                'address' => null,
            ],
        ];

        expect($engine->matches($conditions, $payload))->toBeFalse();
    });
});
