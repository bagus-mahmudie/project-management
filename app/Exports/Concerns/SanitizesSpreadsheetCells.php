<?php

namespace App\Exports\Concerns;

trait SanitizesSpreadsheetCells
{
    protected function sanitizeSpreadsheetCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value;
    }

    protected function sanitizeSpreadsheetRow(array $row): array
    {
        return array_map(fn ($value) => $this->sanitizeSpreadsheetCell($value), $row);
    }
}
