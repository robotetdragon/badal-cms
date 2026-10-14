<?php

use PHPUnit\Framework\TestCase;

class AudioDurationTest extends TestCase
{
    // =========================================================================
    //  secondsToTimecode
    // =========================================================================

    public function testSecondsToTimecodeMinutesOnly(): void
    {
        $this->assertSame('5:30', AudioDuration::secondsToTimecode(330));
    }

    public function testSecondsToTimecodeWithHours(): void
    {
        $this->assertSame('1:05:30', AudioDuration::secondsToTimecode(3930));
    }

    public function testSecondsToTimecodeZero(): void
    {
        $this->assertSame('0:00', AudioDuration::secondsToTimecode(0));
    }

    public function testSecondsToTimecodeRoundsFloat(): void
    {
        $this->assertSame('1:01', AudioDuration::secondsToTimecode(60.6));
    }

    public function testSecondsToTimecodeShortDuration(): void
    {
        $this->assertSame('0:45', AudioDuration::secondsToTimecode(45));
    }

    public function testSecondsToTimecodeExactHour(): void
    {
        $this->assertSame('1:00:00', AudioDuration::secondsToTimecode(3600));
    }

    public function testSecondsToTimecodeMultipleHours(): void
    {
        $this->assertSame('2:30:00', AudioDuration::secondsToTimecode(9000));
    }

    // =========================================================================
    //  fromFile — error cases (no ffprobe needed)
    // =========================================================================

    public function testFromFileReturnsEmptyForMissingFile(): void
    {
        $this->assertSame('', AudioDuration::fromFile('/inexistant/fichier.mp3'));
    }

    // =========================================================================
    //  fromFile — real file (skipped when ffmpeg is not installed)
    // =========================================================================

    public function testFromFileReadsDurationWithSpacesInPath(): void
    {
        $dir  = TEST_FIXTURES_DIR . '/audio_' . uniqid();
        $file = $dir . '/mon episode.mp3';
        mkdir($dir, 0755, true);

        // 3 seconds of silence generated on the fly
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        @exec('ffmpeg -v error -f lavfi -i anullsrc=r=44100:cl=mono -t 3 -q:a 9 '
            . escapeshellarg($file) . ' 2>' . $null, $out, $code);

        if ($code !== 0 || !is_file($file)) {
            rmdir($dir);
            $this->markTestSkipped('ffmpeg not available');
        }

        $duration = AudioDuration::fromFile($file);

        unlink($file);
        rmdir($dir);

        $this->assertSame('0:03', $duration);
    }
}
