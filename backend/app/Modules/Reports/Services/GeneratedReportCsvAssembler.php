<?php

declare(strict_types=1);

namespace Modules\Reports\Services;

/** Compose a retained CSV artifact from provenance and streamed data rows. */
final class GeneratedReportCsvAssembler
{
    /**
     * @param array<int, string> $headers
     * @param array<string, scalar|null> $metadata
     */
    public static function assemble(
        string $path,
        string $partPath,
        array $headers,
        array $metadata,
    ): void {
        $output = fopen($path, 'wb');
        $input = fopen($partPath, 'rb');
        if ($output === false || $input === false) {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_resource($input)) {
                fclose($input);
            }
            throw new \RuntimeException('Unable to assemble the generated report file.');
        }

        try {
            fputcsv($output, ['SYNAPSE generated report', ''], ',', '"', '');
            foreach ($metadata as $label => $value) {
                fputcsv(
                    $output,
                    ReportExportPolicy::sanitizeRow([$label, $value]),
                    ',',
                    '"',
                    '',
                );
            }
            fputcsv($output, [], ',', '"', '');
            fputcsv($output, ReportExportPolicy::sanitizeRow($headers), ',', '"', '');
            if (stream_copy_to_stream($input, $output) === false) {
                throw new \RuntimeException('Unable to finalize the generated report file.');
            }
        } finally {
            fclose($input);
            fclose($output);
            @unlink($partPath);
        }
    }
}
