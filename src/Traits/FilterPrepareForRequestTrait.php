<?php
/**
 * Created by Shukhratjon Yuldashev on 2025-05-20
 * Contact: https://t.me/alif_coder
 * Time: 2:29 PM
 */

namespace Alif\QueryFilter\Traits;

use Alif\QueryFilter\DTO\ValidationRuleDTO;
use Alif\QueryFilter\Enums\OperationEnum;

trait FilterPrepareForRequestTrait
{
    protected function prepareForValidation(): void
    {
        foreach ($this->all() as $p_key => $p_value) {
            if (in_array($p_key, $this->defaultExcepts())) {
                continue;
            }
            if (is_array($p_value)) {
                $new_values = array_map(function ($value) { return \Arr::wrap($value); }, $p_value);
                $this->merge([$p_key => $new_values]);
            } else {
                $this->merge([$p_key => \Arr::wrap($p_value)]);
            }
        }

        $this->beforeValidation();
    }

    protected function beforeValidation(): void
    {
    }

    private function defaultExcepts(): array
    {
        return array_merge(array_keys(config('query-filter.default_filters', [])), $this->exceptWrap());
    }

    protected function exceptWrap(): array
    {
        return [];
    }

    public abstract function fields(): array;

    public function rules(): array
    {
        $rules = config('query-filter.default_filters', []);

        /** @var ValidationRuleDTO $dto */
        foreach ($this->getFields() as $dto) {
            if (in_array($dto->field, $this->defaultExcepts())) {
                $rules = $rules + [$dto->field => $dto->rules];
                continue;
            }
            $rules = $rules + [
                            $dto->field       => ['array'],
                            '-' . $dto->field => ['array'],
                    ];

            /** @var OperationEnum $operation */
            foreach ($dto->operations as $operation) {
                $rules = $rules + [
                                $dto->field . '.' . $operation->value              => ['array'],
                                $dto->field . '.' . $operation->value . '.*'       => \Arr::wrap($dto->rules),
                                '-' . $dto->field . '.' . $operation->value        => ['array'],
                                '-' . $dto->field . '.' . $operation->value . '.*' => \Arr::wrap($dto->rules),
                        ];
            }
        }

        return $rules;
    }

    public function getFields(): array
    {
        return array_merge($this->fields(), $this->defaultValidationFields());
    }

    private function defaultValidationFields(): array
    {
        $fields = [];

        foreach (config('query-filter.default_validation_fields', []) as $definition) {
            $operations = $definition['operations'] ?? 'all';
            $operations = $operations === 'all'
                    ? OperationEnum::cases()
                    : array_map(fn (string|OperationEnum $operation) => $operation instanceof OperationEnum
                            ? $operation
                            : OperationEnum::from($operation), $operations);

            $fields[] = new ValidationRuleDTO($definition['field'], $definition['rules'], $operations);
        }

        return $fields;
    }
}