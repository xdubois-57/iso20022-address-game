<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for address formatter mapping.
 * Verifies ISO 20022 fields are correctly mapped to address-formatter library fields.
 * Tests the field transformation logic directly without database dependencies.
 */
class AddressFormatterTest extends TestCase
{
    /**
     * Replicate the formatAddressDisplay logic from GameController for testing
     */
    private function formatAddressDisplay(array $data): array
    {
        $countryCode = strtoupper(trim($data['Ctry'] ?? ''));
        return [
            'attention' => trim($data['AdtlAdrInf'] ?? ''),  // Additional info (floor, suite, etc.)
            'road' => trim($data['StrtNm'] ?? ''),             // Street name
            'houseNumber' => trim($data['BldgNb'] ?? ''),     // Building number
            'city' => trim($data['TwnNm'] ?? ''),             // City/town
            'postcode' => trim($data['PstCd'] ?? ''),         // Postal code
            'countryCode' => $countryCode,                      // ISO country code (e.g., 'DE', 'US')
            'country' => $this->getCountryName($countryCode), // Full country name for fallback
        ];
    }

    /**
     * Replicate the getCountryName logic from GameController for testing
     */
    private function getCountryName(string $code): string
    {
        $countries = [
            'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan',
            'AG' => 'Antigua and Barbuda', 'AI' => 'Anguilla', 'AL' => 'Albania',
            'AM' => 'Armenia', 'AO' => 'Angola', 'AQ' => 'Antarctica', 'AR' => 'Argentina',
            'AS' => 'American Samoa', 'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba',
            'AX' => 'Åland Islands', 'AZ' => 'Azerbaijan', 'BA' => 'Bosnia and Herzegovina',
            'BB' => 'Barbados', 'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BF' => 'Burkina Faso',
            'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BI' => 'Burundi', 'BJ' => 'Benin',
            'BL' => 'Saint Barthélemy', 'BM' => 'Bermuda', 'BN' => 'Brunei', 'BO' => 'Bolivia',
            'BQ' => 'Bonaire', 'BR' => 'Brazil', 'BS' => 'Bahamas', 'BT' => 'Bhutan',
            'BV' => 'Bouvet Island', 'BW' => 'Botswana', 'BY' => 'Belarus', 'BZ' => 'Belize',
            'CA' => 'Canada', 'CC' => 'Cocos Islands', 'CD' => 'DR Congo', 'CF' => 'Central African Republic',
            'CG' => 'Republic of the Congo', 'CH' => 'Switzerland', 'CI' => 'Côte d\'Ivoire',
            'CK' => 'Cook Islands', 'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China',
            'CO' => 'Colombia', 'CR' => 'Costa Rica', 'CU' => 'Cuba', 'CV' => 'Cape Verde',
            'CW' => 'Curaçao', 'CX' => 'Christmas Island', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic',
            'DE' => 'Germany', 'DJ' => 'Djibouti', 'DK' => 'Denmark', 'DM' => 'Dominica',
            'DO' => 'Dominican Republic', 'DZ' => 'Algeria', 'EC' => 'Ecuador', 'EE' => 'Estonia',
            'EG' => 'Egypt', 'EH' => 'Western Sahara', 'ER' => 'Eritrea', 'ES' => 'Spain',
            'ET' => 'Ethiopia', 'FI' => 'Finland', 'FJ' => 'Fiji', 'FK' => 'Falkland Islands',
            'FM' => 'Micronesia', 'FO' => 'Faroe Islands', 'FR' => 'France', 'GA' => 'Gabon',
            'GB' => 'United Kingdom', 'GD' => 'Grenada', 'GE' => 'Georgia', 'GF' => 'French Guiana',
            'GG' => 'Guernsey', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GL' => 'Greenland',
            'GM' => 'Gambia', 'GN' => 'Guinea', 'GP' => 'Guadeloupe', 'GQ' => 'Equatorial Guinea',
            'GR' => 'Greece', 'GS' => 'South Georgia', 'GT' => 'Guatemala', 'GU' => 'Guam',
            'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HK' => 'Hong Kong', 'HM' => 'Heard Island',
            'HN' => 'Honduras', 'HR' => 'Croatia', 'HT' => 'Haiti', 'HU' => 'Hungary',
            'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man',
            'IN' => 'India', 'IO' => 'British Indian Ocean Territory', 'IQ' => 'Iraq',
            'IR' => 'Iran', 'IS' => 'Iceland', 'IT' => 'Italy', 'JE' => 'Jersey',
            'JM' => 'Jamaica', 'JO' => 'Jordan', 'JP' => 'Japan', 'KE' => 'Kenya',
            'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia', 'KI' => 'Kiribati', 'KM' => 'Comoros',
            'KN' => 'Saint Kitts and Nevis', 'KP' => 'North Korea', 'KR' => 'South Korea',
            'KW' => 'Kuwait', 'KY' => 'Cayman Islands', 'KZ' => 'Kazakhstan', 'LA' => 'Laos',
            'LB' => 'Lebanon', 'LC' => 'Saint Lucia', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka',
            'LR' => 'Liberia', 'LS' => 'Lesotho', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
            'LV' => 'Latvia', 'LY' => 'Libya', 'MA' => 'Morocco', 'MC' => 'Monaco',
            'MD' => 'Moldova', 'ME' => 'Montenegro', 'MF' => 'Saint Martin', 'MG' => 'Madagascar',
            'MH' => 'Marshall Islands', 'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar',
            'MN' => 'Mongolia', 'MO' => 'Macao', 'MP' => 'Northern Mariana Islands', 'MQ' => 'Martinique',
            'MR' => 'Mauritania', 'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius',
            'MV' => 'Maldives', 'MW' => 'Malawi', 'MX' => 'Mexico', 'MY' => 'Malaysia',
            'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NC' => 'New Caledonia', 'NE' => 'Niger',
            'NF' => 'Norfolk Island', 'NG' => 'Nigeria', 'NI' => 'Nicaragua', 'NL' => 'Netherlands',
            'NO' => 'Norway', 'NP' => 'Nepal', 'NR' => 'Nauru', 'NU' => 'Niue',
            'NZ' => 'New Zealand', 'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru',
            'PF' => 'French Polynesia', 'PG' => 'Papua New Guinea', 'PH' => 'Philippines',
            'PK' => 'Pakistan', 'PL' => 'Poland', 'PM' => 'Saint Pierre and Miquelon',
            'PN' => 'Pitcairn', 'PR' => 'Puerto Rico', 'PS' => 'Palestine', 'PT' => 'Portugal',
            'PW' => 'Palau', 'PY' => 'Paraguay', 'QA' => 'Qatar', 'RE' => 'Réunion',
            'RO' => 'Romania', 'RS' => 'Serbia', 'RU' => 'Russia', 'RW' => 'Rwanda',
            'SA' => 'Saudi Arabia', 'SB' => 'Solomon Islands', 'SC' => 'Seychelles',
            'SD' => 'Sudan', 'SE' => 'Sweden', 'SG' => 'Singapore', 'SH' => 'Saint Helena',
            'SI' => 'Slovenia', 'SJ' => 'Svalbard', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone',
            'SM' => 'San Marino', 'SN' => 'Senegal', 'SO' => 'Somalia', 'SR' => 'Suriname',
            'SS' => 'South Sudan', 'ST' => 'São Tomé', 'SV' => 'El Salvador', 'SX' => 'Sint Maarten',
            'SY' => 'Syria', 'SZ' => 'Eswatini', 'TC' => 'Turks and Caicos', 'TD' => 'Chad',
            'TF' => 'French Southern Territories', 'TG' => 'Togo', 'TH' => 'Thailand',
            'TJ' => 'Tajikistan', 'TK' => 'Tokelau', 'TL' => 'Timor-Leste', 'TM' => 'Turkmenistan',
            'TN' => 'Tunisia', 'TO' => 'Tonga', 'TR' => 'Turkey', 'TT' => 'Trinidad and Tobago',
            'TV' => 'Tuvalu', 'TW' => 'Taiwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraine',
            'UG' => 'Uganda', 'UM' => 'US Minor Outlying Islands', 'US' => 'United States',
            'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VA' => 'Vatican City', 'VC' => 'Saint Vincent',
            'VE' => 'Venezuela', 'VG' => 'British Virgin Islands', 'VI' => 'US Virgin Islands',
            'VN' => 'Vietnam', 'VU' => 'Vanuatu', 'WF' => 'Wallis and Futuna', 'WS' => 'Samoa',
            'XK' => 'Kosovo', 'YE' => 'Yemen', 'YT' => 'Mayotte', 'ZA' => 'South Africa',
            'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
        ];
        return $countries[$code] ?? $code;
    }

    /**
     * Helper to call private formatAddressDisplay method
     */
    private function callFormatAddressDisplay(array $data): array
    {
        return $this->formatAddressDisplay($data);
    }

    /**
     * Helper to call getCountryName
     */
    private function callGetCountryName(string $code): string
    {
        return $this->getCountryName($code);
    }

    /* =======================================================
       Field Mapping Tests
       ======================================================= */

    /**
     * @test
     * Test all ISO 20022 fields are correctly mapped
     */
    public function testAllFieldsAreMapped(): void
    {
        $isoData = [
            'StrtNm' => 'Hauptstraße',
            'BldgNb' => '15',
            'PstCd' => '10115',
            'TwnNm' => 'Berlin',
            'Ctry' => 'DE',
            'AdtlAdrInf' => '3. Etage',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('Hauptstraße', $result['road'], 'StrtNm should map to road');
        $this->assertEquals('15', $result['houseNumber'], 'BldgNb should map to houseNumber');
        $this->assertEquals('10115', $result['postcode'], 'PstCd should map to postcode');
        $this->assertEquals('Berlin', $result['city'], 'TwnNm should map to city');
        $this->assertEquals('DE', $result['countryCode'], 'Ctry should map to countryCode');
        $this->assertEquals('Germany', $result['country'], 'Country name should be resolved');
        $this->assertEquals('3. Etage', $result['attention'], 'AdtlAdrInf should map to attention');
    }

    /**
     * @test
     * Test AdtlAdrInf (additional info) is mapped to attention field
     */
    public function testAdditionalInfoMapsToAttention(): void
    {
        $isoData = [
            'StrtNm' => 'Rue de la Paix',
            'BldgNb' => '10',
            'PstCd' => '75002',
            'TwnNm' => 'Paris',
            'Ctry' => 'FR',
            'AdtlAdrInf' => 'Apartment 4B',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('Apartment 4B', $result['attention']);
    }

    /**
     * @test
     * Test empty additional info is handled gracefully
     */
    public function testEmptyAdditionalInfoIsEmptyString(): void
    {
        $isoData = [
            'StrtNm' => 'Main St',
            'BldgNb' => '123',
            'PstCd' => '12345',
            'TwnNm' => 'New York',
            'Ctry' => 'US',
            'AdtlAdrInf' => '',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('', $result['attention']);
    }

    /**
     * @test
     * Test missing fields are handled gracefully
     */
    public function testMissingFieldsDefaultToEmptyString(): void
    {
        $isoData = [
            'StrtNm' => 'Main St',
            'Ctry' => 'US',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('Main St', $result['road']);
        $this->assertEquals('', $result['houseNumber']);
        $this->assertEquals('', $result['postcode']);
        $this->assertEquals('', $result['city']);
        $this->assertEquals('US', $result['countryCode']);
        $this->assertEquals('', $result['attention']);
    }

    /**
     * @test
     * Test country code is uppercased
     */
    public function testCountryCodeIsUppercased(): void
    {
        $isoData = [
            'StrtNm' => 'Street',
            'Ctry' => 'de',  // lowercase
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('DE', $result['countryCode']);
    }

    /**
     * @test
     * Test country code with whitespace is trimmed
     */
    public function testCountryCodeIsTrimmed(): void
    {
        $isoData = [
            'StrtNm' => 'Street',
            'Ctry' => '  DE  ',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('DE', $result['countryCode']);
    }

    /* =======================================================
       Country Name Resolution Tests
       ======================================================= */

    /**
     * @test
     * Test major countries are resolved correctly
     */
    public function testMajorCountriesAreResolved(): void
    {
        $tests = [
            ['DE', 'Germany'],
            ['FR', 'France'],
            ['US', 'United States'],
            ['GB', 'United Kingdom'],
            ['ES', 'Spain'],
            ['IT', 'Italy'],
            ['NL', 'Netherlands'],
            ['BE', 'Belgium'],
            ['CH', 'Switzerland'],
            ['AT', 'Austria'],
            ['JP', 'Japan'],
            ['CN', 'China'],
            ['CA', 'Canada'],
            ['AU', 'Australia'],
            ['BR', 'Brazil'],
        ];

        foreach ($tests as [$code, $expected]) {
            $this->assertEquals($expected, $this->callGetCountryName($code), "Country $code should resolve to $expected");
        }
    }

    /**
     * @test
     * Test unknown country code returns the code itself
     */
    public function testUnknownCountryCodeReturnsCode(): void
    {
        $this->assertEquals('XX', $this->callGetCountryName('XX'));
        $this->assertEquals('ZZ', $this->callGetCountryName('ZZ'));
    }

    /**
     * @test
     * Test empty country code returns empty string
     */
    public function testEmptyCountryCodeReturnsEmptyString(): void
    {
        $this->assertEquals('', $this->callGetCountryName(''));
    }

    /* =======================================================
       Complete Address Scenarios
       ======================================================= */

    /**
     * @test
     * Test German address with all fields
     */
    public function testGermanAddress(): void
    {
        $isoData = [
            'StrtNm' => 'Hauptstraße',
            'BldgNb' => '15',
            'PstCd' => '10115',
            'TwnNm' => 'Berlin',
            'Ctry' => 'DE',
            'AdtlAdrInf' => '3. Etage',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('Hauptstraße', $result['road']);
        $this->assertEquals('15', $result['houseNumber']);
        $this->assertEquals('10115', $result['postcode']);
        $this->assertEquals('Berlin', $result['city']);
        $this->assertEquals('DE', $result['countryCode']);
        $this->assertEquals('Germany', $result['country']);
        $this->assertEquals('3. Etage', $result['attention']);
    }

    /**
     * @test
     * Test US address
     */
    public function testUsAddress(): void
    {
        $isoData = [
            'StrtNm' => 'Main Street',
            'BldgNb' => '123',
            'PstCd' => '10001',
            'TwnNm' => 'New York',
            'Ctry' => 'US',
            'AdtlAdrInf' => 'Suite 200',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('Main Street', $result['road']);
        $this->assertEquals('123', $result['houseNumber']);
        $this->assertEquals('10001', $result['postcode']);
        $this->assertEquals('New York', $result['city']);
        $this->assertEquals('US', $result['countryCode']);
        $this->assertEquals('United States', $result['country']);
        $this->assertEquals('Suite 200', $result['attention']);
    }

    /**
     * @test
     * Test UK address
     */
    public function testUkAddress(): void
    {
        $isoData = [
            'StrtNm' => 'High Street',
            'BldgNb' => '42',
            'PstCd' => 'SW1A 1AA',
            'TwnNm' => 'London',
            'Ctry' => 'GB',
            'AdtlAdrInf' => 'Flat 3',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('High Street', $result['road']);
        $this->assertEquals('42', $result['houseNumber']);
        $this->assertEquals('SW1A 1AA', $result['postcode']);
        $this->assertEquals('London', $result['city']);
        $this->assertEquals('GB', $result['countryCode']);
        $this->assertEquals('United Kingdom', $result['country']);
        $this->assertEquals('Flat 3', $result['attention']);
    }

    /**
     * @test
     * Test French address
     */
    public function testFrenchAddress(): void
    {
        $isoData = [
            'StrtNm' => 'Rue de la Paix',
            'BldgNb' => '10',
            'PstCd' => '75002',
            'TwnNm' => 'Paris',
            'Ctry' => 'FR',
            'AdtlAdrInf' => 'Appartement 4B',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('Rue de la Paix', $result['road']);
        $this->assertEquals('10', $result['houseNumber']);
        $this->assertEquals('75002', $result['postcode']);
        $this->assertEquals('Paris', $result['city']);
        $this->assertEquals('FR', $result['countryCode']);
        $this->assertEquals('France', $result['country']);
        $this->assertEquals('Appartement 4B', $result['attention']);
    }

    /**
     * @test
     * Test Japanese address
     */
    public function testJapaneseAddress(): void
    {
        $isoData = [
            'StrtNm' => 'Sakura Dori',
            'BldgNb' => '1-2-3',
            'PstCd' => '150-0001',
            'TwnNm' => 'Shibuya',
            'Ctry' => 'JP',
            'AdtlAdrInf' => 'Building 5F',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('Sakura Dori', $result['road']);
        $this->assertEquals('1-2-3', $result['houseNumber']);
        $this->assertEquals('150-0001', $result['postcode']);
        $this->assertEquals('Shibuya', $result['city']);
        $this->assertEquals('JP', $result['countryCode']);
        $this->assertEquals('Japan', $result['country']);
        $this->assertEquals('Building 5F', $result['attention']);
    }

    /**
     * @test
     * Test address without additional info
     */
    public function testAddressWithoutAdditionalInfo(): void
    {
        $isoData = [
            'StrtNm' => 'Simple Street',
            'BldgNb' => '1',
            'PstCd' => '12345',
            'TwnNm' => 'Simpletown',
            'Ctry' => 'DE',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('Simple Street', $result['road']);
        $this->assertEquals('1', $result['houseNumber']);
        $this->assertEquals('12345', $result['postcode']);
        $this->assertEquals('Simpletown', $result['city']);
        $this->assertEquals('DE', $result['countryCode']);
        $this->assertEquals('Germany', $result['country']);
        $this->assertEquals('', $result['attention']);
    }

    /**
     * @test
     * Test whitespace trimming on all fields
     */
    public function testWhitespaceTrimming(): void
    {
        $isoData = [
            'StrtNm' => '  Street Name  ',
            'BldgNb' => '  123  ',
            'PstCd' => '  12345  ',
            'TwnNm' => '  City  ',
            'Ctry' => '  US  ',
            'AdtlAdrInf' => '  Floor 2  ',
        ];

        $result = $this->callFormatAddressDisplay($isoData);

        $this->assertEquals('Street Name', $result['road']);
        $this->assertEquals('123', $result['houseNumber']);
        $this->assertEquals('12345', $result['postcode']);
        $this->assertEquals('City', $result['city']);
        $this->assertEquals('US', $result['countryCode']);
        $this->assertEquals('Floor 2', $result['attention']);
    }
}
