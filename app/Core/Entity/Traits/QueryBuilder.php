<?php

namespace App\Core\Entity\Traits;

use App\Core\Entity\EntityException;
use App\Core\Entity\EntityRelation;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ResultInterface;
use DateTimeInterface;

trait QueryBuilder
{
    /**
     * Tracks which relation joins have already been applied to the current query.
     * Prevents duplicate LEFT JOINs when the same relation is referenced more than once.
     */
    protected array $joinedRelations = [];

    /**
     * Supported SQL comparison operators
     */
    protected array $operators = [
        '='              => 1,
        '<'              => 1,
        '>'              => 1,
        '<='             => 1,
        '>='             => 1,
        '<>'             => 1,
        '!='             => 1,
        '<=>'            => 1,
        'like'           => 1,
        'like binary'    => 1,
        'not like'       => 1,
        'ilike'          => 1,
        '&'              => 1,
        '|'              => 1,
        '^'              => 1,
        '<<'             => 1,
        '>>'             => 1,
        '&~'             => 1,
        'rlike'          => 1,
        'not rlike'      => 1,
        'regexp'         => 1,
        'not regexp'     => 1,
        '~'              => 1,
        '~*'             => 1,
        '!~'             => 1,
        '!~*'            => 1,
        'similar to'     => 1,
        'not similar to' => 1,
        'not ilike'      => 1,
        '~~*'            => 1,
        '!~~*'           => 1,
    ];

    /**
     * Execute a COUNT(*) query with all current WHERE and JOIN conditions applied.
     * Pass $reset = false to keep conditions in place for a subsequent findAll().
     *
     *   $model->where('active', 1)->countAllResults();
     *   $model->where('active', 1)->countAllResults(false);
     */
    public function get(?int $limit = null, int $offset = 0): ResultInterface
    {
        return $this->builder->get($limit, $offset);
    }

    /**
     * Set the SELECT clause.
     *
     *   select('name, email')
     *   select(['name', 'email'])
     */
    public function select(string|array $columns = '*', ?bool $escape = null): static
    {
        $this->builder->select($columns, $escape);

        return $this;
    }

    /**
     * Add one or more columns to the SELECT clause.
     * Accepts individual strings, comma-separated expressions, or arrays.
     *
     *   addSelect('name', 'email')
     *   addSelect(['name', 'email'])
     *   addSelect('p.name AS product_name')
     */
    public function addSelect(string|array ...$columns): static
    {
        $flat = [];

        foreach ($columns as $col) {
            is_array($col) ? array_push($flat, ...$col) : ($flat[] = $col);
        }

        $this->builder->select(implode(', ', $flat));

        return $this;
    }

    /**
     * Add a raw SQL expression to the SELECT clause.
     * Use ? placeholders for safe value binding.
     *
     *   selectRaw('price * ? AS total', [1.2])
     *   selectRaw('COUNT(*) AS count')
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->builder->select($this->bindRaw($expression, $bindings), false);

        return $this;
    }

    /**
     * Add SELECT MAX(column) to the query, with an optional alias.
     *
     *   selectMax('price')
     *   selectMax('price', 'max_price')
     */
    public function selectMax(string $column, string $alias = ''): static
    {
        $this->builder->selectMax($column, $alias);

        return $this;
    }

    /**
     * Add SELECT MIN(column) to the query, with an optional alias.
     *
     *   selectMin('price')
     *   selectMin('price', 'min_price')
     */
    public function selectMin(string $column, string $alias = ''): static
    {
        $this->builder->selectMin($column, $alias);

        return $this;
    }

    /**
     * Add SELECT AVG(column) to the query, with an optional alias.
     *
     *   selectAvg('rating')
     *   selectAvg('rating', 'avg_rating')
     */
    public function selectAvg(string $column, string $alias = ''): static
    {
        $this->builder->selectAvg($column, $alias);

        return $this;
    }

    /**
     * Add SELECT SUM(column) to the query, with an optional alias.
     *
     *   selectSum('amount')
     *   selectSum('amount', 'total_amount')
     */
    public function selectSum(string $column, string $alias = ''): static
    {
        $this->builder->selectSum($column, $alias);

        return $this;
    }

    /**
     * Add SELECT COUNT(column) to the query, with an optional alias.
     *
     *   selectCount('id', 'total')
     */
    public function selectCount(string $column, string $alias = ''): static
    {
        $this->builder->selectCount($column, $alias);

        return $this;
    }

    /**
     * Add DISTINCT to the SELECT clause so duplicate rows are filtered out.
     */
    public function distinct(): static
    {
        $this->builder->distinct();

        return $this;
    }

    /**
     * Add an INNER JOIN — only rows with a match in both tables are returned.
     *
     *   join('contacts', 'users.id = contacts.user_id')
     *   join('contacts', 'users.id', '=', 'contacts.user_id')
     */
    public function join(string $table, string $first, string $operator = '=', ?string $second = null): static
    {
        $this->builder->join($table, $second !== null ? "{$first} {$operator} {$second}" : $first);

        return $this;
    }

    /**
     * Add a LEFT JOIN — all rows from the left table are returned; unmatched right-side columns are NULL.
     *
     *   leftJoin('profiles', 'users.id = profiles.user_id')
     */
    public function leftJoin(string $table, string $first, string $operator = '=', ?string $second = null): static
    {
        $this->builder->join($table, $second !== null ? "{$first} {$operator} {$second}" : $first, 'left');

        return $this;
    }

    /**
     * Add a RIGHT JOIN — all rows from the right table are returned; unmatched left-side columns are NULL.
     *
     *   rightJoin('orders', 'users.id = orders.user_id')
     */
    public function rightJoin(string $table, string $first, string $operator = '=', ?string $second = null): static
    {
        $this->builder->join($table, $second !== null ? "{$first} {$operator} {$second}" : $first, 'right');

        return $this;
    }

    /**
     * Add a CROSS JOIN — produces the Cartesian product of both tables.
     */
    public function crossJoin(string $table): static
    {
        $this->builder->join($table, '1=1', 'cross');

        return $this;
    }

    /**
     * INNER JOIN against a subquery. The callable receives the database connection and must return a BaseBuilder.
     *
     *   joinSub(fn($db) => $db->table('orders')->select('user_id, MAX(total) AS max_total')->groupBy('user_id'), 'o', 'users.id', '=', 'o.user_id')
     */
    public function joinSub(BaseBuilder|callable $query, string $as, string $first, string $operator = '=', ?string $second = null): static
    {
        $sql = $this->resolveSubquery($query);
        $this->builder->join("({$sql}) {$as}", $second !== null ? "{$first} {$operator} {$second}" : $first, 'inner', false);

        return $this;
    }

    /**
     * LEFT JOIN against a subquery.
     *
     *   leftJoinSub(fn($db) => $db->table('profiles')->select('user_id, avatar'), 'p', 'users.id', '=', 'p.user_id')
     */
    public function leftJoinSub(BaseBuilder|callable $query, string $as, string $first, string $operator = '=', ?string $second = null): static
    {
        $sql = $this->resolveSubquery($query);
        $this->builder->join("({$sql}) {$as}", $second !== null ? "{$first} {$operator} {$second}" : $first, 'left', false);

        return $this;
    }

    /**
     * RIGHT JOIN against a subquery.
     *
     *   rightJoinSub(fn($db) => $db->table('profiles')->select('user_id, avatar'), 'p', 'users.id', '=', 'p.user_id')
     */
    public function rightJoinSub(BaseBuilder|callable $query, string $as, string $first, string $operator = '=', ?string $second = null): static
    {
        $sql = $this->resolveSubquery($query);
        $this->builder->join("({$sql}) {$as}", $second !== null ? "{$first} {$operator} {$second}" : $first, 'right', false);

        return $this;
    }

    /**
     * Add a WHERE condition. Accepts several call forms:
     *
     *   where('active', 1)                               => WHERE active = 1
     *   where('votes', '>=', 100)                        => WHERE votes >= 100
     *   where(['status' => 'active', 'role' => 'admin']) => WHERE status = 'active' AND role = 'admin'
     *   where([['votes', '>=', 100], ['status', 'active']])
     *
     * Pass a closure to wrap conditions in parentheses:
     *   where(fn($q) => $q->where('x', 1)->orWhere('y', 2)) => WHERE (x = 1 OR y = 2)
     */
    public function where(string|array|callable $column, mixed $operator = null, mixed $value = null): static
    {
        return is_callable($column)
            ? $this->applyGroupedWhere('groupStart', 'where', $column, $operator, $value)
            : $this->applyWhere('where', $column, $operator, $value);
    }

    /**
     * Add an OR WHERE condition. Accepts the same call forms as where().
     *
     *   orWhere('role', 'admin')
     *   orWhere(fn($q) => $q->where('x', 1)->orWhere('y', 2)) => OR (x = 1 OR y = 2)
     */
    public function orWhere(string|array|callable $column, mixed $operator = null, mixed $value = null): static
    {
        return is_callable($column)
            ? $this->applyGroupedWhere('orGroupStart', 'orWhere', $column, $operator, $value)
            : $this->applyWhere('orWhere', $column, $operator, $value);
    }

    /**
     * Negate one or more conditions with AND NOT (...).
     *
     *   whereNot('banned', 1)                                   => WHERE NOT (banned = 1)
     *   whereNot(fn($q) => $q->where('x', 1)->orWhere('y', 2))  => WHERE NOT (x = 1 OR y = 2)
     */
    public function whereNot(string|array|callable $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->applyGroupedWhere('notGroupStart', 'where', $column, $operator, $value);
    }

    /**
     * Negate one or more conditions with OR NOT (...).
     *
     *   orWhereNot('banned', 1)  => OR NOT (banned = 1)
     */
    public function orWhereNot(string|array|callable $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->applyGroupedWhere('orNotGroupStart', 'where', $column, $operator, $value);
    }

    /**
     * Add a raw SQL WHERE clause. Use ? placeholders for safe value binding.
     *
     *   whereRaw('price > ? AND stock > 0', [10])
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->builder->where($this->bindRaw($sql, $bindings), null, false);

        return $this;
    }

    /**
     * Add a raw OR WHERE clause. Use ? placeholders for safe value binding.
     *
     *   orWhereRaw('YEAR(created_at) = ?', [2024])
     */
    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        $this->builder->orWhere($this->bindRaw($sql, $bindings), null, false);

        return $this;
    }

    /**
     * Constrain results to rows where $column matches one of the given values.
     *
     *   whereIn('status', ['active', 'pending'])
     */
    public function whereIn(string $column, array $values): static
    {
        $this->builder->whereIn($column, $values);

        return $this;
    }

    /**
     * Add an OR version of whereIn().
     *
     *   orWhereIn('role', ['admin', 'editor'])
     */
    public function orWhereIn(string $column, array $values): static
    {
        $this->builder->orWhereIn($column, $values);

        return $this;
    }

    /**
     * Exclude rows where $column matches any of the given values.
     *
     *   whereNotIn('status', ['deleted', 'banned'])
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->builder->whereNotIn($column, $values);

        return $this;
    }

    /**
     * Add an OR version of whereNotIn().
     *
     *   orWhereNotIn('type', ['hidden', 'draft'])
     */
    public function orWhereNotIn(string $column, array $values): static
    {
        $this->builder->orWhereNotIn($column, $values);

        return $this;
    }

    /**
     * Constrain results to rows where $column falls within the given range (inclusive).
     *
     *   whereBetween('age', [18, 65])
     */
    public function whereBetween(string $column, array $values): static
    {
        $this->builder->where("{$column} >=", $values[0])->where("{$column} <=", $values[1]);

        return $this;
    }

    /**
     * Exclude rows where $column falls within the given range.
     *
     *   whereNotBetween('price', [10, 50])
     */
    public function whereNotBetween(string $column, array $values): static
    {
        $this->builder->groupStart()->where("{$column} <", $values[0])->orWhere("{$column} >", $values[1])->groupEnd();

        return $this;
    }

    /**
     * Add an OR version of whereBetween().
     *
     *   orWhereBetween('score', [80, 100])
     */
    public function orWhereBetween(string $column, array $values): static
    {
        $this->builder->orGroupStart()->where("{$column} >=", $values[0])->where("{$column} <=", $values[1])->groupEnd();

        return $this;
    }

    /**
     * Add an OR version of whereNotBetween().
     *
     *   orWhereNotBetween('rank', [1, 10])
     */
    public function orWhereNotBetween(string $column, array $values): static
    {
        $this->builder->orGroupStart()->where("{$column} <", $values[0])->orWhere("{$column} >", $values[1])->groupEnd();

        return $this;
    }

    /**
     * Constrain results to rows where $column falls within the range defined
     * by two other columns. Useful for comparing against stored intervals.
     *
     *   whereBetweenColumns('weight', ['min_weight', 'max_weight']) => WHERE weight >= min_weight AND weight <= max_weight
     */
    public function whereBetweenColumns(string $column, array $columns): static
    {
        $this->builder
            ->where("{$column} >= {$columns[0]}", null, false)
            ->where("{$column} <= {$columns[1]}", null, false);

        return $this;
    }

    /**
     * Exclude rows where $column falls within the range defined by two other columns.
     *
     *   whereNotBetweenColumns('weight', ['min_weight', 'max_weight']) => WHERE weight < min_weight OR weight > max_weight
     */
    public function whereNotBetweenColumns(string $column, array $columns): static
    {
        $this->builder
            ->groupStart()
            ->where("{$column} < {$columns[0]}", null, false)
            ->orWhere("{$column} > {$columns[1]}", null, false)
            ->groupEnd();

        return $this;
    }

    /**
     * Constrain results to rows where a scalar value falls between two columns.
     * The inverse of whereBetweenColumns — the value is the fixed point.
     *
     *   whereValueBetween(100, ['min_price', 'max_price']) => WHERE min_price <= 100 AND max_price >= 100
     */
    public function whereValueBetween(mixed $value, array $columns): static
    {
        $escaped = $this->escapeValue($value);

        $this->builder
            ->where("{$columns[0]} <= {$escaped}", null, false)
            ->where("{$columns[1]} >= {$escaped}", null, false);

        return $this;
    }

    /**
     * Exclude rows where a scalar value falls between two columns.
     *
     *   whereValueNotBetween(100, ['min_price', 'max_price']) => WHERE min_price > 100 OR max_price < 100
     */
    public function whereValueNotBetween(mixed $value, array $columns): static
    {
        $escaped = $this->escapeValue($value);

        $this->builder
            ->groupStart()
            ->where("{$columns[0]} > {$escaped}", null, false)
            ->orWhere("{$columns[1]} < {$escaped}", null, false)
            ->groupEnd();

        return $this;
    }

    /**
     * Constrain results to rows where $column is NULL.
     * Accepts a single column name or an array of names.
     *
     *   whereNull('deleted_at')
     *   whereNull(['deleted_at', 'archived_at'])
     *
     * @param string|array<string> $columns
     */
    public function whereNull(string|array $columns): static
    {
        return $this->applyNullCheck('where', true, $columns);
    }

    /**
     * Constrain results to rows where $column is not NULL.
     *
     *   whereNotNull('email')
     *
     * @param string|array<string> $columns
     */
    public function whereNotNull(string|array $columns): static
    {
        return $this->applyNullCheck('where', false, $columns);
    }

    /**
     * Add an OR version of whereNull().
     *
     * @param string|array<string> $columns
     */
    public function orWhereNull(string|array $columns): static
    {
        return $this->applyNullCheck('orWhere', true, $columns);
    }

    /**
     * Add an OR version of whereNotNull().
     *
     * @param string|array<string> $columns
     */
    public function orWhereNotNull(string|array $columns): static
    {
        return $this->applyNullCheck('orWhere', false, $columns);
    }

    /**
     * Compare two columns against each other in a WHERE clause.
     * Accepts a single pair or an array of pairs.
     *
     *   whereColumn('created_at', 'updated_at')           => WHERE created_at = updated_at
     *   whereColumn('balance', '>', 'minimum_balance')    => WHERE balance > minimum_balance
     *   whereColumn([['first_name', 'last_name'], ['updated_at', '>', 'created_at']])
     */
    public function whereColumn(string|array $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->applyColumnCompare('where', $first, $operator, $second);
    }

    /**
     * Add an OR version of whereColumn().
     *
     *   orWhereColumn('shipped_at', 'delivered_at')
     */
    public function orWhereColumn(string|array $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->applyColumnCompare('orWhere', $first, $operator, $second);
    }

    /**
     * Require that at least one column in $columns matches the given value (OR logic).
     *
     *   whereAny(['name', 'email', 'username'], 'like', '%john%') => WHERE (name LIKE '%john%' OR email LIKE '%john%' OR username LIKE '%john%')
     */
    public function whereAny(array $columns, string $operator, mixed $value): static
    {
        $hasOp = $operator !== '=';

        $this->builder->groupStart();

        $first = true;

        foreach ($columns as $column) {
            $clause = $hasOp ? "{$column} {$operator}" : $column;

            if ($first) {
                $this->builder->where($clause, $value);
                $first = false;
            } else {
                $this->builder->orWhere($clause, $value);
            }
        }

        $this->builder->groupEnd();

        return $this;
    }

    /**
     * Require that every column in $columns matches the given value (AND logic).
     *
     *   whereAll(['price', 'sale_price'], '>', 0) => WHERE (price > 0 AND sale_price > 0)
     */
    public function whereAll(array $columns, string $operator, mixed $value): static
    {
        $this->builder->groupStart();

        $hasOp = $operator !== '=';

        foreach ($columns as $column) {
            $this->builder->where($hasOp ? "{$column} {$operator}" : $column, $value);
        }

        $this->builder->groupEnd();

        return $this;
    }

    /**
     * Require that none of the columns in $columns match the given value.
     * This is the logical complement of whereAny().
     *
     *   whereNone(['name', 'email', 'bio'], 'like', '%spam%') => WHERE NOT (name LIKE '%spam%' OR email LIKE '%spam%' OR bio LIKE '%spam%')
     */
    public function whereNone(array $columns, string $operator, mixed $value): static
    {
        $hasOperator = $operator !== '=';

        $this->builder->notGroupStart();

        $first = true;

        foreach ($columns as $column) {
            $clause = $hasOperator ? "{$column} {$operator}" : $column;

            if ($first) {
                $this->builder->where($clause, $value);
                $first = false;
            } else {
                $this->builder->orWhere($clause, $value);
            }
        }

        $this->builder->groupEnd();

        return $this;
    }

    /**
     * Add a WHERE EXISTS condition using a subquery.
     * The callable receives the database connection and must return a BaseBuilder.
     *
     *   whereExists(fn($db) => $db->table('orders')
     *       ->select('1')
     *       ->where('orders.user_id = users.id', null, false))
     */
    public function whereExists(BaseBuilder|callable $query): static
    {
        $this->builder->where('EXISTS (' . $this->resolveSubquery($query) . ')', null, false);

        return $this;
    }

    /**
     * Add an OR WHERE EXISTS condition.
     *
     *   orWhereExists(fn($db) => $db->table('subscriptions')->where('user_id = users.id', null, false))
     */
    public function orWhereExists(BaseBuilder|callable $query): static
    {
        $this->builder->orWhere('EXISTS (' . $this->resolveSubquery($query) . ')', null, false);

        return $this;
    }

    /**
     * Add a WHERE NOT EXISTS condition.
     *
     *   whereNotExists(fn($db) => $db->table('bans')->where('user_id = users.id', null, false))
     */
    public function whereNotExists(BaseBuilder|callable $query): static
    {
        $this->builder->where('NOT EXISTS (' . $this->resolveSubquery($query) . ')', null, false);

        return $this;
    }

    /**
     * Add an OR WHERE NOT EXISTS condition.
     *
     *   orWhereNotExists(fn($db) => $db->table('bans')->where('user_id = users.id', null, false))
     */
    public function orWhereNotExists(BaseBuilder|callable $query): static
    {
        $this->builder->orWhere('NOT EXISTS (' . $this->resolveSubquery($query) . ')', null, false);

        return $this;
    }

    /**
     * Open an AND group. Everything added until groupEnd() is wrapped in parentheses
     * and joined to the preceding conditions with AND.
     */
    public function groupStart(): static
    {
        $this->builder->groupStart();

        return $this;
    }

    /**
     * Open an OR group. Everything added until groupEnd() is wrapped in parentheses
     * and joined to the preceding conditions with OR.
     */
    public function orGroupStart(): static
    {
        $this->builder->orGroupStart();

        return $this;
    }

    /**
     * Open an AND NOT group. The contents are negated and joined to preceding conditions with AND.
     */
    public function notGroupStart(): static
    {
        $this->builder->notGroupStart();

        return $this;
    }

    /**
     * Open an OR NOT group. The contents are negated and joined to preceding conditions with OR.
     */
    public function orNotGroupStart(): static
    {
        $this->builder->orNotGroupStart();

        return $this;
    }

    /**
     * Close the group opened by the most recent groupStart() call.
     */
    public function groupEnd(): static
    {
        $this->builder->groupEnd();

        return $this;
    }

    /**
     * Conditionally apply query constraints without breaking the fluent chain.
     * The callback receives the query builder and the condition value.
     * An optional second callback serves as the else branch.
     *
     *   when($request->has('search'), fn($q, $v) => $q->whereLike('name', $request->search))
     *   when($isAdmin, fn($q) => $q->where('active', 1), fn($q) => $q->where('public', 1))
     */
    public function when(mixed $condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default !== null) {
            $default($this, $condition);
        }

        return $this;
    }

    /**
     * Pass the query builder to a callback, then return the builder unchanged.
     * Useful for applying a scope or logging mid-chain without breaking fluency.
     *
     *   $model->where('active', 1)->tap(fn($q) => log($q->builder()->getCompiledSelect(false)))->findAll()
     */
    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * Pass the query builder to a callback and return whatever the callback returns.
     * Useful for extracting a derived value at the end of a chain.
     *
     *   $count = $model->where('active', 1)->pipe(fn($q) => $q->countAllResults())
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    // Filter by individual date parts extracted from a datetime column.
    // Every method accepts a formatted string, a Unix timestamp (int),
    // or a DateTimeInterface object as the comparison value.

    /**
     * Filter by the date portion of a datetime column (YYYY-MM-DD).
     *
     *   whereDate('created_at', '2024-01-01')
     *   whereDate('created_at', 1704067200)        // Unix timestamp
     *   whereDate('created_at', '>=', '2024-01-01')
     */
    public function whereDate(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->whereDatePart('DATE', $column, $operator, $value);
    }

    /**
     * Filter by the month number of a datetime column (1–12).
     *
     *   whereMonth('created_at', 12)               // December
     *   whereMonth('created_at', new DateTime('2024-12-01'))
     *   whereMonth('created_at', '>=', 6)
     */
    public function whereMonth(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->whereDatePart('MONTH', $column, $operator, $value);
    }

    /**
     * Filter by the day of the month of a datetime column (1–31).
     *
     *   whereDay('created_at', 1)
     *   whereDay('published_at', '>=', 15)
     */
    public function whereDay(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->whereDatePart('DAY', $column, $operator, $value);
    }

    /**
     * Filter by the year of a datetime column.
     *
     *   whereYear('created_at', 2024)
     *   whereYear('created_at', '>=', 2020)
     *   whereYear('created_at', new DateTime('2024-01-01'))
     */
    public function whereYear(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->whereDatePart('YEAR', $column, $operator, $value);
    }

    /**
     * Filter by the time portion of a datetime column (HH:MM:SS).
     *
     *   whereTime('published_at', '09:00:00')
     *   whereTime('published_at', '>=', new DateTime('09:00:00'))
     */
    public function whereTime(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->whereDatePart('TIME', $column, $operator, $value);
    }

    /**
     * Constrain results to rows where $column is in the past (before the current datetime).
     *
     *   wherePast('published_at') => WHERE published_at < '2024-01-15 14:30:00'
     */
    public function wherePast(string $column): static
    {
        $this->builder->where("{$column} <", $this->columnNow($column));

        return $this;
    }

    /**
     * Constrain results to rows where $column is in the future (after the current datetime).
     *
     *   whereFuture('expires_at') => WHERE expires_at > '2024-01-15 14:30:00'
     */
    public function whereFuture(string $column): static
    {
        $this->builder->where("{$column} >", $this->columnNow($column));

        return $this;
    }

    /**
     * Constrain results to rows where $column is now or in the past.
     *
     *   whereNowOrPast('published_at')
     */
    public function whereNowOrPast(string $column): static
    {
        $this->builder->where("{$column} <=", $this->columnNow($column));

        return $this;
    }

    /**
     * Constrain results to rows where $column is now or in the future.
     *
     *   whereNowOrFuture('starts_at')
     */
    public function whereNowOrFuture(string $column): static
    {
        $this->builder->where("{$column} >=", $this->columnNow($column));

        return $this;
    }

    /**
     * Constrain results to rows where the date portion of $column is today.
     *
     *   whereToday('created_at') => WHERE DATE(created_at) = '2024-01-15'
     */
    public function whereToday(string $column): static
    {
        return $this->whereDate($column, new \DateTime());
    }

    /**
     * Constrain results to rows where the date portion of $column is before today.
     *
     *   whereBeforeToday('created_at') => WHERE DATE(created_at) < '2024-01-15'
     */
    public function whereBeforeToday(string $column): static
    {
        return $this->whereDate($column, '<', new \DateTime());
    }

    /**
     * Constrain results to rows where the date portion of $column is after today.
     *
     *   whereAfterToday('expires_at') => WHERE DATE(expires_at) > '2024-01-15'
     */
    public function whereAfterToday(string $column): static
    {
        return $this->whereDate($column, '>', new \DateTime());
    }

    /**
     * Constrain results to rows where the date portion of $column is today or earlier.
     *
     *   whereTodayOrBefore('published_at')
     */
    public function whereTodayOrBefore(string $column): static
    {
        return $this->whereDate($column, '<=', new \DateTime());
    }

    /**
     * Constrain results to rows where the date portion of $column is today or later.
     *
     *   whereTodayOrAfter('starts_at')
     */
    public function whereTodayOrAfter(string $column): static
    {
        return $this->whereDate($column, '>=', new \DateTime());
    }

    /**
     * Add a WHERE LIKE condition, searching for the value anywhere in the column.
     * Case-insensitive by default.
     *
     *   whereLike('name', 'john')
     *   whereLike('name', 'John', caseSensitive: true)
     */
    public function whereLike(string $column, string $value, bool $caseSensitive = false): static
    {
        $this->builder->like($column, $value, 'both', null, !$caseSensitive);

        return $this;
    }

    /**
     * Add an OR WHERE LIKE condition.
     *
     *   orWhereLike('email', '@gmail')
     */
    public function orWhereLike(string $column, string $value, bool $caseSensitive = false): static
    {
        $this->builder->orLike($column, $value, 'both', null, !$caseSensitive);

        return $this;
    }

    /**
     * Add a WHERE NOT LIKE condition.
     *
     *   whereNotLike('name', 'test')
     */
    public function whereNotLike(string $column, string $value, bool $caseSensitive = false): static
    {
        $this->builder->notLike($column, $value, 'both', null, !$caseSensitive);

        return $this;
    }

    /**
     * Add an OR WHERE NOT LIKE condition.
     *
     *   orWhereNotLike('email', '@spam')
     */
    public function orWhereNotLike(string $column, string $value, bool $caseSensitive = false): static
    {
        $this->builder->orNotLike($column, $value, 'both', null, !$caseSensitive);

        return $this;
    }

    /**
     * Sort results by the given column.
     *
     *   orderBy('created_at', 'desc')
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->builder->orderBy($column, $direction);

        return $this;
    }

    /**
     * Sort results by the given column in descending order.
     *
     *   orderByDesc('created_at')
     */
    public function orderByDesc(string $column): static
    {
        $this->builder->orderBy($column, 'desc');

        return $this;
    }

    /**
     * Sort results using a raw SQL expression. Use ? for safe value binding.
     *
     *   orderByRaw('FIELD(status, ?, ?)', ['active', 'pending'])
     */
    public function orderByRaw(string $sql, array $bindings = []): static
    {
        $this->builder->orderBy($this->bindRaw($sql, $bindings), '', false);

        return $this;
    }

    /**
     * Sort results newest-first by the given column (defaults to the model's created field).
     *
     *   latest()               => ORDER BY created_at DESC
     *   latest('published_at') => ORDER BY published_at DESC
     */
    public function latest(?string $column = null): static
    {
        $this->builder->orderBy($column ?? $this->createdField ?? 'created_at', 'desc');

        return $this;
    }

    /**
     * Sort results oldest-first by the given column (defaults to the model's created field).
     *
     *   oldest()               => ORDER BY created_at ASC
     *   oldest('published_at') => ORDER BY published_at ASC
     */
    public function oldest(?string $column = null): static
    {
        $this->builder->orderBy($column ?? $this->createdField ?? 'created_at', 'asc');

        return $this;
    }

    /**
     * Sort results in a random, non-deterministic order.
     */
    public function inRandomOrder(): static
    {
        $this->builder->orderBy('', 'RANDOM');

        return $this;
    }

    /**
     * Add GROUP BY columns. Accepts individual strings or arrays.
     *
     *   groupBy('city')
     *   groupBy('city', 'status')
     *   groupBy(['city', 'status'])
     */
    public function groupBy(string|array ...$groups): static
    {
        $flat = [];

        foreach ($groups as $g) {
            if (is_array($g)) {
                array_push($flat, ...$g);
            } else {
                $flat[] = $g;
            }
        }

        $this->builder->groupBy(implode(', ', $flat));

        return $this;
    }

    /**
     * Add a raw GROUP BY expression.
     *
     *   groupByRaw('DATE(created_at)')
     */
    public function groupByRaw(string $sql): static
    {
        $this->builder->groupBy($sql, false);

        return $this;
    }

    /**
     * Filter aggregate results with a HAVING condition.
     *
     *   having('total', 100)       => HAVING total = 100
     *   having('total', '>', 100)  => HAVING total > 100
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->applyHaving('having', $column, $operator, $value);
    }

    /**
     * Add an OR HAVING condition.
     *
     *   orHaving('total', '<', 10)
     */
    public function orHaving(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->applyHaving('orHaving', $column, $operator, $value);
    }

    /**
     * Add a raw HAVING condition. Use ? for safe value binding.
     *
     *   havingRaw('SUM(amount) > ?', [1000])
     */
    public function havingRaw(string $sql, array $bindings = []): static
    {
        $this->builder->having($this->bindRaw($sql, $bindings), null, false);

        return $this;
    }

    /**
     * Add a raw OR HAVING condition. Use ? for safe value binding.
     *
     *   orHavingRaw('COUNT(id) < ?', [5])
     */
    public function orHavingRaw(string $sql, array $bindings = []): static
    {
        $this->builder->orHaving($this->bindRaw($sql, $bindings), null, false);

        return $this;
    }

    /**
     * Filter aggregate results to rows where $column falls within the given range.
     *
     *   havingBetween('total', [100, 500])
     */
    public function havingBetween(string $column, array $values): static
    {
        $this->builder->having("{$column} >=", $values[0])->having("{$column} <=", $values[1]);

        return $this;
    }

    /**
     * Limit the number of rows returned.
     *
     *   limit(10)
     */
    public function limit(int $value): static
    {
        $this->builder->limit($value);

        return $this;
    }

    /**
     * Shorthand alias for limit().
     */
    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /**
     * Skip the given number of rows before returning results.
     *
     *   offset(20)
     */
    public function offset(int $value): static
    {
        $this->builder->offset($value);

        return $this;
    }

    /**
     * Shorthand alias for offset().
     */
    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    /**
     * Combine the results of another query with UNION or UNION ALL.
     * UNION removes duplicates by default; pass $all = true to preserve them.
     *
     *   union($otherBuilder)
     *   union($otherBuilder, all: true)
     */
    public function union(BaseBuilder $query, bool $all = false): static
    {
        $all ? $this->builder->unionAll($query) : $this->builder->union($query);

        return $this;
    }

    /**
     * Combine the results of another query with UNION ALL, preserving duplicates.
     *
     *   unionAll($otherBuilder)
     */
    public function unionAll(BaseBuilder $query): static
    {
        $this->builder->unionAll($query);

        return $this;
    }

    // CI4 has no native query-builder locking API, so both clauses are appended
    // via whereRaw. Both modes work on MySQL / MariaDB; for strict requirements
    // use a raw $db->query() call instead.

    /**
     * Acquire a shared read lock on the selected rows (LOCK IN SHARE MODE).
     * Other sessions may still read, but any write will block until the transaction ends.
     */
    public function sharedLock(): static
    {
        return $this->whereRaw('1=1 LOCK IN SHARE MODE');
    }

    /**
     * Acquire an exclusive write lock on the selected rows (FOR UPDATE).
     * Both reads and writes from other transactions will block until this one completes.
     */
    public function lockForUpdate(): static
    {
        return $this->whereRaw('1=1 FOR UPDATE');
    }

    /**
     * Atomically increment a numeric column by the given amount.
     * Always scope with a where() call first to avoid updating every row.
     *
     *   where('id', 1)->increment('views')
     *   where('id', 1)->increment('points', 5)
     */
    public function increment(string $column, int $value = 1): bool
    {
        return $this->builder->increment($column, $value);
    }

    /**
     * Atomically decrement a numeric column by the given amount.
     * Always scope with a where() call first to avoid updating every row.
     *
     *   where('id', 1)->decrement('stock')
     *   where('id', 1)->decrement('credits', 10)
     */
    public function decrement(string $column, int $value = 1): bool
    {
        return $this->builder->decrement($column, $value);
    }

    public function countAllResults(bool $reset = true): int
    {
        return (int) $this->builder->countAllResults($reset);
    }

    /**
     * Return true if at least one row matches the current query conditions.
     *
     *   $model->where('email', $email)->exists()
     */
    public function exists(): bool
    {
        return $this->countAllResults() > 0;
    }

    /**
     * Return true if no rows match the current query conditions.
     *
     *   $model->where('role', 'admin')->doesntExist()
     */
    public function doesntExist(): bool
    {
        return $this->countAllResults() === 0;
    }

    /**
     * Add a WHERE condition on a related table's column.
     * The relation is LEFT JOINed automatically the first time it is referenced.
     *
     *   whereRelation('author', 'active', 1)
     *   whereRelation('author', 'created_at', '>=', '2024-01-01')
     *
     * For meta (EAV) relations $column is the meta key; the condition targets
     * that key's value column:
     *   whereRelation('meta', 'views', '>', 100)
     */
    public function whereRelation(string $relation, string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->applyWhere('where', $this->resolveRelationColumn($relation, $column), $operator, $value);
    }

    /**
     * Add an OR WHERE condition on a related table's column.
     *
     *   orWhereRelation('category', 'slug', 'news')
     */
    public function orWhereRelation(string $relation, string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->applyWhere('orWhere', $this->resolveRelationColumn($relation, $column), $operator, $value);
    }

    /**
     * Sort results by a column on a related table.
     * The relation is LEFT JOINed automatically the first time it is referenced.
     *
     *   orderByRelation('author', 'name')
     *   orderByRelation('author', 'name', 'desc')
     *
     * For meta (EAV) relations $column is the meta key:
     *   orderByRelation('meta', 'views', 'desc')
     */
    public function orderByRelation(string $relation, string $column, string $direction = 'asc'): static
    {
        $this->builder->orderBy($this->resolveRelationColumn($relation, $column), $direction);

        return $this;
    }

    /**
     * Open a CI4 WHERE group, apply conditions inside it, then close the group.
     * Used internally by where(callable), whereNot(), orWhereNot(), and their variants.
     */
    protected function applyGroupedWhere(string $open, string $method, string|array|callable $column, mixed $operator, mixed $value): static
    {
        $this->builder->{$open}();
        is_callable($column) ? $column($this) : $this->applyWhere($method, $column, $operator, $value);
        $this->builder->groupEnd();

        return $this;
    }

    /**
     * Dispatch a single WHERE condition to the CI4 builder.
     *
     * Handles three call forms:
     *   array of conditions  ['col' => val] or [['col', 'op', val], …]
     *   2-arg shorthand      ('col', val)       => col = val
     *   3-arg explicit       ('col', 'op', val)
     */
    protected function applyWhere(string $method, string|array $column, mixed $operator, mixed $value): static
    {
        if (is_array($column)) {
            foreach ($column as $key => $condition) {
                if (is_array($condition)) {
                    if (isset($condition[2])) {
                        $this->builder->{$method}($condition[1] !== '=' ? "{$condition[0]} {$condition[1]}" : $condition[0], $condition[2]);
                    } else {
                        $this->builder->{$method}($condition[0], $condition[1]);
                    }
                } else {
                    $this->builder->{$method}($key, $condition);
                }
            }

            return $this;
        }

        // Normalize 2-arg shorthand: where('col', 'val') => operator = null, value = 'val'
        if ($value === null && $operator !== null && (!is_string($operator) || !isset($this->operators[strtolower($operator)]))) {
            $value    = $operator;
            $operator = null;
        }

        $this->builder->{$method}($operator !== null ? "{$column} {$operator}" : $column, $value);

        return $this;
    }

    /**
     * Append IS NULL or IS NOT NULL conditions for one or more columns.
     */
    protected function applyNullCheck(string $method, bool $null, string|array $columns): static
    {
        $clause = $null ? 'IS NULL' : 'IS NOT NULL';

        if (is_string($columns)) {
            $this->builder->{$method}("{$columns} {$clause}", null, false);

            return $this;
        }

        foreach ($columns as $column) {
            $this->builder->{$method}("{$column} {$clause}", null, false);
        }

        return $this;
    }

    /**
     * Append a column-vs-column comparison clause.
     * Handles both the scalar (3-arg) and array-of-pairs call forms.
     */
    protected function applyColumnCompare(string $method, string|array $first, ?string $operator, ?string $second): static
    {
        if (is_array($first)) {
            foreach ($first as $args) {
                if (isset($args[2])) {
                    $this->builder->{$method}("{$args[0]} {$args[1]} {$args[2]}", null, false);
                } else {
                    $this->builder->{$method}("{$args[0]} = {$args[1]}", null, false);
                }
            }

            return $this;
        }

        $op  = $second === null ? '=' : $operator;
        $col = $second === null ? $operator : $second;
        $this->builder->{$method}("{$first} {$op} {$col}", null, false);

        return $this;
    }

    /**
     * Append a HAVING condition, normalizing the 2-arg shorthand where the operator is omitted.
     */
    protected function applyHaving(string $method, string $column, mixed $operator, mixed $value): static
    {
        if ($value === null and $operator !== null and (!is_string($operator) or !isset($this->operators[strtolower($operator)]))) {
            $value    = $operator;
            $operator = '=';
        }

        $this->builder->{$method}($operator !== '=' ? "{$column} {$operator}" : $column, $value);

        return $this;
    }

    /**
     * Append a WHERE FN(column) OP value clause for date-part filtering.
     * Normalizes the 2-arg shorthand and converts timestamps / DateTimeInterface
     * values to the string or integer format expected by the SQL function.
     * Unix-timestamp columns (no date format) are wrapped with FROM_UNIXTIME() so that
     * date-part extraction works correctly regardless of storage type.
     *
     *   whereDatePart('DATE', 'created_at', …) =>  WHERE DATE(created_at) …
     *   whereDatePart('DATE', 'created_at', …) =>  WHERE DATE(FROM_UNIXTIME(created_at)) …  (timestamp)
     */
    protected function whereDatePart(string $fn, string $column, mixed $operator, mixed $value): static
    {
        if ($value === null and $operator !== null and (!is_string($operator) or !isset($this->operators[strtolower($operator)]))) {
            $value    = $operator;
            $operator = '=';
        }

        if (isset($this->allowedFields[$column]) and $this->fields->getDateFormat($column) === null) {
            $expr = "{$fn}(FROM_UNIXTIME({$column}))";
        } else {
            $expr = "{$fn}({$column})";
        }

        $this->builder->where("{$expr} {$operator}", $this->normalizeDateValue($fn, $value));

        return $this;
    }

    /**
     * Return the current moment in the storage format appropriate for the given column.
     * Uses getDateFormat() to resolve the column's storage format directly — date/datetime
     * columns return a formatted string, timestamp columns return a Unix integer.
     * Falls back to a standard datetime string for unknown fields.
     */
    protected function columnNow(string $column): int|string
    {
        if (isset($this->allowedFields[$column])) {
            $format = $this->fields->getDateFormat($column);

            return $format !== null ? date($format) : time();
        }

        return date('Y-m-d H:i:s');
    }

    /**
     * Convert a date value to the format expected by a SQL date-part function.
     * Strings are returned as-is. Integers are treated as Unix timestamps.
     * DateTimeInterface objects are formatted appropriately for each function.
     */
    protected function normalizeDateValue(string $fn, mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return match ($fn) {
                'DATE' => $value->format('Y-m-d'),
                'MONTH' => (int) $value->format('n'),
                'DAY' => (int) $value->format('j'),
                'YEAR' => (int) $value->format('Y'),
                'TIME' => $value->format('H:i:s'),
                default => $value->format('Y-m-d H:i:s'),
            };
        }

        if (is_int($value)) {
            return match ($fn) {
                'DATE' => date('Y-m-d', $value),
                'MONTH' => (int) date('n', $value),
                'DAY' => (int) date('j', $value),
                'YEAR' => (int) date('Y', $value),
                'TIME' => date('H:i:s', $value),
                default => $value,
            };
        }

        return $value;
    }

    /**
     * Replace ? placeholders in a raw SQL string with properly escaped values.
     * Integers and floats are inserted as-is; strings are escaped via the DB connection.
     */
    protected function bindRaw(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        foreach ($bindings as $binding) {
            $escaped = is_numeric($binding) ? (string) $binding : $this->db->escape($binding);
            $sql     = preg_replace('/\?/', $escaped, $sql, 1);
        }

        return $sql;
    }

    /**
     * Escape a scalar value for use in a raw SQL fragment.
     * Integers and floats are cast to string; everything else is escaped via the DB connection.
     */
    protected function escapeValue(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : $this->db->escape($value);
    }

    /**
     * Compile a subquery from either a BaseBuilder or a callable.
     * The callable receives the database connection and must return a BaseBuilder.
     *
     *   resolveSubquery(fn($db) => $db->table('orders')->select('1')->where('user_id = users.id', null, false))
     */
    protected function resolveSubquery(BaseBuilder|callable $query): string
    {
        if (is_callable($query)) {
            $query = $query($this->db);
        }

        return $query->getCompiledSelect();
    }

    /**
     * LEFT JOIN the given relation into the current query (once) and return its EntityRelation.
     */
    protected function joinRelation(string $key, string $column = ''): EntityRelation
    {
        if (!isset($this->relations[$key])) {
            throw new EntityException("Relation '{$key}' is not defined.");
        }

        $relation = $this->fields->getRelation($key);
        $cacheKey = $relation->getType() === 'meta' ? "{$key}:{$column}" : $key;

        if (!isset($this->joinedRelations[$cacheKey])) {
            $relation->join($this->builder, $this->table, $this->alias, $this->db, $column);
            $this->joinedRelations[$cacheKey] = true;
        }

        return $relation;
    }

    /**
     * Join the relation (if not already joined) and resolve the fully-qualified column expression.
     *
     *   resolveRelationColumn('author', 'name')  => 'authors.name'
     *   resolveRelationColumn('meta', 'views')   => 'm_meta_views.meta_value'
     */
    protected function resolveRelationColumn(string $key, string $column): string
    {
        $relation = $this->joinRelation($key, $column);

        if ($relation->getType() === 'meta') {
            $valueColumn = $relation->getConfig()['value_column'] ?? 'meta_value';

            return "m_{$key}_{$column}.{$valueColumn}";
        }

        return $relation->getTable() . '.' . $column;
    }
}
