<?php

declare(strict_types=1);

namespace App\Services;

use App\Compliance\ComplianceService;
use App\Compliance\ReasonCode;
use App\Core\Config;
use App\Database\QueryBuilder;
use App\Repositories\SegmentRepository;
use App\Support\TenantContext;

final class SegmentService
{
    public function __construct(
        private readonly SegmentRepository $segments,
        private readonly SegmentCompiler $compiler,
        private readonly ComplianceService $compliance,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->segments->all();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->segments->findWithDefinition($id);
    }

    /**
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $attributes
     */
    public function create(string $name, array $definition, array $attributes = []): int
    {
        $validated = $this->compiler->validate($definition);

        $id = $this->segments->create($name, $validated, $attributes);

        $this->audit->log('segment_created', 'segment', $id, null, [
            'name'        => $name,
            'description' => $this->compiler->describe($validated),
            'created_via' => $attributes['created_via'] ?? 'manual',
        ]);

        return $id;
    }

    /**
     * @param array<string,mixed>      $attributes
     * @param array<string,mixed>|null $definition
     */
    public function update(int $id, array $attributes, ?array $definition = null): void
    {
        $before    = $this->segments->findWithDefinition($id);
        $validated = $definition === null ? null : $this->compiler->validate($definition);

        $this->segments->update($id, $attributes, $validated);

        $this->audit->log('segment_updated', 'segment', $id, $before, $attributes);
    }

    public function delete(int $id): void
    {
        $this->segments->softDelete($id);
        $this->audit->log('segment_deleted', 'segment', $id);
    }

    /** @param array<string,mixed> $definition */
    public function query(array $definition): QueryBuilder
    {
        return $this->compiler->compile($definition);
    }

    /**
     * Audience preview.
     *
     * This is what the UI shows before a campaign is created, and the numbers
     * are deliberately broken out rather than reported as one total: "1,482
     * contacts, 1,327 eligible, 43 suppressed, 112 without consent" tells the
     * user something actionable, where a single number hides the problem.
     *
     * @param array<string,mixed> $definition
     * @return array{total:int,eligible:int,suppressed:int,no_consent:int,invalid:int,blocked:int,sample:array<int,array<string,mixed>>}
     */
    public function preview(array $definition, int $sampleSize = 10): array
    {
        $query = $this->compiler->compile($definition);

        $total = (clone $query)->count();

        $organisation = $this->tenant->organisation();

        $counts = ['eligible' => 0, 'suppressed' => 0, 'no_consent' => 0, 'invalid' => 0, 'blocked' => 0];
        $sample = [];

        $chunkSize = (int) $this->config->get('database.chunk_size', 1000);
        $lastId    = 0;

        // Chunked so previewing a large segment does not hold the whole audience
        // in memory.
        do {
            $chunk = (clone $query)
                ->where('contacts.id', '>', $lastId)
                ->orderBy('contacts.id')
                ->limit($chunkSize)
                ->get();

            if ($chunk === []) {
                break;
            }

            $decisions = $this->compliance->evaluateBatch($organisation, $chunk);

            foreach ($chunk as $contact) {
                $lastId   = (int) $contact['id'];
                $decision = $decisions[(int) $contact['id']] ?? null;

                if ($decision === null) {
                    continue;
                }

                if ($decision->allowed) {
                    $counts['eligible']++;

                    if (count($sample) < $sampleSize) {
                        $sample[] = $contact;
                    }

                    continue;
                }

                $counts[$this->bucketFor($decision->reason)]++;
            }
        } while (count($chunk) === $chunkSize);

        return [
            'total'      => $total,
            'eligible'   => $counts['eligible'],
            'suppressed' => $counts['suppressed'],
            'no_consent' => $counts['no_consent'],
            'invalid'    => $counts['invalid'],
            'blocked'    => $counts['blocked'],
            'sample'     => $sample,
        ];
    }

    /**
     * Refresh and store the cached counts for a segment. Called by the scheduler,
     * and on demand when a cached count is stale.
     *
     * @return array{total:int,eligible:int}
     */
    public function refreshCounts(int $segmentId): array
    {
        $segment = $this->segments->findWithDefinition($segmentId);

        if ($segment === null) {
            return ['total' => 0, 'eligible' => 0];
        }

        $preview = $this->preview($segment['definition'], 0);

        $this->segments->storeCounts($segmentId, $preview['total'], $preview['eligible']);

        return ['total' => $preview['total'], 'eligible' => $preview['eligible']];
    }

    /** @param array<string,mixed> $segment */
    public function countsAreStale(array $segment): bool
    {
        return $this->segments->countsAreStale(
            $segment,
            (int) $this->config->get('segments.count_cache_ttl', 900)
        );
    }

    /** @param array<string,mixed> $definition */
    public function describe(array $definition): string
    {
        return $this->compiler->describe($definition);
    }

    /** @param array<string,mixed> $definition */
    public function validate(array $definition): array
    {
        return $this->compiler->validate($definition);
    }

    /** @return array<string,array<string,mixed>> */
    public function availableFields(): array
    {
        return $this->compiler->availableFields();
    }

    private function bucketFor(string $reason): string
    {
        return ReasonCode::bucket($reason);
    }
}
