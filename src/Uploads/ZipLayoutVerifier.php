<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * Checks that a ZIP holds nothing a streaming reader would find but libzip would not.
 *
 * libzip reads only what the central directory references; streaming readers
 * (libarchive from a pipe, Java's ZipInputStream) walk the local headers from
 * byte 0 instead. A local header the directory never lists, or bytes between
 * entries, can hide a bomb from the inflation budget. So the local headers,
 * walked in central-directory order, must tile the file exactly from byte 0 up to
 * the central directory, which must end exactly where the end record starts, and
 * that end record must be the only one. For files libzip will not open at all, it
 * also tells whether a more tolerant reader would.
 */
final class ZipLayoutVerifier
{
    private const string LOCAL_HEADER = "PK\x03\x04";

    private const string DATA_DESCRIPTOR = "PK\x07\x08";

    private const string CENTRAL_HEADER = "PK\x01\x02";

    private const string END_RECORD = "PK\x05\x06";

    private const string ZIP64_END_RECORD = "PK\x06\x06";

    private const string ZIP64_END_LOCATOR = "PK\x06\x07";

    /**
     * @param  \ZipArchive  $archive  the same file, already opened by libzip
     */
    public function coversWholeFile(string $absolutePath, int $fileBytes, \ZipArchive $archive): bool
    {
        $file = new \SplFileObject($absolutePath, 'rb');
        $directoryOffset = $this->centralDirectoryOffset($file, $fileBytes);

        return $directoryOffset !== null && $this->localHeadersEndAt($file, $archive) === $directoryOffset;
    }

    /**
     * Whether a ZIP reader that tolerates leading data would open this file: an end
     * record in the tail whose central directory starts right before it (record
     * offset minus directory size, where unzip and Python's zipfile look), or a ZIP64
     * one. A stray signature in an ordinary file has no directory there.
     */
    public function hasReadableEndRecord(string $absolutePath, int $fileBytes): bool
    {
        $file = new \SplFileObject($absolutePath, 'rb');
        $searchStart = max(0, $fileBytes - 65_557);
        $tail = $this->read($file, $searchStart, $fileBytes - $searchStart);
        $candidate = strrpos($tail, self::END_RECORD);

        while ($candidate !== false) {
            if ($this->isReadableEndRecord($file, $searchStart + $candidate)) {
                return true;
            }

            $candidate = strrpos(substr($tail, 0, $candidate), self::END_RECORD);
        }

        return false;
    }

    /**
     * Where the local headers stop when walked from byte 0 in central-directory
     * order, or null when one is missing or misplaced.
     */
    private function localHeadersEndAt(\SplFileObject $file, \ZipArchive $archive): ?int
    {
        $position = 0;

        for ($entryIndex = 0; $entryIndex < $archive->count(); $entryIndex++) {
            $header = $this->read($file, $position, 30);
            $entry = $archive->statIndex($entryIndex);

            if ($entry === false || strlen($header) !== 30 || ! str_starts_with($header, self::LOCAL_HEADER)) {
                return null;
            }

            $fields = $this->fields('x6/vflags/x18/vnameLength/vextraLength', $header);
            $extra = $this->read($file, $position + 30 + $fields['nameLength'], $fields['extraLength']);
            $position += 30 + $fields['nameLength'] + $fields['extraLength'] + $entry['comp_size'];

            // Bit 3: the sizes follow the data, after an optional signature.
            if (($fields['flags'] & 0x08) !== 0) {
                if ($this->read($file, $position, 4) === self::DATA_DESCRIPTOR) {
                    $position += 4;
                }

                // CRC, then compressed and uncompressed sizes: 8 bytes each for ZIP64.
                $position += $this->hasZip64Field($extra) ? 20 : 12;
            }
        }

        return $position;
    }

    /**
     * The central directory offset, when the directory ends exactly where the
     * end record (or its ZIP64 record) starts; null otherwise.
     */
    private function centralDirectoryOffset(\SplFileObject $file, int $fileBytes): ?int
    {
        $endRecordAt = $this->endRecordPosition($file, $fileBytes);

        if ($endRecordAt === null) {
            return null;
        }

        $endRecord = $this->fields('x12/VsizeBytes/VoffsetBytes', $this->read($file, $endRecordAt, 22));

        if ($endRecord['sizeBytes'] === 0xFFFF_FFFF || $endRecord['offsetBytes'] === 0xFFFF_FFFF) {
            return $this->zip64CentralDirectoryOffset($file, $endRecordAt);
        }

        return $endRecordAt === $endRecord['offsetBytes'] + $endRecord['sizeBytes'] ? $endRecord['offsetBytes'] : null;
    }

    /**
     * The last end record, which must run with its comment exactly to the end of
     * the file. Any other record after it (appended, or inside its comment) is the
     * last one instead, and then the directory does not end where it starts.
     */
    private function endRecordPosition(\SplFileObject $file, int $fileBytes): ?int
    {
        // 22 bytes of end record plus the longest comment: as far back as readers search.
        $searchStart = max(0, $fileBytes - 65_557);
        $tail = $this->read($file, $searchStart, $fileBytes - $searchStart);
        $endRecordAt = strrpos($tail, self::END_RECORD);

        if ($endRecordAt === false || strlen($tail) - $endRecordAt < 22) {
            return null;
        }

        $commentLength = $this->fields('x20/vcommentLength', $tail, $endRecordAt)['commentLength'];

        return $endRecordAt + 22 + $commentLength === strlen($tail) ? $searchStart + $endRecordAt : null;
    }

    private function zip64CentralDirectoryOffset(\SplFileObject $file, int $endRecordAt): ?int
    {
        $locatorAt = $endRecordAt - 20;
        $locator = $this->read($file, $locatorAt, 20);

        if (! str_starts_with($locator, self::ZIP64_END_LOCATOR)) {
            return null;
        }

        $recordAt = $this->fields('x8/PrecordAt', $locator)['recordAt'];
        // The record runs up to its locator, which the end record follows.
        $recordBytes = $locatorAt - $recordAt;

        if ($recordBytes < 56) {
            return null;
        }

        $record = $this->read($file, $recordAt, $recordBytes);
        $directory = $this->fields('x4/PrecordSize/x28/PsizeBytes/PoffsetBytes', $record);

        if (! str_starts_with($record, self::ZIP64_END_RECORD) || 12 + $directory['recordSize'] !== $recordBytes) {
            return null;
        }

        return $recordAt === $directory['offsetBytes'] + $directory['sizeBytes'] ? $directory['offsetBytes'] : null;
    }

    private function isReadableEndRecord(\SplFileObject $file, int $recordAt): bool
    {
        $record = $this->read($file, $recordAt, 22);

        if (strlen($record) !== 22) {
            return false;
        }

        $fields = $this->fields('x12/VdirectoryBytes/VdirectoryOffset', $record);

        // ZIP64 keeps the real values elsewhere: treat it as readable.
        if ($fields['directoryBytes'] === 0xFFFF_FFFF || $fields['directoryOffset'] === 0xFFFF_FFFF) {
            return true;
        }

        return $this->read($file, $recordAt - $fields['directoryBytes'], 4) === self::CENTRAL_HEADER;
    }

    private function hasZip64Field(string $extra): bool
    {
        $position = 0;

        while ($position + 4 <= strlen($extra)) {
            $field = $this->fields('vid/vsize', $extra, $position);

            if ($field['id'] === 0x0001) {
                return true;
            }

            $position += 4 + $field['size'];
        }

        return false;
    }

    /**
     * Callers check the byte count first, so unpack() never runs short.
     *
     * @return array<int|string, int>
     */
    private function fields(string $format, string $bytes, int $offset = 0): array
    {
        return array_filter((array) unpack($format, $bytes, $offset), is_int(...));
    }

    private function read(\SplFileObject $file, int $offset, int $length): string
    {
        // fread() refuses a zero length; fseek() refuses a negative offset.
        if ($length < 1 || $file->fseek($offset) !== 0) {
            return '';
        }

        return (string) $file->fread($length);
    }
}
