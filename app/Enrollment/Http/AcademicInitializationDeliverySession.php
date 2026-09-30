<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\SessionManager;
use InvalidArgumentException;

final readonly class AcademicInitializationDeliverySession
{
    private const PREVIEW_KEY = '_academic_initialization_preview';
    private const RESULT_KEY = '_academic_initialization_result';
    private const TTL_SECONDS = 900;
    private const MAXIMUM_ISSUES = 100;

    public function __construct(
        private SessionManager $session,
        private Clock $clock,
    ) {
    }

    public function invalidateForNewPreview(): void
    {
        $this->session->remove(self::PREVIEW_KEY);
        $this->session->remove(self::RESULT_KEY);
    }

    public function issuePreview(
        int $actorId,
        string $fileDigest,
        string $stateDigest,
        string $academicPeriodCode,
    ): string {
        $this->assertActor($actorId);
        $this->assertDigest($fileDigest);
        $this->assertDigest($stateDigest);
        if (trim($academicPeriodCode) === '') {
            throw new InvalidArgumentException('Academic initialization period code is invalid.');
        }

        $token = bin2hex(random_bytes(32));
        $issuedAt = $this->clock->now()->getTimestamp();
        $this->session->put(self::PREVIEW_KEY, [
            'token' => $token,
            'actor_id' => $actorId,
            'file_digest' => $fileDigest,
            'state_digest' => $stateDigest,
            'academic_period_code' => trim($academicPeriodCode),
            'expires_at' => $issuedAt + self::TTL_SECONDS,
        ]);

        return $token;
    }

    public function consumePreview(string $token, int $actorId): ?AcademicInitializationPreviewGrant
    {
        $state = $this->session->get(self::PREVIEW_KEY);
        if (!is_array($state)
            || !is_string($state['token'] ?? null)
            || strlen($token) !== 64
            || !hash_equals($state['token'], $token)
        ) {
            return null;
        }
        $this->session->remove(self::PREVIEW_KEY);

        if (($state['actor_id'] ?? null) !== $actorId
            || !is_int($state['expires_at'] ?? null)
            || $this->clock->now()->getTimestamp() >= $state['expires_at']
            || !$this->validDigest($state['file_digest'] ?? null)
            || !$this->validDigest($state['state_digest'] ?? null)
            || !is_string($state['academic_period_code'] ?? null)
            || trim($state['academic_period_code']) === ''
        ) {
            return null;
        }

        return new AcademicInitializationPreviewGrant(
            $state['file_digest'],
            $state['state_digest'],
            $state['academic_period_code'],
        );
    }

    /**
     * @param array{rows: int, created_drafts: int, placements_set: int, already_correct: int}|null $counts
     * @param list<array{category: string, row: int, field: ?string, message: string}> $issues
     */
    public function storeResult(int $actorId, ?array $counts, array $issues): void
    {
        $this->assertActor($actorId);
        $this->session->put(self::RESULT_KEY, [
            'actor_id' => $actorId,
            'expires_at' => $this->clock->now()->getTimestamp() + self::TTL_SECONDS,
            'counts' => $counts,
            'issues' => array_slice($issues, 0, self::MAXIMUM_ISSUES),
        ]);
    }

    /** @return array{counts: ?array<string, int>, issues: list<array<string, int|string|null>>}|null */
    public function pullResult(int $actorId): ?array
    {
        $state = $this->session->pull(self::RESULT_KEY);
        if (!is_array($state)
            || ($state['actor_id'] ?? null) !== $actorId
            || !is_int($state['expires_at'] ?? null)
            || $this->clock->now()->getTimestamp() >= $state['expires_at']
        ) {
            return null;
        }

        return [
            'counts' => is_array($state['counts'] ?? null) ? $state['counts'] : null,
            'issues' => is_array($state['issues'] ?? null) ? $state['issues'] : [],
        ];
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Academic initialization actor is invalid.');
        }
    }

    private function assertDigest(string $digest): void
    {
        if (!$this->validDigest($digest)) {
            throw new InvalidArgumentException('Academic initialization digest is invalid.');
        }
    }

    private function validDigest(mixed $digest): bool
    {
        return is_string($digest) && preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1;
    }
}
