
# SQL Error Masker

A framework-agnostic PHP library to detect, classify, and **mask** SQL error messages protecting logs and users while preserving useful debug context.

## Features
- Identify SQLSTATE & vendor-specific error codes
- Classify errors by type (schema/data/query/connection) and severity
- Mask sensitive data (table names, column values, UUIDs, file paths)
- Boolean helpers (`isDuplicateData`, `isResourceNotFound`, etc.)
- Multiple log levels (`debug`, `info`, `warning`, `error`)
 - Works in **any PHP project** and integrates easily with **Laravel** or **CodeIgniter**


## Installation

```bash
composer require ilham/sql-error-masker
````


## Usage

### 1. PHP Native Example

```php
<?php

require 'vendor/autoload.php';

use Ilham\SqlErrorMasker\SqlErrorMasker;

$masker = new SqlErrorMasker();

$errorMessage = "SQLSTATE[42S02]: Base table or view not found: 1146 Table `users` doesn't exist";

// Identify the error
$info = $masker->identify($errorMessage);
print_r($info);
/*
Array
(
    [type] => resource_not_found
    [code] => 42S02
    [description] => Resource not found
    [category] => schema
    [severity] => high
)
*/

// Mask for safe logging
echo $masker->mask($errorMessage, SqlErrorMasker::LOG_LEVEL_WARNING);
// Output: SQLSTATE[42S02]: Base table or view not found

// User-friendly message
echo $masker->userMessage($errorMessage);
// Output: The requested resource could not be found.

// Boolean check
if ($masker->isResourceNotFound($errorMessage)) {
    echo "Handle missing table/resource logic here.";
}
```

---

### 2. Laravel Example (via Facade)

Add this **Facade** in your Laravel app:

```php
<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class SqlErrorMasker extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Ilham\SqlErrorMasker\SqlErrorMasker::class;
    }
}
```

**Usage in Laravel Controller:**

```php
use App\Facades\SqlErrorMasker;

public function store()
{
    try {
        // Some DB operation...
    } catch (\Throwable $e) {
        \Log::error(SqlErrorMasker::mask($e->getMessage(), 'error'));
        return response()->json([
            'message' => SqlErrorMasker::userMessage($e->getMessage())
        ], 500);
    }
}
```

---

### 3. CodeIgniter Example

```php
use Ilham\\SqlErrorMasker\\SqlErrorMasker;

class UserController extends \\CodeIgniter\\Controller
{
    public function store()
    {
        $masker = new SqlErrorMasker();
        try {
            // Some DB operation...
        } catch (\\Throwable $e) {
            log_message('error', $masker->mask($e->getMessage(), SqlErrorMasker::LOG_LEVEL_ERROR));
            return $this->response->setStatusCode(500)
                ->setBody($masker->userMessage($e->getMessage()));
        }
    }
}
```

---

## Boolean Helper Methods

```php
$masker->isDuplicateData($msg);      // true if duplicate error
$masker->isResourceNotFound($msg);   // true if resource not found
$masker->isQueryError($msg);         // true if syntax/query error
$masker->isConnectionError($msg);    // true if DB connection error
```

---

## Contributing

Contributions are welcome!

1. **Fork** the repo
2. **Create** your feature branch:

   ```bash
   git checkout -b feature/my-new-feature
   ```
3. **Install dependencies**:

   ```bash
   composer install
   ```
4. **Run tests**:

   ```bash
   composer test
   ```
5. **Commit and push** your branch
6. **Open a Pull Request**

---

## License

MIT License © 2025 [Kodikas](https://github.com/kodikas-studio-id)
