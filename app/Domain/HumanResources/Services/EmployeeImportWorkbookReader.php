<?php

namespace App\Domain\HumanResources\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Extrae valores de la plantilla XLSX sin convertir el libro en una fuente confiable.
 *
 * Solo interpreta la estructura del archivo; las reglas institucionales y la
 * persistencia permanecen en EmployeeBulkImportService/HumanResourcesService.
 */
class EmployeeImportWorkbookReader
{
    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const MAX_UNCOMPRESSED_BYTES = 10_485_760;

    /**
     * @return array<int, array<int, string|null>>
     */
    public function read(UploadedFile $file): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages([
                'file' => 'El servidor no tiene disponible el lector de archivos Excel.',
            ]);
        }

        $archive = new ZipArchive;
        $opened = $archive->open($file->getRealPath());

        if ($opened !== true) {
            throw ValidationException::withMessages([
                'file' => 'No fue posible abrir el archivo Excel. Descargue nuevamente la plantilla y vuelva a intentarlo.',
            ]);
        }

        try {
            $this->assertSafeArchive($archive);
            $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');

            if ($sheetXml === false) {
                throw ValidationException::withMessages([
                    'file' => 'El archivo no contiene la hoja principal de funcionarios.',
                ]);
            }

            return $this->parseWorksheet($sheetXml, $this->sharedStrings($archive));
        } finally {
            $archive->close();
        }
    }

    private function assertSafeArchive(ZipArchive $archive): void
    {
        $totalSize = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            $name = $stat['name'] ?? '';
            $totalSize += (int) ($stat['size'] ?? 0);

            if (str_contains($name, '..') || $totalSize > self::MAX_UNCOMPRESSED_BYTES) {
                throw ValidationException::withMessages([
                    'file' => 'El archivo Excel no es válido o excede el tamaño permitido para procesarlo.',
                ]);
            }
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $archive): array
    {
        $xml = $archive->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $document = $this->xml($xml);
        $document->registerXPathNamespace('main', self::SPREADSHEET_NAMESPACE);

        return collect($document->xpath('//main:si') ?: [])
            ->map(fn (SimpleXMLElement $item): string => $this->textFrom($item))
            ->all();
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string|null>>
     */
    private function parseWorksheet(string $xml, array $sharedStrings): array
    {
        $document = $this->xml($xml);
        $document->registerXPathNamespace('main', self::SPREADSHEET_NAMESPACE);
        $rows = [];

        foreach ($document->xpath('//main:sheetData/main:row') ?: [] as $row) {
            $rowNumber = (int) $row['r'];
            $row->registerXPathNamespace('main', self::SPREADSHEET_NAMESPACE);
            $values = [];

            foreach ($row->xpath('./main:c') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                $column = $this->columnIndex($reference);
                $values[$column] = $this->cellValue($cell, (string) $cell['t'], $sharedStrings);
            }

            $rows[$rowNumber] = $values;
        }

        return $rows;
    }

    /** @param array<int, string> $sharedStrings */
    private function cellValue(SimpleXMLElement $cell, string $type, array $sharedStrings): ?string
    {
        $cell->registerXPathNamespace('main', self::SPREADSHEET_NAMESPACE);

        if ($type === 'inlineStr') {
            return $this->textFrom($cell);
        }

        $value = (string) (($cell->xpath('./main:v')[0] ?? null) ?: '');

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? null;
        }

        return $value === '' ? null : $value;
    }

    private function textFrom(SimpleXMLElement $element): string
    {
        $element->registerXPathNamespace('main', self::SPREADSHEET_NAMESPACE);

        return trim(implode('', array_map(
            fn (SimpleXMLElement $node): string => (string) $node,
            $element->xpath('.//main:t') ?: [],
        )));
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/', $reference, $matches);
        $letters = $matches[0] ?? '';
        $column = 0;

        foreach (str_split($letters) as $letter) {
            $column = ($column * 26) + (ord($letter) - 64);
        }

        return $column - 1;
    }

    private function xml(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);

            if (! $document instanceof SimpleXMLElement) {
                throw ValidationException::withMessages([
                    'file' => 'El archivo Excel tiene contenido XML inválido.',
                ]);
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
