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
}
