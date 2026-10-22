<?php
declare(strict_types=1);

require_once __DIR__ . '/sync.php';

class SyncServiceTest {
    private SyncService $service;

    public function __construct()
    {
        $reader = new JsonFileReader();
        $this->service = new SyncService(
            new TagLoader($reader),
            new RecruitmentLoader($reader),
            new MembershipLoader($reader),
        );
    }

    public function testCollectsMappedTags(): void
    {
        $mapping = [
            'Balaton' => [new Tag('brand-balaton')],
            'Vistula' => [new Tag('brand-triglav'), new Tag('brand-vistula')],
        ];

        $actual = $this->service->getMappedTagNames($mapping);

        $this->check(['brand-balaton', 'brand-triglav', 'brand-vistula'], $actual);
    }

    public function testCandidateWithoutRecruitmentsHasNoTags(): void
    {
        $actual = $this->service->getDesiredTagNames(new Membership(1, []));

        $this->check([], $actual);
    }

    public function testClosedRecruitmentAlsoGivesTags(): void
    {
        $recruitment = new Recruitment(
            101, 'Balaton', true, new DateTimeImmutable('2026-01-01'),
            [new Tag('brand-balaton')],
        );
        $candidate = new Membership(1, [$recruitment]);

        $actual = $this->service->getDesiredTagNames($candidate);

        $this->check(['brand-balaton'], $actual);
    }

    public function testRepeatedRecruitmentsDoNotDuplicateTags(): void
    {
        $recruitment = new Recruitment(
            101, 'Balaton', false, new DateTimeImmutable('2026-01-01'),
            [new Tag('brand-balaton')],
        );
        $candidate = new Membership(1, [$recruitment, $recruitment]);

        $actual = $this->service->getDesiredTagNames($candidate);

        $this->check(['brand-balaton'], $actual);
    }

    public function testReportsUnusedTagButLeavesVip(): void
    {
        $currentTags = ['brand-balaton', 'vip'];
        $mappedTags = ['brand-balaton'];
        $desiredTags = [];

        $actual = $this->service->getTagsToRemove($currentTags, $mappedTags, $desiredTags);

        $this->check(['brand-balaton'], $actual);
    }

    public function testStillNeededTagIsNotReported(): void
    {
        $currentTags = ['brand-balaton'];
        $mappedTags = ['brand-balaton'];
        $desiredTags = ['brand-balaton', 'brand-kampinos'];

        $actual = $this->service->getTagsToRemove($currentTags, $mappedTags, $desiredTags);

        $this->check([], $actual);
    }

    private function check(array $expected, array $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('Expected ' . json_encode($expected) . ', got ' . json_encode($actual));
        }
    }
}

$tests = new SyncServiceTest();
$tests->testCollectsMappedTags();
$tests->testCandidateWithoutRecruitmentsHasNoTags();
$tests->testClosedRecruitmentAlsoGivesTags();
$tests->testRepeatedRecruitmentsDoNotDuplicateTags();
$tests->testReportsUnusedTagButLeavesVip();
$tests->testStillNeededTagIsNotReported();
echo "OK: all 6 tests passed.\n";
