<?php

namespace App\Modules\Payroll\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class XlsxWorkbookReader
{
    /**
     * @return array<string, array<int, array<string, array{v: mixed, f: ?string}>>>
     */
    public function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the Excel workbook.');
        }

        try {
            $shared = $this->sharedStrings($zip);
            $sheetFiles = $this->sheetFiles($zip);
            $sheets = [];
            foreach ($sheetFiles as $name => $file) {
                $xml = $zip->getFromName($file);
                if ($xml === false) {
                    $xml = $zip->getFromName('xl/'.$file);
                }
                if ($xml === false) {
                    continue;
                }
                $sheets[$name] = $this->parseSheet($xml, $shared);
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $root = simplexml_load_string($xml);
        if ($root === false) {
            return [];
        }

        $strings = [];
        foreach ($this->xpath($root, '//*[local-name()="si"]') as $si) {
            $texts = $this->xpath($si, './/*[local-name()="t"]');
            $strings[] = implode('', array_map(fn ($node) => (string) $node, $texts));
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private function sheetFiles(ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            throw new RuntimeException('Workbook is missing sheet metadata.');
        }

        $relRoot = simplexml_load_string($rels);
        $wbRoot = simplexml_load_string($workbook);
        if ($relRoot === false || $wbRoot === false) {
            throw new RuntimeException('Workbook metadata is invalid.');
        }

        $targets = [];
        foreach ($this->xpath($relRoot, '//*[local-name()="Relationship"]') as $rel) {
            $targets[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
        }

        $sheets = [];
        foreach ($this->xpath($wbRoot, '//*[local-name()="sheet"]') as $sheet) {
            $name = (string) $sheet['name'];
            $rid = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
            if ($rid === '') {
                $rid = (string) ($sheet['id'] ?? '');
            }
            $target = $targets[$rid] ?? null;
            if (! $name || ! $target) {
                continue;
            }
            $sheets[$name] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return $sheets;
    }

    /**
     * @param  list<string>  $shared
     * @return array<int, array<string, array{v: mixed, f: ?string}>>
     */
    private function parseSheet(string $xml, array $shared): array
    {
        $root = simplexml_load_string($xml);
        if ($root === false) {
            return [];
        }

        $rows = [];
        foreach ($this->xpath($root, '//*[local-name()="c"]') as $cell) {
            $ref = (string) $cell['r'];
            if ($ref === '' || ! preg_match('/^([A-Z]+)(\d+)$/', $ref, $match)) {
                continue;
            }
            $col = $match[1];
            $row = (int) $match[2];
            $type = (string) $cell['t'];
            $formulaNode = $this->xpath($cell, './/*[local-name()="f"]')[0] ?? null;
            $valueNode = $this->xpath($cell, './/*[local-name()="v"]')[0] ?? null;
            $formula = $formulaNode !== null ? (string) $formulaNode : null;
            $raw = $valueNode !== null ? (string) $valueNode : null;
            $value = $raw;
            if ($type === 's' && $raw !== null && $raw !== '') {
                $value = $shared[(int) $raw] ?? $raw;
            } elseif ($type === 'inlineStr') {
                $texts = $this->xpath($cell, './/*[local-name()="t"]');
                $value = implode('', array_map(fn ($node) => (string) $node, $texts));
            }
            if ($formula === null && ($value === null || $value === '')) {
                continue;
            }
            $rows[$row][$col] = ['v' => $value, 'f' => $formula];
        }
        ksort($rows);

        return $rows;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function xpath(SimpleXMLElement $node, string $query): array
    {
        $result = $node->xpath($query);

        return $result === false ? [] : $result;
    }
}
