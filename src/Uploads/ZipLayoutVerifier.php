<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @internal Checks that a ZIP holds nothing a streaming reader would find but libzip would not.
 *
 * libzip reads only what the central directory references; streaming readers
 * (libarchive from a pipe, Java's ZipInputStream) walk the local headers from
 * byte 0 instead. A local header the directory never lists, or bytes between
 * entries, can hide a bomb from the inflation budget. So each local header must
 * sit exactly at its directory offset, the headers must tile the file from byte 0
 * up to the central directory with every data descriptor matching the directory,
 * and the directory must end exactly where the only end record starts. This checks
 * structure only; it does not make streaming extraction safe. For files libzip
 * will not open at all, it also tells whether a more tolerant reader would.
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
        $directory = $this->centralDirectory($file, $fileBytes);

        if ($directory === null) {
            return false;
        }

        $localHeaderOffsets = $this->localHeaderOffsets($file, $directory, $archive->count());

        return $localHeaderOffsets !== null
            && $this->localHeadersEndAt($file, $archive, $localHeaderOffsets) === $directory['offset'];
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
     * Where the local headers stop when walked from byte 0, or null when a header
     * is not exactly where its directory entry says, which is how a header hidden
     * in another one's extra field or data is caught. A stored entry with a data
     * descriptor is refused: nothing but the descriptor marks where its data ends,
     * so a streaming reader can be made to stop early and read on into hidden bytes.
     *
     * @param  list<int>  $localHeaderOffsets  from the central directory, in its order
     */
    private function localHeadersEndAt(\SplFileObject $file, \ZipArchive $archive, array $localHeaderOffsets): ?int
    {
        $position = 0;

        foreach ($localHeaderOffsets as $entryIndex => $localHeaderOffset) {
            $header = $this->read($file, $position, 30);
            $entry = $archive->statIndex($entryIndex);

            if ($position !== $localHeaderOffset || $entry === false || strlen($header) !== 30 || ! str_starts_with($header, self::LOCAL_HEADER)) {
                return null;
            }

            $fields = $this->fields('x6/vflags/vmethod/x16/vnameLength/vextraLength', $header);
            $extra = $this->read($file, $position + 30 + $fields['nameLength'], $fields['extraLength']);
            $position += 30 + $fields['nameLength'] + $fields['extraLength'] + $entry['comp_size'];

            if (($fields['flags'] & 0x08) === 0) {
                continue;
            }

            if ($fields['method'] === 0) {
                return null;
            }

            $position = $this->dataDescriptorEnd($file, $position, $entry, $this->hasZip64Field($extra));

            if ($position === null) {
                return null;
            }
        }

        return $position;
    }

    /**
     * Where a data descriptor ends, or null when it disagrees with the directory.
     * The signature is optional, so it counts only when the CRC follows it.
     *
     * @param  array{crc: int, comp_size: int, size: int}  $entry
     */
    private function dataDescriptorEnd(\SplFileObject $file, int $position, array $entry, bool $isZip64): ?int
    {
        $signatureAndCrc = $this->read($file, $position, 8);

        if ($signatureAndCrc === self::DATA_DESCRIPTOR.pack('V', $entry['crc'])) {
            $position += 4;
        }

        $descriptorBytes = $isZip64 ? 20 : 12;
        $descriptor = $this->read($file, $position, $descriptorBytes);
        $expected = pack('V', $entry['crc']).($isZip64
            ? pack('PP', $entry['comp_size'], $entry['size'])
            : pack('VV', $entry['comp_size'], $entry['size']));

        return $descriptor === $expected ? $position + $descriptorBytes : null;
    }

    /**
     * The local header offset of every directory entry, in directory order, or null
     * when the entries do not fill the directory exactly.
     *
     * @param  array{offset: int, end: int}  $directory
     * @return list<int>|null
     */
    private function localHeaderOffsets(\SplFileObject $file, array $directory, int $entryCount): ?array
    {
        $offsets = [];
        $position = $directory['offset'];

        for ($entryIndex = 0; $entryIndex < $entryCount; $entryIndex++) {
            $header = $this->read($file, $position, 46);

            if (strlen($header) !== 46 || ! str_starts_with($header, self::CENTRAL_HEADER)) {
                return null;
            }

            $fields = $this->fields('x20/VcompressedBytes/VuncompressedBytes/vnameLength/vextraLength/vcommentLength/x8/VlocalOffset', $header);
            $extra = $this->read($file, $position + 46 + $fields['nameLength'], $fields['extraLength']);
            $offsets[] = $fields['localOffset'] === 0xFFFF_FFFF ? $this->zip64LocalOffset($extra, $fields) : $fields['localOffset'];
            $position += 46 + $fields['nameLength'] + $fields['extraLength'] + $fields['commentLength'];
        }

        return $position === $directory['end'] ? $offsets : null;
    }

    /**
     * In the ZIP64 extra field the 64-bit values appear in a fixed order, each only
     * when its 32-bit field is maxed: uncompressed size, compressed size, offset.
     *
     * @param  array<int|string, int>  $fields
     */
    private function zip64LocalOffset(string $extra, array $fields): int
    {
        $skippedBytes = ($fields['uncompressedBytes'] === 0xFFFF_FFFF ? 8 : 0) + ($fields['compressedBytes'] === 0xFFFF_FFFF ? 8 : 0);
        $position = 0;

        while ($position + 4 <= strlen($extra)) {
            $field = $this->fields('vid/vsize', $extra, $position);
            $offsetAt = $position + 4 + $skippedBytes;

            // The bytes actually present decide, not the size the field claims.
            if ($field['id'] === 0x0001) {
                return $offsetAt + 8 <= strlen($extra) ? $this->fields('Poffset', $extra, $offsetAt)['offset'] : PHP_INT_MIN;
            }

            $position += 4 + $field['size'];
        }

        // No ZIP64 value at all: an offset no header can be at.
        return PHP_INT_MIN;
    }

    /**
     * The central directory, when it ends exactly where the end record (or its
     * ZIP64 record) starts; null otherwise.
     *
     * @return array{offset: int, end: int}|null
     */
    private function centralDirectory(\SplFileObject $file, int $fileBytes): ?array
    {
        $endRecordAt = $this->endRecordPosition($file, $fileBytes);

        if ($endRecordAt === null) {
            return null;
        }

        $endRecord = $this->fields('x12/VsizeBytes/VoffsetBytes', $this->read($file, $endRecordAt, 22));

        if ($endRecord['sizeBytes'] === 0xFFFF_FFFF || $endRecord['offsetBytes'] === 0xFFFF_FFFF) {
            return $this->zip64CentralDirectory($file, $endRecordAt);
        }

        return $endRecordAt === $endRecord['offsetBytes'] + $endRecord['sizeBytes']
            ? ['offset' => $endRecord['offsetBytes'], 'end' => $endRecordAt]
            : null;
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

    /**
     * @return array{offset: int, end: int}|null
     */
    private function zip64CentralDirectory(\SplFileObject $file, int $endRecordAt): ?array
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

        return $recordAt === $directory['offsetBytes'] + $directory['sizeBytes']
            ? ['offset' => $directory['offsetBytes'], 'end' => $recordAt]
            : null;
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
