<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Security\PageAccessService;

/**
 * The rules that decide whether a page may be shown.
 *
 * They are applied twice with different groups: with the anonymous ones for
 * llms.txt, llms-full.txt and the Markdown export, which are public documents
 * cached for everybody, and with the visitor's own for the search assistant,
 * where a member page belongs in the results of the member who is logged in.
 */
final class PageVisibilityTest extends TestCase
{
    private const NOW = 1_700_000_000;

    private const ANONYMOUS = PageAccessService::ANONYMOUS_GROUPS;

    /** A logged-in member of group 3, the way the frontend user aspect reports it. */
    private const MEMBER = [0, -2, 3];

    /**
     * @param array<string, mixed> $row
     * @param list<int> $groupIds
     */
    #[Test]
    #[DataProvider('pages')]
    public function aPageIsShownOnlyWhileItIsActiveAndReachable(
        array $row,
        array $groupIds,
        bool $expected,
        string $why
    ): void {
        self::assertSame($expected, PageAccessService::isVisibleRow($row, $groupIds, self::NOW), $why);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<int>, 2: bool, 3: string}>
     */
    public static function pages(): array
    {
        return [
            'an ordinary page' => [
                [],
                self::ANONYMOUS,
                true,
                'Nothing set means nothing in the way.',
            ],
            'a disabled page' => [
                ['hidden' => 1],
                self::ANONYMOUS,
                false,
                'The "Disable" checkbox is the whole point of this.',
            ],
            'a disabled page for a logged-in visitor' => [
                ['hidden' => 1],
                self::MEMBER,
                false,
                'A login does not un-disable a page.',
            ],
            'a deleted page' => [
                ['deleted' => 1],
                self::ANONYMOUS,
                false,
                '',
            ],
            'a page that is not published yet' => [
                ['starttime' => self::NOW + 60],
                self::ANONYMOUS,
                false,
                '',
            ],
            'a page published a minute ago' => [
                ['starttime' => self::NOW - 60],
                self::ANONYMOUS,
                true,
                '',
            ],
            'a page that has expired' => [
                ['endtime' => self::NOW - 1],
                self::ANONYMOUS,
                false,
                '',
            ],
            'a page expiring in a minute' => [
                ['endtime' => self::NOW + 60],
                self::ANONYMOUS,
                true,
                '',
            ],
            'an endtime of zero is no endtime' => [
                ['endtime' => 0],
                self::ANONYMOUS,
                true,
                '0 means "never", not "in 1970".',
            ],
            'a member page seen by a visitor' => [
                ['fe_group' => '3'],
                self::ANONYMOUS,
                false,
                'Without a login there is no access to a group-restricted page.',
            ],
            'a member page seen by the member' => [
                ['fe_group' => '3'],
                self::MEMBER,
                true,
                'The whole point of asking per request: the member may see it.',
            ],
            'a member page seen by another member' => [
                ['fe_group' => '4'],
                self::MEMBER,
                false,
                'Logged in is not the same as entitled.',
            ],
            'one of several groups is enough' => [
                ['fe_group' => '4,3'],
                self::MEMBER,
                true,
                '',
            ],
            'fe_group zero is no restriction' => [
                ['fe_group' => '0'],
                self::MEMBER,
                true,
                'TYPO3 writes "0" for "show at any visitor".',
            ],
            '"hide at login" for a visitor' => [
                ['fe_group' => '-1'],
                self::ANONYMOUS,
                true,
                '-1 is "hide at login" — a visitor without one still sees it.',
            ],
            '"hide at login" for a member' => [
                ['fe_group' => '-1'],
                self::MEMBER,
                false,
                '',
            ],
            '"show at any login" for a visitor' => [
                ['fe_group' => '-2'],
                self::ANONYMOUS,
                false,
                '',
            ],
            '"show at any login" for a member' => [
                ['fe_group' => '-2'],
                self::MEMBER,
                true,
                '',
            ],
            'disabled wins over an entitlement' => [
                ['hidden' => 1, 'fe_group' => '3'],
                self::MEMBER,
                false,
                '',
            ],
            'values arrive as strings from the database' => [
                ['hidden' => '1'],
                self::ANONYMOUS,
                false,
                'The driver hands back strings; the check must not care.',
            ],
        ];
    }
}
