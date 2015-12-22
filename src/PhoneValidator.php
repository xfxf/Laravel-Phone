<?php namespace Propaganistas\LaravelPhone;

use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberType;
use Propaganistas\LaravelPhone\Exceptions\NoValidCountryFoundException;
use Propaganistas\LaravelPhone\Exceptions\InvalidParameterException;

class PhoneValidator
{

    /**
     * The phone number attribute.
     *
     * @var string
     */
    protected $attribute;

    /**
     * Data from the validator instance.
     *
     * @var array
     */
    protected $data;

    /**
     * Countries to validate against.
     *
     * @var array
     */
    protected $allowedCountries = [];

    /**
     * Untransformed phone number types to validate against.
     *
     * @var array
     */
    protected $untransformedTypes = [];

    /**
     * Transformed phone number types to validate against.
     *
     * @var array
     */
    protected $allowedTypes = [];

    /**
     * Supplied validator parameters.
     *
     * @var array
     */
    protected $parameters;

    /**
     * Instance of PhoneNumberUtil.
     * @var object
     */
    protected $phoneUtil;

    /**
     * Whether to allow lenient checking of numbers (i.e. landline without area codes)
     * @var object
     */
    protected $allowLenient;

    /**
     * Validates a phone number.
     *
     * @param  string $attribute
     * @param  mixed  $value
     * @param  array  $parameters
     * @param  object $validator
     * @return bool
     * @throws \Propaganistas\LaravelPhone\Exceptions\InvalidParameterException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NoValidCountryFoundException
     */
    public function validatePhone($attribute, $value, array $parameters, $validator)
    {
        $this->attribute = $attribute;
        $this->data = $validator->getData();
        $this->parameters = array_map('strtoupper', $parameters);

        $this->determineCountries();
        $this->determineLenient();
        $this->determineTypes();
        $this->checkLeftoverParameters();

        $this->phoneUtil = PhoneNumberUtil::getInstance();

        // Perform validation.
        foreach ($this->allowedCountries as $country) {
            try {
                // For default countries or country field, the following throws NumberParseException if
                // not parsed correctly against the supplied country.
                // For automatic detection: tries to discover the country code using from the number itself.
                $phoneProto = $this->phoneUtil->parse($value, $country);

                // For automatic detection, the number should have a country code.
                // Check if type is allowed.
                if ($phoneProto->hasCountryCode() && empty($this->allowedTypes) || in_array($this->phoneUtil->getNumberType($phoneProto),
                        $this->allowedTypes)
                ) {

                    // Automatic detection:
                    if ($country == 'ZZ') {
                        // Validate if the international phone number is valid for its contained country.
                        return $this->isValidNumber($phoneProto);
                    }

                    // Validate number against the specified country.  Return only if success.
                    // If failure, continue loop to next specfied country
                    $success = $this->isValidNumberForRegion($phoneProto, $country);

                    if ($success) {
                        return true;
                    }
                }

            } catch (NumberParseException $e) {
                // Proceed to default validation error.
            }
        }

        // All specified country validations have failed.
        return false;
    }

    /**
     * Conditional wrapper for phoneUtil->isValidNumber which allows for leniency
     *
     * @param $number
     * @return mixed
     */
    private function isValidNumber($number) {
        if (isset($this->allowLenient)) {
            return $this->phoneUtil->isPossibleNumber($number);
        } else {
            return $this->phoneUtil->isValidNumber($number);
        }
    }

    /**
     * Conditional wrapper for phoneUtil->isValidNumberForRegion which allows for leniency
     *
     * @param $number
     * @param $country
     * @return mixed
     */
    private function isValidNumberForRegion($number, $country) {
        if (isset($this->allowLenient)) {
            return $this->phoneUtil->isPossibleNumber($number, $country);
        } else {
            return $this->phoneUtil->isValidNumberForRegion($number, $country);
        }
    }

    /**
     * Checks if the supplied string is a valid country code using some arbitrary country validation.
     * If using a package based on umpirsky/country-list, invalidate the option 'ZZ => Unknown or invalid region'.
     *
     * @param  string $country
     * @return bool
     */
    public function isPhoneCountry($country)
    {
        return (strlen($country) === 2 && ctype_alpha($country) && ctype_upper($country) && $country != 'ZZ');
    }

    /**
     * Checks if the supplied string is a valid phone number type.
     *
     * @param  string $type
     * @return bool
     */
    public function isPhoneType($type)
    {
        // Legacy support.
        $type = ($type == 'LANDLINE' ? 'FIXED_LINE' : $type);

        return defined($this->constructPhoneTypeConstant($type));
    }

    /**
     * Constructs the corresponding namespaced class constant for a phone number type.
     *
     * @param  string $type
     * @return string
     */
    protected function constructPhoneTypeConstant($type)
    {
        return '\libphonenumber\PhoneNumberType::' . $type;
    }

    /**
     * Sets the countries to validate against.
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NoValidCountryFoundException
     */
    protected function determineCountries()
    {
        // Check for the existence of a country field.
        $field = $this->attribute . '_country';
        if (isset($this->data[$field])) {
            $this->allowedCountries = ($this->isPhoneCountry($this->data[$field])) ? [$this->data[$field]] : [];
            // No exception should be thrown since empty country fields should validate to false.
        } // Or if we need to parse for automatic detection.
        elseif (in_array('AUTO', $this->parameters)) {
            $this->allowedCountries = ['ZZ'];
        } // Else use the supplied parameters.
        else {
            $this->allowedCountries = array_filter($this->parameters, function ($item) {
                return $this->isPhoneCountry($item);
            });

            if (empty($this->allowedCountries)) {
                throw new NoValidCountryFoundException;
            }
        }
    }

    /**
     * Sets whether to run the validator in a more lenient mode.
     */
    protected function determineLenient()
    {
        // Check for for the LENIENT parameter
        if (in_array('LENIENT', $this->parameters)) {
            $this->allowLenient = array('LENIENT');
        }
    }

    /**
     * Sets the phone number types to validate against.
     */
    protected function determineTypes()
    {
        // Get phone types.
        $this->untransformedTypes = array_filter($this->parameters, function ($item) {
            return $this->isPhoneType($item);
        });

        // Transform valid types to their namespaced class constant.
        $this->allowedTypes = array_map(function ($item) {
            return constant($this->constructPhoneTypeConstant($item));
        }, $this->untransformedTypes);

        // Add in the unsure number type if applicable.
        if (array_intersect(['FIXED_LINE', 'MOBILE'], $this->parameters)) {
            $this->allowedTypes[] = PhoneNumberType::FIXED_LINE_OR_MOBILE;
        }
    }

    /**
     * Checks for parameter leftovers to force developers to write proper code.
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\InvalidParameterException
     */
    protected function checkLeftoverParameters()
    {
        // Remove the automatic detection option if applicable.
        $leftovers = array_diff($this->parameters, $this->allowedCountries, $this->allowLenient, $this->untransformedTypes, array('AUTO'));
        if (!empty($leftovers)) {
            throw new InvalidParameterException(implode(', ', $leftovers));
        }
    }

}
