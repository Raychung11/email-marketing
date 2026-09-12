<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rule-string validator.
 *
 *   $data = (new Validator($request->all(), [
 *       'email'    => 'required|email|max:255',
 *       'password' => 'required|min:12|confirmed',
 *       'country'  => 'required|in:AU,US',
 *   ]))->validate();
 *
 * validate() returns only the validated keys, which keeps mass-assignment
 * mistakes out of the persistence layer.
 */
final class Validator
{
    /** @var array<string,array<int,string>> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    /** @var array<string,callable(mixed,array<string,mixed>):(bool|string)> */
    private array $customRules = [];

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $messages
     * @param array<string,string> $attributes
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly array $attributes = [],
    ) {
    }

    /** @param callable(mixed,array<string,mixed>):(bool|string) $callback */
    public function addRule(string $name, callable $callback): self
    {
        $this->customRules[$name] = $callback;

        return $this;
    }

    public function passes(): bool
    {
        $this->errors    = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $rules = explode('|', $ruleString);

            $nullable = in_array('nullable', $rules, true);
            $required = in_array('required', $rules, true);

            if ($required && $this->isEmpty($value)) {
                $this->addError($field, 'required', 'The :attribute field is required.');
                continue;
            }

            if ($this->isEmpty($value) && ($nullable || !$required)) {
                if (array_key_exists($field, $this->data)) {
                    $this->validated[$field] = $value;
                }
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable' || $rule === '') {
                    continue;
                }

                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

                if (!$this->check((string) $name, $value, $parameter, $field)) {
                    break;
                }
            }

            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }

        return $this->errors === [];
    }

    /**
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function validate(): array
    {
        if (!$this->passes()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    /** @return array<string,array<int,string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function check(string $rule, mixed $value, ?string $parameter, string $field): bool
    {
        if (isset($this->customRules[$rule])) {
            $result = ($this->customRules[$rule])($value, $this->data);

            if ($result === true) {
                return true;
            }

            $this->addError($field, $rule, is_string($result) ? $result : 'The :attribute field is invalid.');

            return false;
        }

        return match ($rule) {
            'email' => $this->assert(
                $field,
                $rule,
                is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'The :attribute must be a valid email address.'
            ),
            'url' => $this->assert(
                $field,
                $rule,
                is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
                'The :attribute must be a valid URL.'
            ),
            'numeric' => $this->assert($field, $rule, is_numeric($value), 'The :attribute must be a number.'),
            'integer' => $this->assert(
                $field,
                $rule,
                filter_var($value, FILTER_VALIDATE_INT) !== false,
                'The :attribute must be an integer.'
            ),
            'boolean' => $this->assert(
                $field,
                $rule,
                in_array($value, [true, false, 0, 1, '0', '1', 'on', 'off', 'yes', 'no'], true),
                'The :attribute must be true or false.'
            ),
            'array' => $this->assert($field, $rule, is_array($value), 'The :attribute must be a list.'),
            'string' => $this->assert($field, $rule, is_string($value), 'The :attribute must be text.'),
            'min' => $this->assert(
                $field,
                $rule,
                $this->size($value) >= (float) $parameter,
                is_numeric($value)
                    ? 'The :attribute must be at least ' . $parameter . '.'
                    : 'The :attribute must be at least ' . $parameter . ' characters.'
            ),
            'max' => $this->assert(
                $field,
                $rule,
                $this->size($value) <= (float) $parameter,
                is_numeric($value)
                    ? 'The :attribute may not be greater than ' . $parameter . '.'
                    : 'The :attribute may not be longer than ' . $parameter . ' characters.'
            ),
            'in' => $this->assert(
                $field,
                $rule,
                in_array((string) $value, explode(',', (string) $parameter), true),
                'The selected :attribute is not valid.'
            ),
            'not_in' => $this->assert(
                $field,
                $rule,
                !in_array((string) $value, explode(',', (string) $parameter), true),
                'The selected :attribute is not valid.'
            ),
            'date' => $this->assert($field, $rule, $this->isDate($value), 'The :attribute must be a valid date.'),
            'regex' => $this->assert(
                $field,
                $rule,
                is_string($value) && preg_match((string) $parameter, $value) === 1,
                'The :attribute format is invalid.'
            ),
            'alpha_dash' => $this->assert(
                $field,
                $rule,
                is_string($value) && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1,
                'The :attribute may only contain letters, numbers, dashes and underscores.'
            ),
            'confirmed' => $this->assert(
                $field,
                $rule,
                isset($this->data[$field . '_confirmation'])
                    && hash_equals((string) $this->data[$field . '_confirmation'], (string) $value),
                'The :attribute confirmation does not match.'
            ),
            'same' => $this->assert(
                $field,
                $rule,
                isset($this->data[(string) $parameter])
                    && (string) $this->data[(string) $parameter] === (string) $value,
                'The :attribute must match ' . str_replace('_', ' ', (string) $parameter) . '.'
            ),
            'timezone' => $this->assert(
                $field,
                $rule,
                is_string($value) && in_array($value, timezone_identifiers_list(), true),
                'The :attribute must be a valid timezone.'
            ),
            default => true,
        };
    }

    private function assert(string $field, string $rule, bool $condition, string $message): bool
    {
        if ($condition) {
            return true;
        }

        $this->addError($field, $rule, $message);

        return false;
    }

    private function addError(string $field, string $rule, string $fallback): void
    {
        $message = $this->messages[$field . '.' . $rule]
            ?? $this->messages[$field]
            ?? $fallback;

        $attribute = $this->attributes[$field] ?? str_replace('_', ' ', $field);

        $this->errors[$field][] = str_replace(':attribute', $attribute, $message);
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function size(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            return (float) count($value);
        }

        return (float) mb_strlen((string) $value);
    }

    private function isDate(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        return strtotime($value) !== false;
    }
}
