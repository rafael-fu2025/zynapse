<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

final class FfmpegRunner
{
    private readonly string $binaryPath;

    public function __construct(?string $binaryPath = null)
    {
        $this->binaryPath = $binaryPath ?? (getenv('FFMPEG_BINARY') ?: 'ffmpeg');
    }

    /**
     * Extract a single frame from a video or image and apply a scale/pad filter.
     *
     * @param string $input Source file path
     * @param string $output Destination file path
     * @param string $filter FFmpeg video filter string
     * @param int|null $seekSeconds Optional seek position for videos (default: 1 second)
     * @return bool True if extraction succeeded
     */
    public function extractFrame(string $input, string $output, string $filter, ?int $seekSeconds = null): bool
    {
        if (!is_file($input)) {
            return false;
        }

        $command = [$this->binaryPath, '-hide_banner', '-loglevel', 'error', '-y'];
        
        if ($seekSeconds !== null) {
            array_push($command, '-ss', (string)$seekSeconds);
        }
        
        array_push($command, '-i', $input, '-frames:v', '1', '-vf', $filter, '-q:v', '3', $output);

        $success = $this->execute($command);
        
        if (!$success) {
            @unlink($output);
            return false;
        }

        return is_file($output) && filesize($output) > 0;
    }

    /**
     * Execute an FFmpeg command.
     *
     * @param array<int,string> $command Command array with binary and arguments
     * @return bool True if the command exited with code 0
     */
    private function execute(array $command): bool
    {
        return ProcessExecutor::execute($command);
    }
}
