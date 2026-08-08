# 🔍 Alif Query Filter

A lightweight, clean, and reusable query filtering library for Laravel Eloquent models — built to help you keep your controllers clean and your queries dynamic.

---

## ✨ Features

- Chainable, dynamic Eloquent filtering based on request input
- Filters as separate classes — fully testable and reusable
- Built-in `eq`/`ne`/`gt`/`gte`/`lt`/`lte` operations, search, sort, joins and soft-delete handling
- Auto-generated validation rules for your filter `FormRequest`s
- No hardcoded column names — every default column, search/sort field, join and
  validation rule is driven by the publishable config
- Works out-of-the-box with Laravel

---

## 📦 Requirements

- PHP >= 8.2
- Laravel ^11.0 || ^12.0 || ^13.0

---

## 🚀 Installation

```bash
composer require alifcoder/query-filter
```

Publish the config (optional, but required if you want to override any default):

```bash
php artisan vendor:publish --tag=query-filter
```

To remove the published config/lang files later:

```bash
php artisan query-filter:uninstall
```

---

## ⚙️ Configuration

Everything that used to be hardcoded inside the base filter classes now lives in
`config/query-filter.php`, so you never need to touch the package internals to
adapt it to your schema.

```php
return [

    // Validation rules for the built-in query string parameters
    // (sort, limit, search, pagination, soft-delete toggles, ...).
    'default_filters' => [ /* ... */ ],

    // Logical field name => actual database column name, used by
    // BaseEBFilter's prefix(), index(), isActive(), deletedAt(), createdAt(),
    // updatedAt(), createdBy(), updatedBy() and by the default search/sort
    // fields below. Override a value if your table uses a different column
    // name — no need to override the base class.
    'columns' => [
        'prefix'        => 'prefix',
        'index'         => 'index',
        'is_active'     => 'is_active',
        'deleted_at'    => 'deleted_at',
        'created_at'    => 'created_at',
        'updated_at'    => 'updated_at',
        'created_by_id' => 'created_by_id',
        'updated_by_id' => 'updated_by_id',
        // ...
    ],

    // Field keys automatically registered as searchable/sortable for every
    // filter, on top of whatever searchFields()/sortFields() return.
    'default_search_fields' => ['prefix-index', 'prefix', 'index', /* ... */],
    'default_sort_fields'   => ['prefix-index', 'index', 'prefix', /* ... */],

    // Relations resolved by checkJoin() and used to build the
    // "created_by.name" / "updated_by.name" default fields above.
    'default_joins' => [
        'created_by' => [
            'table'        => 'users as created_by',
            'first'        => 'created_by.id',
            'second'       => '{table}.created_by_id',
            'alias'        => 'created_by',
            'name_columns' => ['first_name', 'last_name'],
        ],
        // ...
    ],

    // Extra ValidationRuleDTO definitions merged into every FormRequest that
    // uses FilterPrepareForRequestTrait::getFields().
    'default_validation_fields' => [
        ['field' => 'prefix', 'rules' => ['string'], 'operations' => ['eq', 'ne']],
        ['field' => 'index', 'rules' => ['string'], 'operations' => 'all'],
        // ...
    ],
];
```

Set a `columns` entry, `default_search_fields`/`default_sort_fields` entry, or
`default_joins` entry to remove it entirely if a default doesn't apply to your
table — the base class only ever registers what's present in config.

---

## 🧱 Usage

### 1. Create a filter

```php
// app/Filters/PostFilter.php
namespace App\Filters;

use Alif\QueryFilter\Abstracts\BaseEBFilter;
use Alif\QueryFilter\Interfaces\Searchable;
use Illuminate\Database\Eloquent\Builder;

class PostFilter extends BaseEBFilter implements Searchable
{
    protected string $table = 'posts';

    protected function getCallback(): array
    {
        return [
            'is_active'     => [$this, 'isActive'],
            'created_at'    => [$this, 'createdAt'],
            'created_by_id' => [$this, 'createdBy'],
            'search'        => [$this, 'search'],
            'sort'          => [$this, 'sort'],
            'limit'         => [$this, 'limit'],
        ];
    }

    protected function sortFields(): array
    {
        return [
            'title' => $this->table . '.title',
        ];
    }

    protected function joinTables(): array
    {
        return [];
    }

    public function searchFields(string $search): array
    {
        return [
            'title' => $this->table . '.title',
        ];
    }
}
```

### 2. Apply it to a model

```php
use Alif\QueryFilter\Traits\Filterable;

class Post extends Model
{
    use Filterable;
}
```

```php
use App\Filters\PostFilter;

$posts = Post::filter(new PostFilter($request->validated()))->get();
```

### 3. (Optional) Auto-generate validation rules

```php
use Alif\QueryFilter\DTO\ValidationRuleDTO;
use Alif\QueryFilter\Traits\FilterPrepareForRequestTrait;
use Illuminate\Foundation\Http\FormRequest;

class PostIndexRequest extends FormRequest
{
    use FilterPrepareForRequestTrait;

    public function fields(): array
    {
        return [
            new ValidationRuleDTO('title', ['string']),
        ];
    }
}
```

`rules()` will combine your `fields()` with `config('query-filter.default_validation_fields')`
and the built-in `default_filters` rules automatically.

---

## 🌐 Example Query

```http
GET /posts?is_active=1&sort=-created_at&search[title]=hello
```

---

## 🧩 Folder Structure

```
src/
├── Abstracts/
│   ├── BaseEBFilter.php      # Eloquent Builder filter base class
│   └── BaseQBFilter.php      # Query Builder filter base class
├── Console/
│   └── UninstallQueryFilterCommand.php
├── DTO/
├── Enums/
├── Interfaces/
├── Macros/
├── Traits/
│   ├── Filterable.php
│   └── FilterPrepareForRequestTrait.php
└── QueryFilterServiceProvider.php
config/
└── query-filter.php
```

---

## 📜 License

MIT © [Shukhratjon Yuldashev](https://t.me/alif_coder)
