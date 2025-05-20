# 🔍 Alif Query Filter

A lightweight, clean, and reusable query filtering library for Laravel Eloquent models — built to help you keep your controllers clean and your queries dynamic.

---

## ✨ Features

- Chainable, dynamic Eloquent filtering based on request input
- Filters as separate classes — fully testable and reusable
- Simple base class for filters
- Works out-of-the-box with Laravel
- Publishable config

---

## 📦 Requirements

- PHP >= 8.1
- Laravel ^11.0 || ^12.0

---

## 🚀 Installation

```bash
composer require alifcoder/query-filter
```

---

## ⚙️ Configuration

Publish the config (optional):

```bash
php artisan vendor:publish --tag=query-filter-config
```

You can configure default filter namespace or behavior.

---

## 🧱 Usage

### 1. Create a Filter

```bash
php artisan make:filter StatusFilter
```

### 2. Define the logic

```php
// app/Filters/StatusFilter.php
use Alif\QueryFilter\Contracts\Filter;

class StatusFilter implements Filter
{
    public function handle(Builder $query, $value, Closure $next)
    {
        if ($value) {
            $query->where('status', $value);
        }

        return $next($query);
    }
}
```

### 3. Use in your model or controller

```php
use Alif\QueryFilter\QueryFilter;

$filtered = Post::filter(QueryFilter::make([
    'status' => request('status'),
    'sort' => request('sort'),
]))->get();
```

---

## 🌐 Example Query

```http
GET /posts?status=published&sort=created_at
```

---

## 🧩 Folder Structure

```
src/
├── Contracts/
│   └── Filter.php
├── Filters/
│   └── QueryFilter.php
├── QueryFilterServiceProvider.php
config/
└── query-filter.php
```

---

## 📜 License

MIT © [Shukhratjon Yuldashev](https://t.me/alif_coder)
