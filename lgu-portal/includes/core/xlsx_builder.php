<?php
// ══════════════════════════════════════════════════════════════════════════════
//  EXCEL (pure PHP / ZipArchive) — shared by generate_report.php and any other
//  page that needs to stream an .xlsx download. Requires the "zip" extension.
// ══════════════════════════════════════════════════════════════════════════════
function numToCol(int $n): string {
    $s = '';
    while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
    return $s;
}

function xmlSafe(string $v): string {
    return preg_replace('/[^\x09\x0A\x0D\x20-\xD7FF\xE000-\xFFFD]/u', '', $v);
}

function buildStylesXml(): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="11">
    <font><sz val="11"/><name val="Arial"/></font>
    <font><sz val="13"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
    <font><sz val="11"/><name val="Arial"/></font>
    <font><sz val="22"/><b/><color rgb="FF1E3A5F"/><name val="Arial"/></font>
    <font><sz val="10"/><color rgb="FF6B7B93"/><name val="Arial"/></font>
    <font><sz val="11"/><b/><color rgb="FF1E3A5F"/><name val="Arial"/></font>
    <font><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/><b/></font>
    <font><sz val="28"/><b/><color rgb="FF1E3A5F"/><name val="Arial"/></font>
    <font><sz val="10"/><color rgb="FF6B7B93"/><name val="Arial"/></font>
    <font><sz val="11"/><b/><name val="Arial"/></font>
    <font><sz val="11"/><color rgb="FF374151"/><name val="Arial"/></font>
  </fonts>
  <fills count="18">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0F4FA"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEEF9EE"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFEF3E2"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFDECEA"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FE"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF22C55E"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF59E0B"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEF4444"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF6366F1"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF3B82F6"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF97316"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFAFBFF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE4ECF7"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF2D5FA3"/></patternFill></fill>
  </fills>
  <borders count="5">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFD1DAE8"/></left>
      <right style="thin"><color rgb="FFD1DAE8"/></right>
      <top style="thin"><color rgb="FFD1DAE8"/></top>
      <bottom style="thin"><color rgb="FFD1DAE8"/></bottom>
      <diagonal/>
    </border>
    <border>
      <left style="medium"><color rgb="FF1E3A5F"/></left>
      <right style="medium"><color rgb="FF1E3A5F"/></right>
      <top style="medium"><color rgb="FF1E3A5F"/></top>
      <bottom style="medium"><color rgb="FF1E3A5F"/></bottom>
      <diagonal/>
    </border>
    <border><bottom style="medium"><color rgb="FF1E3A5F"/></bottom><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFB0C4DE"/></left>
      <right style="thin"><color rgb="FFB0C4DE"/></right>
      <top style="thin"><color rgb="FFB0C4DE"/></top>
      <bottom style="medium"><color rgb="FF2D5FA3"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="28">
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="4" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="3" fillId="15" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="4" fillId="15" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="5" fillId="0" borderId="3" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="8" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="9" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="10" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="11" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="10" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="13" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="12" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="7" fillId="16" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="8" fillId="16" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="15" borderId="0" xfId="0" applyFill="1"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="17" borderId="4" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="4" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="9" fillId="15" borderId="2" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="9" fillId="15" borderId="2" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0" fontId="10" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="15" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>
  </cellXfs>
</styleSheet>
XML;
}

function statusStyleXls(string $s): int {
    return match(strtolower($s)) {
        'approved','completed'                                              => 6,
        'pending','scheduled','pending acceptance','accepted'              => 7,
        'rejected','delayed','cancelled'                                   => 8,
        'in progress','pending completion','pending admin approval',
        'pending approval'                                                 => 9,
        default                                                            => 7,
    };
}
function priorityStyleXls(string $p): int {
    return match(strtolower($p)) {
        'critical' => 10,
        'high'     => 11,
        'medium'   => 7,
        'low'      => 12,
        default    => 12,
    };
}

function makeSharedStrings(array &$pool, string $v): int {
    if (!array_key_exists($v, $pool)) $pool[$v] = count($pool);
    return $pool[$v];
}

function sc(string $ref, int $si, int $style): string {
    return "<c r=\"{$ref}\" t=\"s\" s=\"{$style}\"><v>{$si}</v></c>";
}
function nc(string $ref, $val, int $style): string {
    return "<c r=\"{$ref}\" s=\"{$style}\"><v>{$val}</v></c>";
}

function buildSheetXml(array $def, array &$pool): string {
    $headers   = $def['headers'];
    $rows      = $def['rows'];
    $colCount  = count($headers);
    $lastCol   = numToCol($colCount);
    $rowXmls   = [];
    $mergeRefs = [];
    $rn = 1;

    $ti = makeSharedStrings($pool, $def['title']);
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"42\" customHeight=\"1\">" . sc("A{$rn}", $ti, 3) . "</row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}"; $rn++;

    $org = makeSharedStrings($pool, "LGU \u{2013} CIMM  |  Community Infrastructure Monitoring & Management");
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"18\" customHeight=\"1\">" . sc("A{$rn}", $org, 4) . "</row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}"; $rn++;

    $meta2 = makeSharedStrings($pool, "Period: {$def['meta_period']}   |   Generated by: {$def['meta_by']}   |   {$def['meta_date']}");
    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"16\" customHeight=\"1\">" . sc("A{$rn}", $meta2, 4) . "</row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}"; $rn++;

    $rowXmls[]   = "<row r=\"{$rn}\" ht=\"5\" customHeight=\"1\"><c r=\"A{$rn}\" s=\"17\"/></row>";
    $mergeRefs[] = "A{$rn}:{$lastCol}{$rn}"; $rn++;

    $rowXmls[] = "<row r=\"{$rn}\" ht=\"6\" customHeight=\"1\"><c r=\"A{$rn}\" s=\"18\"/></row>"; $rn++;

    $headerRow = $rn;
    $hCells = '';
    foreach ($headers as $ci => $h) {
        $col  = numToCol($ci + 1);
        $hi   = makeSharedStrings($pool, strtoupper($h));
        $hCells .= sc("{$col}{$rn}", $hi, ($def['centerCols'][$ci] ?? false) ? 1 : 20);
    }
    $rowXmls[] = "<row r=\"{$rn}\" ht=\"22\" customHeight=\"1\">{$hCells}</row>"; $rn++;

    $dataCount = count($rows);
    foreach ($rows as $ri => $rowData) {
        $isEven  = $ri % 2 === 1;
        $baseS   = $isEven ? 2 : 0;
        $ctrBase = $isEven ? 22 : 21;
        $cells   = '';
        foreach (array_values($rowData) as $ci => $val) {
            $col    = numToCol($ci + 1);
            $ref    = "{$col}{$rn}";
            $center = $def['centerCols'][$ci] ?? false;
            $badge  = $def['badgeCols'][$ci] ?? null;
            if ($badge === 'status') {
                $cells .= sc($ref, makeSharedStrings($pool, (string)$val), statusStyleXls((string)$val));
            } elseif ($badge === 'priority') {
                $cells .= sc($ref, makeSharedStrings($pool, (string)$val), priorityStyleXls((string)$val));
            } elseif (is_numeric($val) && ($def['numericCols'][$ci] ?? false)) {
                $cells .= nc($ref, $val, $center ? $ctrBase : $baseS);
            } else {
                $cells .= sc($ref, makeSharedStrings($pool, (string)$val), $center ? $ctrBase : $baseS);
            }
        }
        $isLast = ($ri === $dataCount - 1) && ($def['totalRow'] ?? false);
        if ($isLast) {
            $cells = '';
            foreach (array_values($rowData) as $ci => $val) {
                $col = numToCol($ci + 1); $ref = "{$col}{$rn}";
                $cells .= sc($ref, makeSharedStrings($pool, (string)$val), $ci === 0 ? 23 : 24);
            }
        }
        $rowXmls[] = "<row r=\"{$rn}\" ht=\"18\" customHeight=\"1\">{$cells}</row>"; $rn++;
    }

    if ($dataCount === 0) {
        $ei = makeSharedStrings($pool, 'No records found for the selected date range.');
        $rowXmls[] = "<row r=\"{$rn}\" ht=\"24\" customHeight=\"1\">" . sc("A{$rn}", $ei, 4) . "</row>"; $rn++;
    }

    $colWidths = '';
    foreach ($headers as $ci => $h) {
        $col = $ci + 1;
        $w   = $def['colWidths'][$ci] ?? min(max(mb_strlen($h) * 1.5 + 6, 12), 60);
        $colWidths .= "<col min=\"{$col}\" max=\"{$col}\" width=\"{$w}\" customWidth=\"1\"/>";
    }

    $freezeRow = $headerRow + 1;
    $freezeXml = "<sheetViews><sheetView workbookViewId=\"0\" tabSelected=\"1\">"
               . "<pane ySplit=\"{$headerRow}\" topLeftCell=\"A{$freezeRow}\" activePane=\"bottomLeft\" state=\"frozen\"/>"
               . "<selection pane=\"bottomLeft\" activeCell=\"A{$freezeRow}\"/>"
               . "</sheetView></sheetViews>";

    $mergeCellXml = implode('', array_map(fn($r) => "<mergeCell ref=\"{$r}\"/>", $mergeRefs));
    $merges = "<mergeCells count=\"" . count($mergeRefs) . "\">{$mergeCellXml}</mergeCells>";

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . $freezeXml
         . '<sheetFormatPr defaultRowHeight="18"/>'
         . "<cols>{$colWidths}</cols>"
         . '<sheetData>' . implode('', $rowXmls) . '</sheetData>'
         . $merges
         . '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
         . '<pageSetup orientation="landscape" fitToPage="1" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
         . '<headerFooter>'
         . '<oddHeader>&amp;L&amp;B&amp;14 CIMM Portal&amp;R&amp;8Generated: ' . date('M d, Y h:i A') . '</oddHeader>'
         . '<oddFooter>&amp;LConfidential — Internal Use Only&amp;CPage &amp;P of &amp;N&amp;R' . htmlspecialchars($def['title'], ENT_XML1) . '</oddFooter>'
         . '</headerFooter>'
         . '</worksheet>';
}

function buildXLSX(array $sheetDefs, string $reportTitle): string {
    $pool = []; $sheetXmls = []; $sheetList = ''; $sheetRels = ''; $overrides = '';
    foreach ($sheetDefs as $si => $def) {
        $num  = $si + 1; $rId = "rId{$num}"; $name = htmlspecialchars($def['name'], ENT_XML1);
        $sheetList .= "<sheet name=\"{$name}\" sheetId=\"{$num}\" r:id=\"{$rId}\"/>";
        $sheetRels .= "<Relationship Id=\"{$rId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$num}.xml\"/>";
        $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$num}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $sheetXmls[$num] = buildSheetXml($def, $pool);
    }
    $total = count($sheetDefs); $rIdSS = 'rId' . ($total + 1); $rIdST = 'rId' . ($total + 2);
    $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
           . ' count="' . count($pool) . '" uniqueCount="' . count($pool) . '">';
    foreach (array_keys($pool) as $v) {
        $ssXml .= '<si><t xml:space="preserve">' . htmlspecialchars(xmlSafe($v), ENT_XML1, 'UTF-8') . '</t></si>';
    }
    $ssXml .= '</sst>';
    $tmp = tempnam(sys_get_temp_dir(), 'cimm_') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $overrides . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<workbookPr date1904="0"/><sheets>' . $sheetList . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $sheetRels
        . "<Relationship Id=\"{$rIdSS}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings\" Target=\"sharedStrings.xml\"/>"
        . "<Relationship Id=\"{$rIdST}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>"
        . '</Relationships>');
    foreach ($sheetXmls as $num => $xml) { $zip->addFromString("xl/worksheets/sheet{$num}.xml", $xml); }
    $zip->addFromString('xl/sharedStrings.xml', $ssXml);
    $zip->addFromString('xl/styles.xml', buildStylesXml());
    $zip->close();
    return $tmp;
}
