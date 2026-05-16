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
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Controllers;

use App\Models\Database;
use App\Models\ScenarioModel;
use App\Models\GameCounterModel;
use App\Controllers\AdminController;
use Snipe\BanBuilder\CensorWords;

class GameController
{
    private ScenarioModel $scenarioModel;

    public function __construct()
    {
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $this->scenarioModel = new ScenarioModel($pdo);
    }

    /**
     * POST /api/game/scenario — Load a random scenario for the player.
     */
    public function getScenario(): void
    {
        $input = $this->getJsonInput();
        $excludeIds = $input['exclude_ids'] ?? [];

        $scenario = $this->scenarioModel->getRandom($excludeIds);
        if (!$scenario) {
            $this->jsonResponse(['error' => 'No scenarios available. Ask an admin to upload scenarios.'], 404);
            return;
        }

        $this->jsonResponse([
            'scenario' => [
                'id' => $scenario['id'],
                'chips' => $this->generateChips($scenario['json_data']),
                'slots_structured' => $this->getSlots('Structured'),
                'slots_hybrid' => $this->getSlots('Hybrid'),
                'address_display' => $this->formatAddressDisplay($scenario['json_data']),
            ],
        ]);
    }

    /**
     * POST /api/game/validate — Validate the user's chip-to-slot mapping.
     */
    public function validate(): void
    {
        $input = $this->getJsonInput();
        $scenarioId = (int) ($input['scenario_id'] ?? 0);
        $mapping = $input['mapping'] ?? [];
        $goalType = $input['goal_type'] ?? null;

        if ($scenarioId <= 0 || empty($mapping)) {
            $this->jsonResponse(['error' => 'Missing scenario_id or mapping'], 400);
            return;
        }

        if (!in_array($goalType, ['Structured', 'Hybrid'], true)) {
            $this->jsonResponse(['error' => 'Invalid goal_type'], 400);
            return;
        }

        $scenario = $this->scenarioModel->getById($scenarioId);
        if (!$scenario) {
            $this->jsonResponse(['error' => 'Scenario not found'], 404);
            return;
        }

        $result = $this->scenarioModel->validateAnswer($scenario, $mapping, $goalType);
        $this->jsonResponse($result);
    }

    /**
     * POST /api/game/deadline — Get the unstructured address deadline (public, no auth).
     */
    private const DEFAULT_DEADLINE = '2026-11-14T18:00';

    public function getDeadline(): void
    {
        $deadline = AdminController::fetchDeadlineStatic() ?? self::DEFAULT_DEADLINE;
        $this->jsonResponse(['deadline' => $deadline]);
    }

    /**
     * POST /api/game/facts — Get all "Did You Know" facts (public, no auth).
     */
    public function getFacts(): void
    {
        $this->jsonResponse(['facts' => AdminController::fetchFactsStatic()]);
    }

    /**
     * POST /api/game/check-name — Validate player name for profanity.
     */
    public function checkName(): void
    {
        $input = $this->getJsonInput();
        $name = trim($input['name'] ?? '');

        if ($name === '' || mb_strlen($name) > 50) {
            $this->jsonResponse(['error' => 'Name must be 1-50 characters'], 400);
            return;
        }

        $censor = new CensorWords();
        $censor->setDictionary(['en-us', 'en-uk', 'fr']);
        $result = $censor->censorString($name, true);

        if (!empty($result['matched'])) {
            $this->jsonResponse([
                'allowed' => false,
                'message' => 'Please choose a different name — offensive language is not allowed.',
            ]);
            return;
        }

        $this->jsonResponse(['allowed' => true]);
    }

    /**
     * POST /api/game/complete — Track game completion (increment counter).
     * Called when a game finishes, regardless of Hall of Fame submission.
     */
    public function complete(): void
    {
        $db = Database::getInstance();
        $counter = new GameCounterModel($db->getPdo());
        $counter->increment();

        $this->jsonResponse(['success' => true]);
    }

    /**
     * Generate draggable chips from scenario data.
     * Chips are shuffled so users cannot rely on order.
     */
    private function generateChips(array $data): array
    {
        $chips = [];
        $fieldLabels = [
            'StrtNm' => 'Street Name',
            'BldgNb' => 'Building Number',
            'PstCd' => 'Postal Code',
            'TwnNm' => 'Town Name',
            'Ctry' => 'Country',
            'AdtlAdrInf' => 'Additional Info',
        ];

        foreach ($data as $field => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $chips[] = [
                'id' => 'chip_' . $field . '_' . bin2hex(random_bytes(4)),
                'field' => $field,
                'value' => $value,
                'label' => $fieldLabels[$field] ?? $field,
            ];
        }

        shuffle($chips);
        return $chips;
    }

    /**
     * Get the target slots for the given goal type.
     */
    private function getSlots(string $goalType): array
    {
        if ($goalType === 'Structured') {
            return [
                ['id' => 'StrtNm', 'label' => 'Street Name', 'tag' => '<StrtNm>', 'mandatory' => false],
                ['id' => 'BldgNb', 'label' => 'Building Number', 'tag' => '<BldgNb>', 'mandatory' => false],
                ['id' => 'PstCd', 'label' => 'Postal Code', 'tag' => '<PstCd>', 'mandatory' => false],
                ['id' => 'TwnNm', 'label' => 'Town Name', 'tag' => '<TwnNm>', 'mandatory' => true],
                ['id' => 'Ctry', 'label' => 'Country', 'tag' => '<Ctry>', 'mandatory' => true],
            ];
        }

        // Hybrid mode
        return [
            ['id' => 'TwnNm', 'label' => 'Town Name', 'tag' => '<TwnNm>', 'mandatory' => true],
            ['id' => 'Ctry', 'label' => 'Country', 'tag' => '<Ctry>', 'mandatory' => true],
            ['id' => 'AdrLine1', 'label' => 'Address Line 1 (max 70 chars)', 'tag' => '<AdrLine>', 'mandatory' => false],
            ['id' => 'AdrLine2', 'label' => 'Address Line 2 (max 70 chars)', 'tag' => '<AdrLine>', 'mandatory' => false],
        ];
    }

    /**
     * Build address object for frontend formatter.
     * Returns structured address components that @fragaria/address-formatter
     * can format according to country-specific rules.
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
     * Get full country name from ISO 3166-1 alpha-2 code.
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

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    private function jsonResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }
}
