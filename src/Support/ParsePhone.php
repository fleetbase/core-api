<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use PragmaRX\Countries\Package\Countries;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

class ParsePhone
{
    public static function phoneNumberUtilInstance()
    {
        return PhoneNumberUtil::getInstance();
    }

    public static function fromModel(Model $model, $options = [], $format = PhoneNumberFormat::E164)
    {
        $phoneUtil = static::phoneNumberUtilInstance();
        $phone = Utils::or($model, ['phone', 'phone_number', 'tel', 'telephone']);
        $country = Utils::or($model, ['country']);
        $currency = Utils::or($model, ['currency']);
        $timezone = Utils::or($model, ['timezone']);
        $parsedNumber = '';

        // if no phone number return null
        if (!$phone) {
            return $phone;
        }

        // if country code is already supplied to phone number
        if (Str::startsWith($phone, '+')) {
            try {
                $parsedNumber = $phoneUtil->parse($phone);
            } catch (NumberParseException $e) {
                // silence...
            }

            if ($phoneUtil->isValidNumber($parsedNumber)) {
                return $phoneUtil->format($parsedNumber, $format);
            }
        }

        if (!$country || !$currency || !$timezone) {
            if (isset($options['country']) && !$country) {
                $country = $options['country'];
            }

            if (isset($options['currency']) && !$currency) {
                $currency = $options['currency'];
            }

            if (isset($options['timezone']) && !$timezone) {
                $timezone = $options['timezone'];
            }
        }

        if (!$country || !$currency || !$timezone) {
            // lookup country from current org
            $company = Auth::getCompany();

            if (!$country) {
                $country = Utils::get($company, 'country');
            }

            if (!$currency) {
                $currency = Utils::get($company, 'currency');
            }

            if (!$timezone) {
                $timezone = Utils::get($company, 'timezone');
            }
        }

        // if model has valid iso2 country code
        if ($country && strlen($country) === 2) {
            try {
                $parsedNumber = $phoneUtil->parse($phone, $country);
            } catch (NumberParseException $e) {
                // silence...
            }

            if ($phoneUtil->isValidNumber($parsedNumber)) {
                return $phoneUtil->format($parsedNumber, $format);
            }
        }

        // if model has iso3 currency
        if ($currency && strlen($currency) === 3) {
            $country = Countries::where('currencies.0', $currency)->first();
            if ($country) {
                $country = $country->cca2;
            }

            try {
                $parsedNumber = $phoneUtil->parse($phone, $country);
            } catch (NumberParseException $e) {
                // silence...
            }

            if ($phoneUtil->isValidNumber($parsedNumber)) {
                return $phoneUtil->format($parsedNumber, $format);
            }
        }

        // if model has timezone
        if ($timezone) {
            $country = Utils::findCountryFromTimezone($timezone)->first();
            if ($country) {
                $country = $country->cca2;
            }

            try {
                $parsedNumber = $phoneUtil->parse($phone, $country);
            } catch (NumberParseException $e) {
                // silence...
            }

            if ($phoneUtil->isValidNumber($parsedNumber)) {
                return $phoneUtil->format($parsedNumber, $format);
            }
        }

        return $phone;
    }

    public static function fromCompany(Company $company,$options = [], $format = PhoneNumberFormat::E164)
    {
        return static::fromModel($company, $options, $format);
    }

    public static function fromUser(User $user,$options = [], $format = PhoneNumberFormat::E164)
    {
        return static::fromModel($user, $options, $format);
    }
}
