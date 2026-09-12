<?php

declare(strict_types=1);

namespace App\Repositories;

final class CustomFieldRepository extends Repository
{
    protected function table(): string
    {
        return 'custom_field_definitions';
    }

    /** @return array<int,array<string,mixed>> */
    public function definitions(string $entityType = 'contact'): array
    {
        $rows = $this->scoped()
            ->where('entity_type', '=', $entityType)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        foreach ($rows as $index => $row) {
            $rows[$index]['options'] = $this->decodeJson($row['options'] ?? null) ?? [];
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $key, string $entityType = 'contact'): ?array
    {
        return $this->scoped()
            ->where('entity_type', '=', $entityType)
            ->where('key', '=', $key)
            ->first();
    }

    /** @param array<string,mixed> $attributes */
    public function createDefinition(array $attributes): int
    {
        if (isset($attributes['options'])) {
            $attributes['options'] = $this->encodeJson($attributes['options']);
        }

        return $this->scoped()->insert($this->withTimestamps($this->withTenant($attributes)));
    }

    /** @param array<string,mixed> $attributes */
    public function updateDefinition(int $id, array $attributes): int
    {
        if (array_key_exists('options', $attributes)) {
            $attributes['options'] = $this->encodeJson($attributes['options']);
        }

        return $this->scoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));
    }

    public function deleteDefinition(int $id): int
    {
        $this->connection->table('contact_custom_fields')
            ->where('organisation_id', '=', $this->organisationId())
            ->where('custom_field_definition_id', '=', $id)
            ->delete();

        return $this->scoped()->where('id', '=', $id)->delete();
    }

    /**
     * Values are written into the typed column matching the definition, so that
     * numeric and date segment rules compare numerically rather than
     * lexicographically.
     */
    public function setValue(int $contactId, int $definitionId, string $type, mixed $value): void
    {
        $columns = [
            'value_text'    => null,
            'value_number'  => null,
            'value_date'    => null,
            'value_boolean' => null,
            'value_json'    => null,
        ];

        switch ($type) {
            case 'number':
                $columns['value_number'] = $value === null || $value === '' ? null : (float) $value;
                break;
            case 'date':
                $columns['value_date'] = $value === null || $value === ''
                    ? null
                    : date('Y-m-d H:i:s', (int) strtotime((string) $value));
                break;
            case 'boolean':
                $columns['value_boolean'] = $value === null || $value === ''
                    ? null
                    : (in_array((string) $value, ['1', 'true', 'yes', 'on'], true) ? 1 : 0);
                break;
            case 'multi_select':
                $columns['value_json'] = $this->encodeJson(is_array($value) ? $value : [$value]);
                $columns['value_text'] = is_array($value) ? implode(', ', $value) : (string) $value;
                break;
            default:
                $columns['value_text'] = $value === null ? null : (string) $value;
        }

        $existing = $this->connection->table('contact_custom_fields')
            ->where('organisation_id', '=', $this->organisationId())
            ->where('contact_id', '=', $contactId)
            ->where('custom_field_definition_id', '=', $definitionId)
            ->first();

        if ($existing !== null) {
            $this->connection->table('contact_custom_fields')
                ->where('id', '=', (int) $existing['id'])
                ->update(array_merge($columns, ['updated_at' => $this->now()]));

            return;
        }

        $this->connection->table('contact_custom_fields')->insert(array_merge($columns, [
            'organisation_id'            => $this->organisationId(),
            'contact_id'                 => $contactId,
            'custom_field_definition_id' => $definitionId,
            'created_at'                 => $this->now(),
            'updated_at'                 => $this->now(),
        ]));
    }

    /** @return array<string,mixed> keyed by field key */
    public function valuesForContact(int $contactId): array
    {
        $rows = $this->connection->select(
            'SELECT d.key, d.label, d.type, v.value_text, v.value_number, v.value_date, v.value_boolean, v.value_json
             FROM contact_custom_fields v
             INNER JOIN custom_field_definitions d ON d.id = v.custom_field_definition_id
             WHERE v.organisation_id = ? AND v.contact_id = ?',
            [$this->organisationId(), $contactId]
        );

        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row['key']] = [
                'label' => $row['label'],
                'type'  => $row['type'],
                'value' => match ((string) $row['type']) {
                    'number'       => $row['value_number'],
                    'date'         => $row['value_date'],
                    'boolean'      => $row['value_boolean'] === null ? null : (bool) $row['value_boolean'],
                    'multi_select' => $this->decodeJson($row['value_json'] ?? null) ?? [],
                    default        => $row['value_text'],
                },
            ];
        }

        return $values;
    }
}
