<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * Generates the default Scenarios.xlsx with 200 credible entries.
 * Run: php scripts/generate_default_excel.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();

// ── Sheet 1: Scenarios ──
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Scenarios');

$headers = ['StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdtlAdrInf'];
foreach ($headers as $col => $h) {
    $sheet->setCellValueByColumnAndRow($col + 1, 1, $h);
}

// StrtNm, BldgNb, PstCd, TwnNm, Ctry, AdtlAdrInf
$scenarios = [
    // ══════════════════════════════════════════════════════════
    // WESTERN EUROPE (60)
    // ══════════════════════════════════════════════════════════
    // Switzerland
    ['Bahnhofstrasse', '1', '8001', 'Zürich', 'CH', ''],
    ['Rue du Rhône', '14', '1204', 'Genève', 'CH', ''],
    ['Paradeplatz', '8', '8001', 'Zürich', 'CH', ''],
    ['Rue du Marché', '2', '1204', 'Genève', 'CH', ''],
    ['Freiestrasse', '90', '4001', 'Basel', 'CH', 'Postfach 222'],
    ['Bahnhofplatz', '10', '3011', 'Bern', 'CH', 'Büro 421'],
    ['Quai du Mont-Blanc', '19', '1201', 'Genève', 'CH', 'Bâtiment Est'],
    ['Pilatusstrasse', '24', '6003', 'Luzern', 'CH', ''],
    // Germany
    ['Königsallee', '27', '40212', 'Düsseldorf', 'DE', ''],
    ['Kurfürstendamm', '188', '10707', 'Berlin', 'DE', '4. OG'],
    ['Maximilianstrasse', '10', '80539', 'München', 'DE', ''],
    ['Kantstrasse', '152', '10623', 'Berlin', 'DE', ''],
    ['Leopoldstrasse', '77', '80802', 'München', 'DE', ''],
    ['Friedrichstrasse', '43', '10117', 'Berlin', 'DE', 'Aufgang C'],
    ['Unter den Linden', '77', '10117', 'Berlin', 'DE', 'Hinterhaus'],
    ['Kölner Strasse', '1', '50667', 'Köln', 'DE', 'Gebäude B'],
    ['Mönckebergstrasse', '7', '20095', 'Hamburg', 'DE', ''],
    ['Zeil', '106', '60313', 'Frankfurt am Main', 'DE', ''],
    // France
    ['Champs-Élysées', '101', '75008', 'Paris', 'FR', ''],
    ['Boulevard Haussmann', '40', '75009', 'Paris', 'FR', 'Étage 3'],
    ['Rue de Rivoli', '226', '75001', 'Paris', 'FR', 'Escalier D'],
    ['Rue Royale', '25', '75008', 'Paris', 'FR', ''],
    ['Rue de la République', '45', '69002', 'Lyon', 'FR', ''],
    ['Promenade des Anglais', '37', '06000', 'Nice', 'FR', ''],
    ['Cours Mirabeau', '12', '13100', 'Aix-en-Provence', 'FR', ''],
    ['Rue Sainte-Catherine', '55', '33000', 'Bordeaux', 'FR', 'Apt 8'],
    // Belgium
    ['Avenue Louise', '480', '1050', 'Bruxelles', 'BE', 'Boîte 12'],
    ['Boulevard Anspach', '1', '1000', 'Bruxelles', 'BE', ''],
    ['Rue de la Loi', '200', '1040', 'Bruxelles', 'BE', ''],
    ['Place de la Bourse', '1', '1000', 'Bruxelles', 'BE', 'Étage 6'],
    ['Rue Neuve', '123', '1000', 'Bruxelles', 'BE', ''],
    ['Meir', '50', '2000', 'Antwerpen', 'BE', ''],
    // Netherlands
    ['Keizersgracht', '555', '1017 DR', 'Amsterdam', 'NL', ''],
    ['Leidsestraat', '97', '1017 NZ', 'Amsterdam', 'NL', 'Etage 3'],
    ['Damstraat', '1', '1012 JL', 'Amsterdam', 'NL', ''],
    ['Damrak', '70', '1012 LM', 'Amsterdam', 'NL', 'Verdieping 2'],
    ['Prinsengracht', '263', '1016 GV', 'Amsterdam', 'NL', 'Bovenwoning'],
    ['Coolsingel', '40', '3011 AD', 'Rotterdam', 'NL', ''],
    // Austria
    ['Ringstrasse', '12', '1010', 'Wien', 'AT', 'Top 3'],
    ['Kärntner Strasse', '51', '1010', 'Wien', 'AT', ''],
    ['Rotenturmstrasse', '29', '1010', 'Wien', 'AT', ''],
    ['Mariahilfer Strasse', '77', '1060', 'Wien', 'AT', 'Stiege 2, Tür 8'],
    ['Graben', '19', '1010', 'Wien', 'AT', ''],
    ['Spiegelgasse', '11', '1010', 'Wien', 'AT', '3. Stock'],
    ['Getreidegasse', '9', '5020', 'Salzburg', 'AT', ''],
    // Luxembourg
    ['Grand-Rue', '18', '1660', 'Luxembourg', 'LU', ''],
    ['Avenue de la Liberté', '22', '1930', 'Luxembourg', 'LU', ''],
    // ══════════════════════════════════════════════════════════
    // BRITISH ISLES (16)
    // ══════════════════════════════════════════════════════════
    ['Baker Street', '221B', 'NW1 6XE', 'London', 'GB', ''],
    ['Regent Street', '14', 'W1B 5SA', 'London', 'GB', ''],
    ['Carnaby Street', '3', 'W1F 9PB', 'London', 'GB', ''],
    ['Oxford Street', '354', 'W1C 1JG', 'London', 'GB', ''],
    ['Buchanan Street', '180', 'G1 2LW', 'Glasgow', 'GB', 'Floor 3'],
    ['George Street', '100', 'EH2 3ES', 'Edinburgh', 'GB', 'Suite 200'],
    ['St Mary Street', '25', 'CF10 1PL', 'Cardiff', 'GB', ''],
    ['Deansgate', '1', 'M3 1AZ', 'Manchester', 'GB', ''],
    ['New Street', '50', 'B2 4EG', 'Birmingham', 'GB', ''],
    ['Princes Street', '48', 'EH2 2YJ', 'Edinburgh', 'GB', ''],
    ['O\'Connell Street', '11', 'D01 T4X6', 'Dublin', 'IE', ''],
    ['Grafton Street', '78', 'D02 VR66', 'Dublin', 'IE', 'Unit 5'],
    ['Dame Street', '45', 'D02 KF82', 'Dublin', 'IE', ''],
    ['Patrick Street', '12', 'T12 XY45', 'Cork', 'IE', ''],
    ['Shop Street', '6', 'H91 E2C3', 'Galway', 'IE', ''],
    ['Sauchiehall Street', '200', 'G2 3EH', 'Glasgow', 'GB', ''],
    // ══════════════════════════════════════════════════════════
    // SOUTHERN EUROPE (24)
    // ══════════════════════════════════════════════════════════
    // Italy
    ['Via Roma', '15', '00184', 'Roma', 'IT', 'Scala B'],
    ['Piazza Navona', '45', '00186', 'Roma', 'IT', ''],
    ['Corso Buenos Aires', '33', '20124', 'Milano', 'IT', ''],
    ['Corso Vittorio Emanuele', '15', '20122', 'Milano', 'IT', 'Int. 7'],
    ['Via Condotti', '22', '00187', 'Roma', 'IT', 'Piano 2'],
    ['Piazza del Duomo', '1', '50122', 'Firenze', 'IT', 'Interno 4'],
    ['Via Toledo', '156', '80134', 'Napoli', 'IT', ''],
    ['Via Maqueda', '100', '90134', 'Palermo', 'IT', ''],
    // Spain
    ['Paseo de la Castellana', '200', '28046', 'Madrid', 'ES', 'Planta 5'],
    ['Gran Vía', '28', '28013', 'Madrid', 'ES', ''],
    ['Calle Serrano', '61', '28006', 'Madrid', 'ES', ''],
    ['Calle de Alcalá', '50', '28014', 'Madrid', 'ES', 'Piso 3'],
    ['Calle Mayor', '1', '28013', 'Madrid', 'ES', ''],
    ['Rambla de Catalunya', '38', '08007', 'Barcelona', 'ES', ''],
    ['Passeig de Gràcia', '92', '08008', 'Barcelona', 'ES', ''],
    ['Avenida de la Constitución', '20', '41001', 'Sevilla', 'ES', ''],
    // Portugal
    ['Rua Augusta', '274', '1100-053', 'Lisboa', 'PT', '3º Andar'],
    ['Rua Garrett', '120', '1200-205', 'Lisboa', 'PT', ''],
    ['Rua da Prata', '80', '1100-420', 'Lisboa', 'PT', '2º Esquerdo'],
    ['Avenida da Liberdade', '110', '1269-046', 'Lisboa', 'PT', 'Loja 3A'],
    ['Rua do Ouro', '250', '1100-065', 'Lisboa', 'PT', ''],
    ['Rua de Santa Catarina', '4', '4000-450', 'Porto', 'PT', ''],
    // Greece
    ['Ermou', '28', '105 63', 'Athens', 'GR', ''],
    ['Stadiou', '24', '105 64', 'Athens', 'GR', ''],
    // ══════════════════════════════════════════════════════════
    // NORDICS & BALTICS (20)
    // ══════════════════════════════════════════════════════════
    // Denmark
    ['Nørrebrogade', '45', '2200', 'København', 'DK', ''],
    ['Frederiksberggade', '24', '1459', 'København', 'DK', ''],
    ['Strøget', '23', '1160', 'København', 'DK', 'Sal 4'],
    ['Nytorv', '9', '1450', 'København', 'DK', ''],
    // Sweden
    ['Sveavägen', '44', '111 34', 'Stockholm', 'SE', ''],
    ['Kungsgatan', '30', '111 35', 'Stockholm', 'SE', 'Vån 4'],
    ['Drottninggatan', '53', '111 21', 'Stockholm', 'SE', 'Vån 2'],
    ['Storgatan', '29', '114 55', 'Stockholm', 'SE', ''],
    // Norway
    ['Karl Johans gate', '33', '0162', 'Oslo', 'NO', ''],
    ['Bogstadveien', '27', '0355', 'Oslo', 'NO', ''],
    ['Aker Brygge', '1', '0250', 'Oslo', 'NO', 'Bygg D'],
    // Finland
    ['Aleksanterinkatu', '17', '00100', 'Helsinki', 'FI', ''],
    ['Mannerheimintie', '5', '00100', 'Helsinki', 'FI', 'Kerros 2'],
    ['Esplanadi', '39', '00100', 'Helsinki', 'FI', ''],
    // Iceland
    ['Laugavegur', '10', '101', 'Reykjavík', 'IS', ''],
    ['Skólavörðustígur', '22', '101', 'Reykjavík', 'IS', ''],
    // Baltics
    ['Viru', '15', '10140', 'Tallinn', 'EE', ''],
    ['Brīvības iela', '54', 'LV-1011', 'Rīga', 'LV', ''],
    ['Gedimino prospektas', '9', 'LT-01103', 'Vilnius', 'LT', ''],
    ['Pilies gatvė', '26', 'LT-01123', 'Vilnius', 'LT', ''],
    // ══════════════════════════════════════════════════════════
    // CENTRAL & EASTERN EUROPE (20)
    // ══════════════════════════════════════════════════════════
    // Poland
    ['Marszałkowska', '89', '00-693', 'Warszawa', 'PL', 'Lok. 14'],
    ['Nowy Świat', '15', '00-029', 'Warszawa', 'PL', ''],
    ['Miodowa', '14', '00-246', 'Warszawa', 'PL', ''],
    ['Floriańska', '3', '31-019', 'Kraków', 'PL', ''],
    // Hungary
    ['Rákóczi út', '42', '1072', 'Budapest', 'HU', ''],
    ['Váci utca', '10', '1052', 'Budapest', 'HU', ''],
    ['Andrássy út', '60', '1062', 'Budapest', 'HU', '2. emelet'],
    ['Deák Ferenc utca', '15', '1052', 'Budapest', 'HU', ''],
    // Czechia
    ['Wenceslas Square', '56', '110 00', 'Praha', 'CZ', ''],
    ['Václavské náměstí', '1', '110 00', 'Praha', 'CZ', ''],
    ['Na Příkopě', '33', '110 00', 'Praha', 'CZ', ''],
    ['Národní', '38', '110 00', 'Praha', 'CZ', 'Zadní trakt'],
    // Romania
    ['Calea Victoriei', '155', '010073', 'Bucureşti', 'RO', ''],
    ['Bulevardul Magheru', '28', '010336', 'Bucureşti', 'RO', 'Etaj 3'],
    // Bulgaria
    ['Vitosha Boulevard', '18', '1000', 'Sofia', 'BG', ''],
    // Croatia
    ['Ilica', '1', '10000', 'Zagreb', 'HR', ''],
    // Slovakia
    ['Obchodná', '52', '811 06', 'Bratislava', 'SK', ''],
    // Slovenia
    ['Čopova ulica', '14', '1000', 'Ljubljana', 'SI', ''],
    // Serbia
    ['Knez Mihailova', '30', '11000', 'Beograd', 'RS', ''],
    // Russia
    ['Nevsky Prospekt', '28', '191186', 'Saint Petersburg', 'RU', 'Office 305'],
    // ══════════════════════════════════════════════════════════
    // TURKEY & MIDDLE EAST (16)
    // ══════════════════════════════════════════════════════════
    ['Istiklal Caddesi', '123', '34430', 'Istanbul', 'TR', 'Kat 2'],
    ['Atatürk Bulvarı', '191', '06680', 'Ankara', 'TR', ''],
    ['Bankalar Caddesi', '35', '34420', 'Istanbul', 'TR', ''],
    ['Bağdat Caddesi', '400', '34740', 'Istanbul', 'TR', ''],
    ['King Fahd Road', '100', '11564', 'Riyadh', 'SA', ''],
    ['Olaya Street', '55', '12241', 'Riyadh', 'SA', 'Office 301'],
    ['Sheikh Zayed Road', '1', '', 'Dubai', 'AE', 'Tower B, Floor 15'],
    ['Al Wasl Road', '45', '', 'Dubai', 'AE', 'Villa 12'],
    ['Hamra Street', '80', '1103', 'Beirut', 'LB', ''],
    ['King Abdullah II Street', '25', '11942', 'Amman', 'JO', ''],
    ['Rothschild Boulevard', '45', '6578401', 'Tel Aviv', 'IL', ''],
    ['Dizengoff Street', '120', '6433222', 'Tel Aviv', 'IL', 'Apt 8'],
    ['Al Corniche Street', '10', '', 'Doha', 'QA', 'Tower A'],
    ['Al Soor Street', '5', '', 'Sharjah', 'AE', ''],
    ['Sultan Qaboos Street', '15', '100', 'Muscat', 'OM', ''],
    ['Kuwait City Avenue', '30', '13001', 'Kuwait City', 'KW', ''],
    // ══════════════════════════════════════════════════════════
    // NORTH AMERICA (16)
    // ══════════════════════════════════════════════════════════
    ['Fifth Avenue', '350', '10118', 'New York', 'US', 'Suite 4100'],
    ['Broadway', '1', '10004', 'New York', 'US', 'Floor 25'],
    ['Wall Street', '23', '10005', 'New York', 'US', ''],
    ['Michigan Avenue', '875', '60611', 'Chicago', 'US', ''],
    ['Market Street', '1', '94105', 'San Francisco', 'US', 'Suite 300'],
    ['Sunset Boulevard', '8000', '90046', 'Los Angeles', 'US', ''],
    ['Pennsylvania Avenue', '1600', '20500', 'Washington', 'US', ''],
    ['Peachtree Street', '200', '30303', 'Atlanta', 'US', ''],
    ['King Street West', '100', 'M5X 1A9', 'Toronto', 'CA', ''],
    ['Rue Sainte-Catherine', '1000', 'H3B 1E7', 'Montréal', 'CA', ''],
    ['Robson Street', '800', 'V6Z 3B7', 'Vancouver', 'CA', ''],
    ['Bank Street', '150', 'K2P 1W1', 'Ottawa', 'CA', ''],
    ['Paseo de la Reforma', '222', '06600', 'Ciudad de México', 'MX', 'Piso 10'],
    ['Avenida Insurgentes Sur', '1602', '03940', 'Ciudad de México', 'MX', ''],
    ['Avenida Revolución', '50', '44100', 'Guadalajara', 'MX', ''],
    ['Boulevard Kukulcán', '12', '77500', 'Cancún', 'MX', ''],
    // ══════════════════════════════════════════════════════════
    // SOUTH AMERICA (12)
    // ══════════════════════════════════════════════════════════
    ['Avenida Paulista', '1578', '01310-200', 'São Paulo', 'BR', 'Andar 12'],
    ['Rua Oscar Freire', '379', '01426-001', 'São Paulo', 'BR', ''],
    ['Avenida Atlântica', '1702', '22021-001', 'Rio de Janeiro', 'BR', ''],
    ['Avenida 9 de Julio', '1200', 'C1073AAZ', 'Buenos Aires', 'AR', 'Piso 8'],
    ['Calle Florida', '165', 'C1005AAC', 'Buenos Aires', 'AR', ''],
    ['Avenida Providencia', '2309', '7510024', 'Santiago', 'CL', 'Oficina 401'],
    ['Calle Hatillo', '55', '15001', 'Lima', 'PE', ''],
    ['Avenida Arequipa', '2450', '15046', 'Lima', 'PE', ''],
    ['Carrera Séptima', '71', '110231', 'Bogotá', 'CO', ''],
    ['Avenida El Dorado', '68D', '111321', 'Bogotá', 'CO', ''],
    ['Avenida 18 de Julio', '1001', '11100', 'Montevideo', 'UY', ''],
    ['Paseo Colón', '275', '1063', 'Buenos Aires', 'AR', ''],
    // ══════════════════════════════════════════════════════════
    // ASIA-PACIFIC (24)
    // ══════════════════════════════════════════════════════════
    // Japan
    ['Ginza', '4-1', '104-0061', 'Tokyo', 'JP', ''],
    ['Omotesando', '5-10', '150-0001', 'Tokyo', 'JP', ''],
    ['Midosuji', '3-6', '541-0046', 'Osaka', 'JP', ''],
    // South Korea
    ['Gangnam-daero', '396', '06253', 'Seoul', 'KR', ''],
    ['Myeongdong-gil', '53', '04536', 'Seoul', 'KR', ''],
    // China
    ['Nanjing East Road', '300', '200001', 'Shanghai', 'CN', ''],
    ['Wangfujing Street', '138', '100006', 'Beijing', 'CN', ''],
    ['Canton Road', '3', '999077', 'Hong Kong', 'HK', 'Floor 5'],
    ['Queen\'s Road Central', '1', '999077', 'Hong Kong', 'HK', ''],
    // Singapore
    ['Orchard Road', '290', '238859', 'Singapore', 'SG', ''],
    ['Raffles Place', '1', '048616', 'Singapore', 'SG', 'Tower 2'],
    // Australia
    ['George Street', '385', '2000', 'Sydney', 'AU', ''],
    ['Collins Street', '120', '3000', 'Melbourne', 'AU', 'Level 10'],
    ['Queen Street', '200', '4000', 'Brisbane', 'AU', ''],
    ['St Georges Terrace', '108', '6000', 'Perth', 'AU', ''],
    // New Zealand
    ['Queen Street', '100', '1010', 'Auckland', 'NZ', ''],
    ['Lambton Quay', '50', '6011', 'Wellington', 'NZ', 'Level 4'],
    // India
    ['Connaught Place', '15', '110001', 'New Delhi', 'IN', ''],
    ['MG Road', '100', '560001', 'Bengaluru', 'IN', ''],
    ['Marine Drive', '50', '400020', 'Mumbai', 'IN', ''],
    // Thailand
    ['Sukhumvit Road', '259', '10110', 'Bangkok', 'TH', ''],
    ['Silom Road', '64', '10500', 'Bangkok', 'TH', 'Floor 8'],
    // Malaysia
    ['Jalan Bukit Bintang', '55', '55100', 'Kuala Lumpur', 'MY', ''],
    // Philippines
    ['Ayala Avenue', '6750', '1226', 'Makati', 'PH', ''],
    // ══════════════════════════════════════════════════════════
    // AFRICA (12)
    // ══════════════════════════════════════════════════════════
    ['Long Street', '34', '8001', 'Cape Town', 'ZA', ''],
    ['Jan Smuts Avenue', '173', '2196', 'Johannesburg', 'ZA', ''],
    ['Nelson Mandela Boulevard', '1', '8001', 'Cape Town', 'ZA', 'Floor 3'],
    ['Kenyatta Avenue', '10', '00100', 'Nairobi', 'KE', ''],
    ['Moi Avenue', '55', '80100', 'Mombasa', 'KE', ''],
    ['Independence Avenue', '22', '', 'Accra', 'GH', ''],
    ['Victoria Island Road', '5', '101241', 'Lagos', 'NG', ''],
    ['Habib Bourguiba Avenue', '40', '1000', 'Tunis', 'TN', ''],
    ['Mohammed V Boulevard', '112', '20250', 'Casablanca', 'MA', ''],
    ['Hassan II Avenue', '50', '10000', 'Rabat', 'MA', ''],
    ['Tahrir Street', '18', '11511', 'Cairo', 'EG', ''],
    ['Corniche El Nil', '1', '11221', 'Cairo', 'EG', 'Tower C'],
];

foreach ($scenarios as $rowIdx => $row) {
    foreach ($row as $col => $value) {
        $sheet->setCellValueByColumnAndRow($col + 1, $rowIdx + 2, $value);
    }
}

// Auto-size columns
foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ── Write ──
$outputPath = __DIR__ . '/../public/assets/Scenarios.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($outputPath);

echo "Generated $outputPath with " . count($scenarios) . " scenarios.\n";
