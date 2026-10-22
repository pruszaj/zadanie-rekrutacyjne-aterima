<?php
declare(strict_types=1);

require_once __DIR__ . '/system-b/ats.php';

$syncService = new SyncService(
    new TagLoader(new JsonFileReader()),
    new RecruitmentLoader(new JsonFileReader()),
    new MembershipLoader(new JsonFileReader())
);

$syncService->sync();

class SyncService {
    public function __construct(
        private TagLoader $tagLoader,
        private RecruitmentLoader $recruitmentLoader,
        private MembershipLoader $membershipLoader,
    )
    {
    }

    public function sync(): void
    {
        $brandTagMap = $this->tagLoader->load();
        $recruitments = $this->recruitmentLoader->load($brandTagMap);
        $memberships = $this->membershipLoader->load($recruitments);

        $progressPath = __DIR__ . '/progress.json';
        $progress = file_exists($progressPath)
            ? (new JsonFileReader())->read($progressPath)
            : ['completed' => [], 'pending' => []];

        $mappedTags = $this->getMappedTagNames($brandTagMap);

        $client = new FakeAtsClient();

        foreach ($memberships as $membership) {
            if (array_key_exists($membership->id, $progress['completed'])) {
                echo "Kandydat {$membership->id}: już zakończony, pomijam." . PHP_EOL;
                continue;
            }

            if (!isset($progress['pending'][$membership->id])) {
                $currentTags = $this->retry(
                    fn() => $client->tags($membership->id),
                );
                $desiredTags = $this->getDesiredTagNames($membership);

                $progress['pending'][$membership->id] = [
                    'tagsToAdd' => array_diff($desiredTags, $currentTags),
                    'tagsToRemove' => $this->getTagsToRemove($currentTags, $mappedTags, $desiredTags),
                ];
                $this->saveProgress($progressPath, $progress);
            } else {
                echo "Kandydat {$membership->id}: wznawiam zapisany plan." . PHP_EOL;
            }

            $tagsToAdd = $progress['pending'][$membership->id]['tagsToAdd'];
            $tagsToRemove = $progress['pending'][$membership->id]['tagsToRemove'];

            if ($tagsToAdd !== []) {
                echo "Dodaję kandydatowi {$membership->id}: " . implode(', ', $tagsToAdd) . PHP_EOL;

                $this->retry(fn() => $client->addTags($membership->id, $tagsToAdd));
            } else {
                echo "Kandydat {$membership->id}: brak tagów do dodania." . PHP_EOL;
            }

            if ($tagsToRemove !== []) {
                echo "Zgłaszam do zdjęcia kandydatowi {$membership->id}: " . implode(', ', $tagsToRemove) . PHP_EOL;
            }

            $progress['completed'][$membership->id] = $tagsToRemove;
            unset($progress['pending'][$membership->id]);
            $this->saveProgress($progressPath, $progress);
        }

        echo 'Raport tagów do zdjęcia:' . PHP_EOL;
        foreach ($progress['completed'] as $candidateId => $tagsToRemove) {
            if ($tagsToRemove !== []) {
                echo "Kandydat {$candidateId}: " . implode(', ', $tagsToRemove) . PHP_EOL;
            }
        }
    }

    public function getDesiredTagNames(Membership $membership): array
    {
        $tagNames = [];

        foreach ($membership->recruitments as $recruitment) {
            foreach ($recruitment->tags as $tag) {
                $tagNames[] = $tag->name;
            }
        }

        return array_unique($tagNames);
    }

    public function getTagsToRemove(array $currentTags, array $mappedTags, array $desiredTags): array
    {
        $tagsToRemove = [];

        foreach ($currentTags as $tag) {
            if (!in_array($tag, $mappedTags, true)) {
                continue;
            }

            if (!in_array($tag, $desiredTags, true)) {
                $tagsToRemove[] = $tag;
            }
        }

        return $tagsToRemove;
    }

    public function getMappedTagNames(array $brandTagMap): array
    {
        $tagNames = [];

        foreach ($brandTagMap as $brandTags) {
            foreach ($brandTags as $tag) {
                $tagNames[] = $tag->name;
            }
        }

        return $tagNames;
    }

    private function saveProgress(string $path, array $progress): void
    {
        $json = json_encode($progress, JSON_PRETTY_PRINT);

        $temporaryPath = $path . '.tmp';
        $writtenBytes = file_put_contents($temporaryPath, $json);

        if ($writtenBytes !== strlen($json)) {
            throw new RuntimeException('Failed to write the complete temporary file.');
        }

        if (!rename($temporaryPath, $path)) {
            throw new RuntimeException('Failed to replace the progress file.');
        }
    }

    private function retry(callable $callback, ?int $maxAttempts = null): mixed
    {
        if ($maxAttempts !== null && $maxAttempts < 1) {
            throw new InvalidArgumentException(
                'maxAttempts must be greater than 0'
            );
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $callback();
            } catch (RateLimitException $e) {
                if ($maxAttempts !== null && $attempt >= $maxAttempts) {
                    throw $e;
                }

                $delay = $e->retryAfter ?? 0;

                if ($delay > 0) {
                    sleep($delay);
                }
            }
        }
    }
}

class JsonFileReader {
    public function read(string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read file: {$path}");
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Cannot read file: {$path}");
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new UnexpectedValueException("Invalid JSON structure: {$path}");
        }

        return $data;
    }
}

class RecruitmentLoader {
    public function __construct(private JsonFileReader $jsonFileReader)
    {
    }

    public function load(array $brandTagMap): array
    {
        $recruitments = [];

        $recruitmentsData = $this->jsonFileReader->read(__DIR__ . '/export/recruitments.json');

        foreach ($recruitmentsData as $recruitment) {
            $recruitments[$recruitment['id']] = new Recruitment(
                $recruitment['id'],
                $recruitment['brand'],
                $recruitment['is_closed'],
                DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $recruitment['updated_at']),
                $brandTagMap[$recruitment['brand']] ?? []
            );
        }

        return $recruitments;
    }
}

class TagLoader {
    public function __construct(private JsonFileReader $jsonFileReader)
    {
    }

    public function load(): array
    {
        $brandTagsMap = [];

        $mappingsData = $this->jsonFileReader->read(__DIR__ . '/mapping.json');

        foreach ($mappingsData as $brand => $tags) {
            foreach ($tags as $tag) {
                $brandTagsMap[$brand][] = new Tag($tag);
            }
        }

        return $brandTagsMap;
    }
}

class MembershipLoader {
    public function __construct(private JsonFileReader $jsonFileReader)
    {
    }

    public function load(array $recruitments): array
    {
        $memberships = [];

        $membershipsData = $this->jsonFileReader->read(__DIR__ . '/export/memberships.json');

        foreach ($membershipsData as $id => $recruitmentIds) {
            $membershipRecruitments = [];
            foreach ($recruitmentIds as $recruitmentId) {
                $membershipRecruitments[] = $recruitments[$recruitmentId]
                    ?? throw new RuntimeException(
                        "Recruitment {$recruitmentId} not found"
                    );
            }

            $memberships[] = new Membership($id, $membershipRecruitments);
        }

        return $memberships;
    }
}

readonly class Recruitment {
    public function __construct(
        public int $id,
        public string $brand,
        public bool $isClosed,
        public DateTimeImmutable $updatedAt,
        public array $tags = []
    )
    {
    }
}

readonly class Tag {
    public function __construct(
        public string $name
    )
    {
    }
}

readonly class Membership {
    public function __construct(
        public int $id,
        public array $recruitments,
    )
    {
    }
}
