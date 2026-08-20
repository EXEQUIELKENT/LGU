<?php
/**
 * docx_lib.php
 * ─────────────────────────────────────────────────────────────────
 * Minimal, dependency-free Word (.docx) document generator.
 *
 * Uses ONLY PHP's built-in ZipArchive extension — no Composer,
 * no PHPWord, no external library required. A .docx file is just a
 * ZIP archive containing a few XML parts, so this hand-builds the
 * minimum valid set of parts for a clean, professional-looking
 * title + labeled-sections report.
 *
 * Requires the "zip" PHP extension (near-universally available;
 * check with `php -m | grep zip`. If missing on a Linux server:
 * `sudo apt-get install php-zip` then restart your web server).
 *
 * Images: rows may include an 'images' entry — a list of already
 * loaded, normalized image buffers (see load_image_for_docx() in
 * export_report_docx.php) — which are embedded inline in the Word
 * table cell as real pictures, not just filenames.
 *
 * Usage:
 *   $binary = generate_simple_docx(
 *       'Report Title',
 *       'Subtitle line (e.g. generated date, ref number)',
 *       [
 *           [
 *               'heading' => 'Section Heading',   // or null for no heading
 *               'rows'    => [
 *                   ['label' => 'Location', 'value' => '123 Main St'],
 *                   ['label' => 'Status',   'value' => 'Ongoing'],
 *                   ['label' => 'Evidence Images', 'images' => [
 *                       ['data' => $jpegBinary1, 'width' => 800, 'height' => 600],
 *                       ['data' => $jpegBinary2, 'width' => 1024,'height' => 768],
 *                   ]],
 *               ],
 *           ],
 *           // ... more sections ...
 *       ],
 *       'Optional footer note, e.g. who generated it and when.',
 *       '2563EB' // accent color (hex, no '#') — theme the doc per page
 *   );
 *   file_put_contents('out.docx', $binary);
 * ─────────────────────────────────────────────────────────────────
 */

if (!function_exists('_docx_esc')) {
    /** Escape a string for safe placement inside a <w:t> element. */
    function _docx_esc(string $s): string {
        $s = str_replace(["\x00"], '', $s); // strip nulls, invalid in XML
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

if (!function_exists('_docx_hex_color')) {
    /** Validate/normalize a hex color, falling back to a default. */
    function _docx_hex_color(?string $color, string $default = '16A34A'): string {
        if ($color === null) return $default;
        $color = ltrim(trim($color), '#');
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $color)) return $default;
        return strtoupper($color);
    }
}

if (!function_exists('_docx_lighten')) {
    /** Return a lighter tint of a hex color, for subtle backgrounds. */
    function _docx_lighten(string $hex, float $amount = 0.88): string {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) $hex = '16A34A';
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $r = (int)round($r + (255 - $r) * $amount);
        $g = (int)round($g + (255 - $g) * $amount);
        $b = (int)round($b + (255 - $b) * $amount);
        return strtoupper(sprintf('%02X%02X%02X', $r, $g, $b));
    }
}

if (!function_exists('generate_simple_docx')) {
    /**
     * @param string $title      Big centered title at the top of the document.
     * @param string $subtitle   Smaller centered line under the title (optional, pass '').
     * @param array  $sections   List of ['heading' => ?string, 'rows' => [['label','value'|'images'], ...]]
     * @param string $footerNote Optional italic note at the very bottom (pass '' to omit).
     * @param string $accentColor Hex color (no '#') used for headings/dividers/table shading.
     * @return string Raw binary contents of the .docx file.
     */
    function generate_simple_docx(string $title, string $subtitle, array $sections, string $footerNote = '', string $accentColor = '16A34A'): string {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'The PHP "zip" extension is not enabled on this server. ' .
                'Install it with "sudo apt-get install php-zip" (Linux) or enable ' .
                'extension=zip in php.ini, then restart your web server.'
            );
        }

        $accent    = _docx_hex_color($accentColor);
        $tintShade = _docx_lighten($accent, 0.92); // very light tint for the table header column

        // ── Image collection (populated while walking rows below) ─────
        $mediaParts  = [];   // ['filename' => 'image1.jpeg', 'data' => binary]
        $imageRelIds = [];   // 'rId1' => 'media/image1.jpeg'
        $imgSeq      = 0;

        // Max display width for an inline image (content column is ~4.68in wide)
        $maxImgWidthIn = 1.55;
        $maxImgWidthEmu = (int)round($maxImgWidthIn * 914400);

        /** Build a <w:drawing> inline-picture run for one image buffer. */
        $buildImageRun = function (array $img) use (&$imgSeq, &$mediaParts, &$imageRelIds, $maxImgWidthEmu): string {
            $data = $img['data'] ?? '';
            if ($data === '') return '';
            $w = max(1, (int)($img['width']  ?? 0));
            $h = max(1, (int)($img['height'] ?? 0));

            $imgSeq++;
            $filename = 'image' . $imgSeq . '.jpeg';
            $relId    = 'rId' . (100 + $imgSeq); // offset avoids clashing with any future core rels
            $mediaParts[] = ['filename' => $filename, 'data' => $data];
            $imageRelIds[$relId] = 'media/' . $filename;

            $cx = $maxImgWidthEmu;
            $cy = (int)round($cx * ($h / $w));

            return '<w:r><w:rPr><w:noProof/></w:rPr><w:drawing>'
                 . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
                 . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
                 . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
                 . '<wp:docPr id="' . $imgSeq . '" name="Image' . $imgSeq . '"/>'
                 . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
                 . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                 . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . $imgSeq . '" name="Image' . $imgSeq . '"/><pic:cNvPicPr/></pic:nvPicPr>'
                 . '<pic:blipFill><a:blip r:embed="' . $relId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                 . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
                 . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
                 . '</pic:pic></a:graphicData></a:graphic>'
                 . '</wp:inline></w:drawing></w:r>';
        };

        $body = '';

        // ── Title block ──────────────────────────────────────────────
        $body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="60"/></w:pPr>'
               . '<w:r><w:rPr><w:b/><w:sz w:val="40"/><w:szCs w:val="40"/><w:color w:val="1F2937"/></w:rPr>'
               . '<w:t xml:space="preserve">' . _docx_esc($title) . '</w:t></w:r></w:p>';

        if ($subtitle !== '') {
            $body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="200"/></w:pPr>'
                   . '<w:r><w:rPr><w:color w:val="6B7280"/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr>'
                   . '<w:t xml:space="preserve">' . _docx_esc($subtitle) . '</w:t></w:r></w:p>';
        } else {
            $body .= '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
        }

        // top divider rule (accent-colored)
        $body .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="8" w:space="1" w:color="' . $accent . '"/></w:pBdr>'
               . '<w:spacing w:after="220"/></w:pPr></w:p>';

        // ── Sections ──────────────────────────────────────────────────
        foreach ($sections as $section) {
            $heading = $section['heading'] ?? null;
            $rows    = $section['rows'] ?? [];
            if (empty($rows)) continue;

            if ($heading) {
                $body .= '<w:p><w:pPr><w:spacing w:before="120" w:after="100"/></w:pPr>'
                       . '<w:r><w:rPr><w:b/><w:sz w:val="24"/><w:szCs w:val="24"/><w:color w:val="' . $accent . '"/></w:rPr>'
                       . '<w:t xml:space="preserve">' . _docx_esc($heading) . '</w:t></w:r></w:p>';
            }

            $body .= '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>'
                   . '<w:tblBorders>'
                   . '<w:top w:val="single" w:sz="4" w:color="E5E7EB"/>'
                   . '<w:left w:val="single" w:sz="4" w:color="E5E7EB"/>'
                   . '<w:bottom w:val="single" w:sz="4" w:color="E5E7EB"/>'
                   . '<w:right w:val="single" w:sz="4" w:color="E5E7EB"/>'
                   . '<w:insideH w:val="single" w:sz="4" w:color="E5E7EB"/>'
                   . '<w:insideV w:val="single" w:sz="4" w:color="E5E7EB"/>'
                   . '</w:tblBorders>'
                   . '<w:tblLayout w:type="fixed"/>'
                   . '<w:tblCellMar><w:left w:w="140" w:type="dxa"/><w:right w:w="140" w:type="dxa"/>'
                   . '<w:top w:w="90" w:type="dxa"/><w:bottom w:w="90" w:type="dxa"/></w:tblCellMar>'
                   . '</w:tblPr>'
                   . '<w:tblGrid><w:gridCol w:w="2600"/><w:gridCol w:w="6740"/></w:tblGrid>';

            foreach ($rows as $row) {
                $label  = (string)($row['label'] ?? '');
                $images = $row['images'] ?? null;

                if (is_array($images) && !empty($images)) {
                    // Build one paragraph containing all images for this row;
                    // Word wraps them left-to-right within the cell width automatically.
                    $imgRuns = '';
                    foreach ($images as $img) {
                        if (!is_array($img) || empty($img['data'])) continue;
                        $imgRuns .= $buildImageRun($img);
                        // small spacer between images
                        $imgRuns .= '<w:r><w:rPr><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">  </w:t></w:r>';
                    }
                    $valuePs = $imgRuns !== ''
                        ? '<w:p><w:pPr><w:spacing w:line="360" w:lineRule="auto"/></w:pPr>' . $imgRuns . '</w:p>'
                        : '<w:p><w:r><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:t xml:space="preserve">—</w:t></w:r></w:p>';
                } else {
                    $value = (string)($row['value'] ?? '');
                    if (trim($value) === '') $value = '—';

                    // Multi-line values (e.g. descriptions) become multiple <w:p> in the same cell
                    $lines = preg_split('/\r\n|\r|\n/', $value);
                    $valuePs = '';
                    foreach ($lines as $line) {
                        $valuePs .= '<w:p><w:r><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr>'
                                  . '<w:t xml:space="preserve">' . _docx_esc($line) . '</w:t></w:r></w:p>';
                    }
                }

                $body .= '<w:tr>'
                       . '<w:tc><w:tcPr><w:tcW w:w="2600" w:type="dxa"/>'
                       . '<w:shd w:val="clear" w:color="auto" w:fill="' . $tintShade . '"/><w:vAlign w:val="center"/></w:tcPr>'
                       . '<w:p><w:r><w:rPr><w:b/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr>'
                       . '<w:t xml:space="preserve">' . _docx_esc($label) . '</w:t></w:r></w:p></w:tc>'
                       . '<w:tc><w:tcPr><w:tcW w:w="6740" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>'
                       . $valuePs . '</w:tc>'
                       . '</w:tr>';
            }
            $body .= '</w:tbl>';
            $body .= '<w:p><w:pPr><w:spacing w:after="160"/></w:pPr></w:p>'; // spacer after table
        }

        if ($footerNote !== '') {
            $body .= '<w:p><w:pPr><w:spacing w:before="200"/>'
                   . '<w:pBdr><w:top w:val="single" w:sz="4" w:space="1" w:color="D1D5DB"/></w:pBdr></w:pPr>'
                   . '<w:r><w:rPr><w:i/><w:sz w:val="18"/><w:szCs w:val="18"/><w:color w:val="6B7280"/></w:rPr>'
                   . '<w:t xml:space="preserve">' . _docx_esc($footerNote) . '</w:t></w:r></w:p>';
        }

        $hasImages = !empty($mediaParts);

        $documentXmlNs = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
                       . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        if ($hasImages) {
            $documentXmlNs .= ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
                            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
                            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<w:document ' . $documentXmlNs . '>'
            . '<w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
            . '<w:pgMar w:top="1440" w:right="1350" w:bottom="1440" w:left="1350" w:header="720" w:footer="720" w:gutter="0"/>'
            . '</w:sectPr></w:body></w:document>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . ($hasImages ? '<Default Extension="jpeg" ContentType="image/jpeg"/>' : '')
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';

        // word/_rels/document.xml.rels — only needed when the document embeds images
        $documentRelsXml = '';
        if ($hasImages) {
            $documentRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            foreach ($imageRelIds as $relId => $target) {
                $documentRelsXml .= '<Relationship Id="' . $relId . '" '
                    . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
                    . 'Target="' . $target . '"/>';
            }
            $documentRelsXml .= '</Relationships>';
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . _docx_esc($title) . '</dc:title>'
            . '<dc:creator>CIMM LGU System</dc:creator>'
            . '<cp:lastModifiedBy>CIMM LGU System</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>CIMM LGU System</Application></Properties>';

        $tmpFile = tempnam(sys_get_temp_dir(), 'docx_');
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            @unlink($tmpFile);
            throw new RuntimeException('Could not create the .docx archive on this server.');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $relsXml);
        $zip->addFromString('docProps/core.xml', $coreXml);
        $zip->addFromString('docProps/app.xml', $appXml);
        $zip->addFromString('word/document.xml', $documentXml);
        if ($hasImages) {
            $zip->addFromString('word/_rels/document.xml.rels', $documentRelsXml);
            foreach ($mediaParts as $part) {
                $zip->addFromString('word/media/' . $part['filename'], $part['data']);
            }
        }
        $zip->close();

        $binary = file_get_contents($tmpFile);
        @unlink($tmpFile);
        return $binary;
    }
}