<?php

namespace Cknow\Money {
    if (!class_exists('Cknow\\Money\\Money')) {
        class Money
        {
            public static array $constructed = [];

            public function __construct(private int $amount, private string $currency)
            {
                self::$constructed[] = compact('amount', 'currency');
            }

            public function format(): string
            {
                return $this->currency . ':' . $this->amount;
            }
        }
    }
}

namespace {
    use Cknow\Money\Money;
    use Fleetbase\Support\Utils;

    test('utils money format normalizes numeric input before formatting with the money adapter', function () {
        Money::$constructed = [];

        expect(Utils::moneyFormat('USD 1,234.56', 'USD'))->toBe('USD:123456')
            ->and(Money::$constructed)->toBe([
                [
                    'amount'   => 123456,
                    'currency' => 'USD',
                ],
            ]);
    });
}
