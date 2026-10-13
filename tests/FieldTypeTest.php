<?php

namespace Alif\QueryFilter\Tests; // Keep test fixtures separate from production classes.

use Alif\QueryFilter\Enums\FieldType; // Use FieldType in this test fixture.
use Alif\QueryFilter\Field; // Use Field in this test fixture.
use PHPUnit\Framework\TestCase; // Use TestCase in this test fixture.

class FieldTypeTest extends TestCase // Group regression coverage for the field type.
{
    public function test_new_field_has_no_type(): void // Verify the default.
    {
        $this->assertNull(Field::make('name')->type); // Default is null.
    }

    public function test_type_returns_a_copy(): void // Verify copy semantics.
    {
        $original = Field::make('name'); // Shared definition.
        $typed = $original->type(FieldType::String); // Specialized copy.

        $this->assertNull($original->type); // Original is unchanged.
        $this->assertSame(FieldType::String, $typed->type); // Copy carries the type.
        $this->assertNull($typed->type(null)->type); // Type can be reset.
    }

    public function test_type_survives_other_fluent_calls(): void // Verify order independence.
    {
        $this->assertSame(FieldType::Date, Field::make('d')->type(FieldType::Date)->sortable(false)->searchable()->type); // Type first.
        $this->assertSame(FieldType::Date, Field::make('d')->sortable(false)->type(FieldType::Date)->filterable()->type); // Type in the middle.
    }

    public function test_type_does_not_change_the_definition_otherwise(): void // Verify metadata only.
    {
        $plain = Field::make('d'); // Untyped.
        $typed = $plain->type(FieldType::Integer); // Typed.

        foreach (['path', 'allowedOperators', 'canFilter', 'canSearch', 'canSort', 'usesExists', 'validationRules'] as $property) { // Compare every query-relevant property.
            $this->assertSame($plain->{$property}, $typed->{$property}, $property); // Nothing else differs.
        }
    }
}
