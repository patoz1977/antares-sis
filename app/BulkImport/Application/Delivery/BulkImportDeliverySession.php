<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Delivery;

use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\SessionManager;
use InvalidArgumentException;

final readonly class BulkImportDeliverySession
{
    private const PREVIEW_KEY = '_e015_bulk_import_preview';
    private const RESULT_KEY = '_e015_bulk_import_result';
    private const REPORT_KEY = '_e015_bulk_import_report';
    private const TTL_SECONDS = 900;
    private const MAXIMUM_ISSUES = 100;
    private const MAXIMUM_FAMILIES = 1000;

    public function __construct(
        private SessionManager $session,
        private Clock $clock,
    ) {
    }

    public function invalidateForNewPreview(): void
    {
        $this->session->remove(self::PREVIEW_KEY);
        $this->session->remove(self::RESULT_KEY);
        $this->session->remove(self::REPORT_KEY);
    }

    /** @param array{families: int, new: int, already_exists: int, conflicts: int, issues: int} $counts */
    public function issuePreview(int $actorId, string $digest, array $counts): string
    {
        $this->assertActorAndDigest($actorId, $digest);
        $token = bin2hex(random_bytes(32));
        $issuedAt = $this->nowTimestamp();
        $this->session->put(self::PREVIEW_KEY, [
            'token' => $token,
            'actor_id' => $actorId,
            'digest' => $digest,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + self::TTL_SECONDS,
            'counts' => $this->normalizeCounts($counts),
        ]);

        return $token;
    }

    public function consumePreview(string $token, int $actorId): ?BulkImportPreviewGrant
    {
        $state = $this->session->get(self::PREVIEW_KEY);
        if (!is_array($state)
            || !isset($state['token'])
            || !is_string($state['token'])
            || strlen($state['token']) !== 64
            || strlen($token) !== 64
            || !hash_equals($state['token'], $token)) {
            return null;
        }

        $this->session->remove(self::PREVIEW_KEY);

        if (($state['actor_id'] ?? null) !== $actorId
            || !isset($state['expires_at'])
            || !is_int($state['expires_at'])
            || $this->nowTimestamp() >= $state['expires_at']
            || !isset($state['digest'])
            || !is_string($state['digest'])
            || preg_match('/\A[a-f0-9]{64}\z/', $state['digest']) !== 1) {
            return null;
        }

        return new BulkImportPreviewGrant($state['digest']);
    }

    /** @param list<array{category: string, sheet: string, row: int, field: ?string, message: string}> $issues */
    public function storeReport(int $actorId, array $issues): void
    {
        $this->assertActor($actorId);
        $this->session->put(self::REPORT_KEY, [
            'actor_id' => $actorId,
            'expires_at' => $this->nowTimestamp() + self::TTL_SECONDS,
            'issues' => array_slice($issues, 0, self::MAXIMUM_ISSUES),
        ]);
    }

    /**
     * @param list<array{family_code: string, classification: string, label: string, message: string}> $families
     * @param list<array{category: string, sheet: string, row: int, field: ?string, message: string}> $issues
     */
    public function storeResult(int $actorId, array $families, array $issues): void
    {
        $this->assertActor($actorId);
        $expiresAt = $this->nowTimestamp() + self::TTL_SECONDS;
        $safeFamilies = array_slice($families, 0, self::MAXIMUM_FAMILIES);
        $safeIssues = array_slice($issues, 0, self::MAXIMUM_ISSUES);
        $this->session->put(self::RESULT_KEY, [
            'actor_id' => $actorId,
            'expires_at' => $expiresAt,
            'families' => $safeFamilies,
            'issues' => $safeIssues,
        ]);
        $this->session->put(self::REPORT_KEY, [
            'actor_id' => $actorId,
            'expires_at' => $expiresAt,
            'issues' => $safeIssues,
        ]);
    }

    /** @return array{families: list<array<string, string>>, issues: list<array<string, int|string|null>>}|null */
    public function pullResult(int $actorId): ?array
    {
        $state = $this->session->pull(self::RESULT_KEY);
        if (!$this->isCurrentActorState($state, $actorId)) {
            return null;
        }

        return [
            'families' => is_array($state['families'] ?? null) ? $state['families'] : [],
            'issues' => is_array($state['issues'] ?? null) ? $state['issues'] : [],
        ];
    }

    /** @return list<array{category: string, sheet: string, row: int, field: ?string, message: string}>|null */
    public function report(int $actorId): ?array
    {
        $state = $this->session->get(self::REPORT_KEY);
        if (!$this->isCurrentActorState($state, $actorId)) {
            $this->session->remove(self::REPORT_KEY);

            return null;
        }

        return is_array($state['issues'] ?? null) ? $state['issues'] : [];
    }

    /** @param mixed $state */
    private function isCurrentActorState(mixed $state, int $actorId): bool
    {
        return is_array($state)
            && ($state['actor_id'] ?? null) === $actorId
            && isset($state['expires_at'])
            && is_int($state['expires_at'])
            && $this->nowTimestamp() < $state['expires_at'];
    }

    /** @param array<string, int> $counts @return array<string, int> */
    private function normalizeCounts(array $counts): array
    {
        $normalized = [];
        foreach (['families', 'new', 'already_exists', 'conflicts', 'issues'] as $key) {
            $value = $counts[$key] ?? null;
            if (!is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Bulk Import preview counts are invalid.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function assertActorAndDigest(int $actorId, string $digest): void
    {
        $this->assertActor($actorId);
        if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
            throw new InvalidArgumentException('Bulk Import digest is invalid.');
        }
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Bulk Import actor is invalid.');
        }
    }

    private function nowTimestamp(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
