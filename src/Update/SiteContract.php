<?php

declare(strict_types=1);

namespace Quire\Server\Update;

use Quire\Server\Process\ProcessResult;

/**
 * The server contract (Quire\Runtime\ServerContract) a site's code keeps, from its
 * "quire:about --format=json" report: the code's own answer, whatever its release, its branch or the
 * labels of its configuration say (those come from the site's files, and may be out of date).
 *
 * The report comes from the site's container, so it's read as untrusted: size-limited, strictly
 * typed, and never used for anything but this number.
 */
final readonly class SiteContract
{
    /** A report is a few kilobytes: more isn't one */
    public const int MAX_REPORT = 1_048_576;

    /**
     * @return int The version its code keeps; 0 when it has no quire:about (Quire before 0.1.1, which
     *             came with the contract), or its report has no "contract"
     *
     * @throws ContractNotKept When it answers with something that isn't a report with a contract
     */
    public static function of(ProcessResult $about): int
    {
        if (!$about->successful()) {
            return 0;
        }

        if (\strlen($about->output) > self::MAX_REPORT) {
            throw new ContractNotKept('The site\'s quire:about report is larger than any report is: which server contract its Quire keeps can\'t be told.');
        }

        try {
            $report = json_decode($about->output, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ContractNotKept('The site\'s quire:about report isn\'t JSON ('.$e->getMessage().'): which server contract its Quire keeps can\'t be told.', 0, $e);
        }

        if (!\is_array($report) || array_is_list($report)) {
            throw new ContractNotKept('The site\'s quire:about report isn\'t one: which server contract its Quire keeps can\'t be told.');
        }

        if (!\array_key_exists('contract', $report)) {
            return 0;
        }

        $contract = $report['contract'];

        if (!\is_int($contract) || $contract < 1) {
            throw new ContractNotKept('The site\'s quire:about report gives a server contract that isn\'t one (a whole number from 1): which it keeps can\'t be told.');
        }

        return $contract;
    }

    /**
     * @throws ContractNotKept When the code keeps a newer contract than this server
     */
    public static function ensureKept(int $contract): void
    {
        if ($contract > ReleaseCheck::CONTRACT) {
            throw new ContractNotKept(\sprintf('The site\'s Quire keeps server contract %d; this server knows up to %d: update the server first ("quire-server update").', $contract, ReleaseCheck::CONTRACT));
        }
    }
}
