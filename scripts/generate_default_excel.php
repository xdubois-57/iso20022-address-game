<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * Generates the default Scenarios.xlsx with 50 credible entries and 15 facts.
 * Run: php scripts/generate_default_excel.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();

// ── Sheet 1: Scenarios ──
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Scenarios');

$headers = ['StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdtlAdrInf', 'Type_Goal'];
foreach ($headers as $col => $h) {
    $sheet->setCellValueByColumnAndRow($col + 1, 1, $h);
}

$scenarios = [
    // ── Structured (60) ──
    ['Bahnhofstrasse', '1', '8001', 'Zürich', 'CH', '', 'Structured'],
    ['Avenue Louise', '480', '1050', 'Bruxelles', 'BE', 'Boîte 12', 'Structured'],
    ['Königsallee', '27', '40212', 'Düsseldorf', 'DE', '', 'Structured'],
    ['Via Roma', '15', '00184', 'Roma', 'IT', 'Scala B', 'Structured'],
    ['Paseo de la Castellana', '200', '28046', 'Madrid', 'ES', 'Planta 5', 'Structured'],
    ['Keizersgracht', '555', '1017 DR', 'Amsterdam', 'NL', '', 'Structured'],
    ['Rue du Rhône', '14', '1204', 'Genève', 'CH', '', 'Structured'],
    ['Baker Street', '221B', 'NW1 6XE', 'London', 'GB', '', 'Structured'],
    ['Fifth Avenue', '350', '10118', 'New York', 'US', 'Suite 4100', 'Structured'],
    ['Champs-Élysées', '101', '75008', 'Paris', 'FR', '', 'Structured'],
    ['Ringstrasse', '12', '1010', 'Wien', 'AT', 'Top 3', 'Structured'],
    ['Nørrebrogade', '45', '2200', 'København', 'DK', '', 'Structured'],
    ['Rua Augusta', '274', '1100-053', 'Lisboa', 'PT', '3º Andar', 'Structured'],
    ['Sveavägen', '44', '111 34', 'Stockholm', 'SE', '', 'Structured'],
    ['Aleksanterinkatu', '17', '00100', 'Helsinki', 'FI', '', 'Structured'],
    ['Marszałkowska', '89', '00-693', 'Warszawa', 'PL', 'Lok. 14', 'Structured'],
    ['Rákóczi út', '42', '1072', 'Budapest', 'HU', '', 'Structured'],
    ['Wenceslas Square', '56', '110 00', 'Praha', 'CZ', '', 'Structured'],
    ['O\'Connell Street', '11', 'D01 T4X6', 'Dublin', 'IE', '', 'Structured'],
    ['Ermou', '28', '105 63', 'Athens', 'GR', '', 'Structured'],
    ['Istiklal Caddesi', '123', '34430', 'Istanbul', 'TR', 'Kat 2', 'Structured'],
    ['Karl Johans gate', '33', '0162', 'Oslo', 'NO', '', 'Structured'],
    ['Grafton Street', '78', 'D02 VR66', 'Dublin', 'IE', 'Unit 5', 'Structured'],
    ['Maximilianstrasse', '10', '80539', 'München', 'DE', '', 'Structured'],
    ['Václavské náměstí', '1', '110 00', 'Praha', 'CZ', '', 'Structured'],
    ['Boulevard Anspach', '1', '1000', 'Bruxelles', 'BE', '', 'Structured'],
    ['Kurfürstendamm', '188', '10707', 'Berlin', 'DE', '4. OG', 'Structured'],
    ['Gran Vía', '28', '28013', 'Madrid', 'ES', '', 'Structured'],
    ['Paradeplatz', '8', '8001', 'Zürich', 'CH', '', 'Structured'],
    ['Regent Street', '14', 'W1B 5SA', 'London', 'GB', '', 'Structured'],
    ['Rue de la Loi', '200', '1040', 'Bruxelles', 'BE', '', 'Structured'],
    ['Piazza Navona', '45', '00186', 'Roma', 'IT', '', 'Structured'],
    ['Leidsestraat', '97', '1017 NZ', 'Amsterdam', 'NL', 'Etage 3', 'Structured'],
    ['Calle Serrano', '61', '28006', 'Madrid', 'ES', '', 'Structured'],
    ['Kärntner Strasse', '51', '1010', 'Wien', 'AT', '', 'Structured'],
    ['Frederiksberggade', '24', '1459', 'København', 'DK', '', 'Structured'],
    ['Rua Garrett', '120', '1200-205', 'Lisboa', 'PT', '', 'Structured'],
    ['Kungsgatan', '30', '111 35', 'Stockholm', 'SE', 'Vån 4', 'Structured'],
    ['Mannerheimintie', '5', '00100', 'Helsinki', 'FI', 'Kerros 2', 'Structured'],
    ['Nowy Świat', '15', '00-029', 'Warszawa', 'PL', '', 'Structured'],
    ['Váci utca', '10', '1052', 'Budapest', 'HU', '', 'Structured'],
    ['Na Příkopě', '33', '110 00', 'Praha', 'CZ', '', 'Structured'],
    ['Dame Street', '45', 'D02 KF82', 'Dublin', 'IE', '', 'Structured'],
    ['Stadiou', '24', '105 64', 'Athens', 'GR', '', 'Structured'],
    ['Atatürk Bulvarı', '191', '06680', 'Ankara', 'TR', '', 'Structured'],
    ['Bogstadveien', '27', '0355', 'Oslo', 'NO', '', 'Structured'],
    ['Broadway', '1', '10004', 'New York', 'US', 'Floor 25', 'Structured'],
    ['Kantstrasse', '152', '10623', 'Berlin', 'DE', '', 'Structured'],
    ['Rue du Marché', '2', '1204', 'Genève', 'CH', '', 'Structured'],
    ['Corso Buenos Aires', '33', '20124', 'Milano', 'IT', '', 'Structured'],
    ['Rambla de Catalunya', '38', '08007', 'Barcelona', 'ES', '', 'Structured'],
    ['Damstraat', '1', '1012 JL', 'Amsterdam', 'NL', '', 'Structured'],
    ['Carnaby Street', '3', 'W1F 9PB', 'London', 'GB', '', 'Structured'],
    ['Boulevard Haussmann', '40', '75009', 'Paris', 'FR', 'Étage 3', 'Structured'],
    ['Rotenturmstrasse', '29', '1010', 'Wien', 'AT', '', 'Structured'],
    ['Bredgade', '76', '1260', 'København', 'DK', '', 'Structured'],
    ['Chiado', '76', '1200-109', 'Lisboa', 'PT', '', 'Structured'],
    ['Birger Jarlsgatan', '6', '114 34', 'Stockholm', 'SE', '', 'Structured'],
    ['Rue Neuve', '123', '1000', 'Bruxelles', 'BE', '', 'Structured'],
    ['Leopoldstrasse', '77', '80802', 'München', 'DE', '', 'Structured'],
    // ── Hybrid (40) ──
    ['Friedrichstrasse', '43', '10117', 'Berlin', 'DE', 'Aufgang C', 'Hybrid'],
    ['Rue de Rivoli', '226', '75001', 'Paris', 'FR', 'Escalier D', 'Hybrid'],
    ['Corso Vittorio Emanuele', '15', '20122', 'Milano', 'IT', 'Int. 7', 'Hybrid'],
    ['Calle de Alcalá', '50', '28014', 'Madrid', 'ES', 'Piso 3, Puerta B', 'Hybrid'],
    ['Damrak', '70', '1012 LM', 'Amsterdam', 'NL', 'Verdieping 2', 'Hybrid'],
    ['Buchanan Street', '180', 'G1 2LW', 'Glasgow', 'GB', 'Floor 3', 'Hybrid'],
    ['Mariahilfer Strasse', '77', '1060', 'Wien', 'AT', 'Stiege 2, Tür 8', 'Hybrid'],
    ['Strøget', '23', '1160', 'København', 'DK', 'Sal 4', 'Hybrid'],
    ['Rua da Prata', '80', '1100-420', 'Lisboa', 'PT', '2º Esquerdo', 'Hybrid'],
    ['Drottninggatan', '53', '111 21', 'Stockholm', 'SE', 'Vån 2', 'Hybrid'],
    ['Nevsky Prospekt', '28', '191186', 'Saint Petersburg', 'RU', 'Office 305', 'Hybrid'],
    ['Andrássy út', '60', '1062', 'Budapest', 'HU', '2. emelet', 'Hybrid'],
    ['Graben', '19', '1010', 'Wien', 'AT', '', 'Hybrid'],
    ['Laugavegur', '10', '101', 'Reykjavík', 'IS', '', 'Hybrid'],
    ['Freiestrasse', '90', '4001', 'Basel', 'CH', 'Postfach 222', 'Hybrid'],
    ['Avenida da Liberdade', '110', '1269-046', 'Lisboa', 'PT', 'Loja 3A', 'Hybrid'],
    ['Unter den Linden', '77', '10117', 'Berlin', 'DE', 'Hinterhaus', 'Hybrid'],
    ['Place de la Bourse', '1', '1000', 'Bruxelles', 'BE', 'Étage 6', 'Hybrid'],
    ['Oxford Street', '354', 'W1C 1JG', 'London', 'GB', 'Rear Entrance', 'Hybrid'],
    ['Piazza del Duomo', '1', '50122', 'Firenze', 'IT', 'Interno 4', 'Hybrid'],
    ['Rue Royale', '25', '75008', 'Paris', 'FR', 'Porte Gauche', 'Hybrid'],
    ['Spiegelgasse', '11', '1010', 'Wien', 'AT', '3. Stock, Tür 12', 'Hybrid'],
    ['Calle Mayor', '1', '28013', 'Madrid', 'ES', 'Escalera A, 4º Dcha', 'Hybrid'],
    ['Via Condotti', '22', '00187', 'Roma', 'IT', 'Piano 2', 'Hybrid'],
    ['Prinsengracht', '263', '1016 GV', 'Amsterdam', 'NL', 'Bovenwoning', 'Hybrid'],
    ['George Street', '100', 'EH2 3ES', 'Edinburgh', 'GB', 'Suite 200', 'Hybrid'],
    ['Kölner Strasse', '1', '50667', 'Köln', 'DE', 'Gebäude B, Etage 5', 'Hybrid'],
    ['Nytorv', '9', '1450', 'København', 'DK', '1. sal tv.', 'Hybrid'],
    ['Rua do Ouro', '250', '1100-065', 'Lisboa', 'PT', 'R/C Direito', 'Hybrid'],
    ['Storgatan', '29', '114 55', 'Stockholm', 'SE', 'Trappuppgång C', 'Hybrid'],
    ['Deák Ferenc utca', '15', '1052', 'Budapest', 'HU', 'III. emelet, Ajtó 2', 'Hybrid'],
    ['Národní', '38', '110 00', 'Praha', 'CZ', 'Zadní trakt', 'Hybrid'],
    ['Miodowa', '14', '00-246', 'Warszawa', 'PL', 'Klatka B, Lok. 9', 'Hybrid'],
    ['Solonos', '60', '106 80', 'Athens', 'GR', 'Orofos 3', 'Hybrid'],
    ['Aker Brygge', '1', '0250', 'Oslo', 'NO', 'Bygg D, Etasje 4', 'Hybrid'],
    ['Bankalar Caddesi', '35', '34420', 'Istanbul', 'TR', 'Kat 6, No 601', 'Hybrid'],
    ['Esplanadi', '39', '00100', 'Helsinki', 'FI', 'Porras A, 2. krs', 'Hybrid'],
    ['Bahnhofplatz', '10', '3011', 'Bern', 'CH', 'Büro 421', 'Hybrid'],
    ['Wall Street', '23', '10005', 'New York', 'US', 'Floor 18, Unit C', 'Hybrid'],
    ['Quai du Mont-Blanc', '19', '1201', 'Genève', 'CH', 'Bâtiment Est', 'Hybrid'],
];

foreach ($scenarios as $rowIdx => $row) {
    foreach ($row as $col => $value) {
        $sheet->setCellValueByColumnAndRow($col + 1, $rowIdx + 2, $value);
    }
}

// Auto-size columns
foreach (range('A', 'G') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ── Sheet 2: Facts ──
$factsSheet = $spreadsheet->createSheet();
$factsSheet->setTitle('Facts');
$factsSheet->setCellValue('A1', 'Fact');

$facts = [
    'ISO 20022 is used by over 200 market infrastructures across 70+ countries worldwide.',
    'The SWIFT network processes an average of 44.8 million messages per day.',
    'Structured addresses reduce payment repair rates by up to 40%.',
    'ISO 20022 supports 10 address components, replacing the legacy 4-line free-text format.',
    'The <TwnNm> and <Ctry> fields are the only mandatory address elements in ISO 20022.',
    'Country codes in ISO 20022 must follow the ISO 3166-1 alpha-2 standard (2-letter codes).',
    'Each <AdrLine> element in hybrid mode is limited to 70 characters maximum.',
    'SWIFT\'s CBPR+ guidelines require structured addresses for cross-border payments from 2026.',
    'The European Payments Council mandates ISO 20022 for all SEPA transactions.',
    'ISO 20022 was first published in 2004 and has been adopted globally over two decades.',
    'Hybrid addressing allows mixing structured fields with free-text address lines.',
    'The <BldgNb> field can contain letters and numbers (e.g., "221B").',
    'ISO 20022 address data improves sanctions screening accuracy by 25%.',
    'The Fedwire Funds Service migrated to ISO 20022 in March 2025.',
    'Over 10,000 financial institutions worldwide exchange ISO 20022 messages daily.',
    'The Bank of England adopted ISO 20022 for CHAPS in June 2023.',
    'Structured addresses enable straight-through processing (STP) without manual intervention.',
    'In ISO 20022, <PstCd> (Postal Code) is optional but strongly recommended for accuracy.',
    'The TARGET2 system in the Eurozone completed its migration to ISO 20022 in March 2023.',
    'ISO 20022 messages use XML syntax and can represent over 400 different business processes.',
];

foreach ($facts as $idx => $fact) {
    $factsSheet->setCellValue('A' . ($idx + 2), $fact);
}
$factsSheet->getColumnDimension('A')->setAutoSize(true);

// ── Write ──
$outputPath = __DIR__ . '/../public/assets/Scenarios.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($outputPath);

echo "Generated $outputPath with " . count($scenarios) . " scenarios and " . count($facts) . " facts.\n";
