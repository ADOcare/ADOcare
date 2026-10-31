<?php

namespace App\Http\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Lightweight API query helper to apply filtering, searching, sorting and pagination.
 *
 * Usage:
 *   $results = ApiQuery::apply($request, Model::query(), searchable: ['first_name','last_name'], allowedFilters: ['sex','company_id']);
 *   // $results is either a LengthAwarePaginator (when paginate=true) or a Collection
 */
class ApiQuery
{
    /**
     * Apply query parameters to a Builder.
     *
     * Recognized params:
     * - filter[field]=value or filter[field][op]=value    (simple filters with optional operators) (operators: gt, gte, lt, lte, ne, neq, like, in)
     * - q=search-term          (search across searchable columns)
     * - sort=field,-other      (comma-separated, prefix - for DESC)
     * - per_page=N, page=N
     * - paginate=0|1 (default 1)
     * - limit=N (when paginate=0)
     * - all=1 (disable pagination/limit)
     * - with=relation1,relation2 (eager load relations)
     *
     * You can pass default options via the `$defaults` argument. Supported keys:
     * - `filter` => array of default filters
     * - `q` => default search string
     * - `sort` => default sort string
     * - `per_page` => default per_page when paginating
     * - `limit` => default limit when not paginating
     * - `paginate` => default paginate boolean
     * - `all` => default for returning all records
     * - `with` => default relations to eager load (comma-separated string)
     * - `scope` => default scope parameters (e.g. ['company' => 4])
     *
     * @param  bool  $allowTrashed  Whether deletion flags may bypass SoftDeletes.
     */
    public static function apply(
        Request $request,
        Builder|\Illuminate\Database\Query\Builder $query,
        array|string $searchable = [],
        array|string $allowedFilters = 'all',
        array $defaults = [],
        bool $allowTrashed = true,
    )
    {

        // Validate inputs
        $request->validate([
            'filter' => 'sometimes|array',
            'q' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|max:255',
            'count' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'limit' => 'sometimes|integer|min:1|max:1000',
            'all' => 'sometimes|boolean',
            'paginate' => 'sometimes|in:true,false,1,0',
            'with' => 'sometimes|string|max:255',
            'scope' => 'sometimes|array',
            'only_deleted' => 'sometimes|boolean',
            'with_deleted' => 'sometimes|boolean',
        ]);


        // Apply modular parts
        static::applyWith($request, $query, $defaults);
        static::applyCount($request, $query, $defaults);
        static::applyFilters($request, $query, $allowedFilters, $defaults, $allowTrashed);
        static::applySearch($request, $query, $searchable, $defaults);
        static::applySort($request, $query, $defaults);

        // Pagination (defaults may override)
        $result = static::applyPagination($request, $query, $defaults);

        // store raw SQL for debugging
        $result->sql = $query->toRawSql();
        return $result;
    }


    /**
     * Apply eager loading (with)
     */
    protected static function applyWith(Request $request, Builder $query, array $defaults = []): void
    {
        $with = $request->input('with', $defaults['with'] ?? null);
        if ($with) {
            $relations = array_map('trim', explode(',', $with));
            // When eager-loading we may need to automatically constrain the
            // related query based on the current "scope" (company, branch,
            // etc). The rules are:
            //   * admins see everything (no constraint)
            //   * managers are limited to their company
            //   * nurses (and others) are limited to a branch when a branch
            //     context is provided, otherwise they fall back to company.
            //   * request may also supply an explicit scope array via
            //     `scope[company]=...` or `scope[branch]=...`; such scopes
            //     are honoured provided the user is permitted to ask for
            //     them.

            $scoped = [];
            foreach ($relations as $rel) {
                $scoped[$rel] = function ($q) use ($request) {
                    // $q may be an Eloquent builder or a Relation instance
                    static::applyScopeToRelation($q, $request);
                };
            }
            $query->with($scoped);
        }
    }

    /**
     * Apply relation counts (withCount)
     * Accepts a comma-separated list of relations in the `count` query param.
     * This is a no-op if the provided builder does not support `withCount`.
     */
    protected static function applyCount(Request $request, Builder $query, array $defaults = []): void
    {
        $count = $request->input('count', $defaults['count'] ?? null);
        if (!$count) {
            return;
        }

        $relations = array_map('trim', explode(',', $count));

        // Only apply when the builder supports withCount (Eloquent builder)
        if (method_exists($query, 'withCount')) {
            // we may need to scope the counted relations just like we scope
            // eager loads.  wrap each relation in a closure that applies
            // the appropriate company/branch scope.
            $scoped = [];
            foreach ($relations as $rel) {
                $scoped[$rel] = function ($q) use ($request) {
                    static::applyScopeToRelation($q, $request);
                };
            }
            $query->withCount($scoped);
        }
    }

    /**
     * Apply simple filters.
     *
     * @param  bool  $allowTrashed  Whether deletion flags may bypass SoftDeletes.
     */
    protected static function applyFilters(
        Request $request,
        Builder $query,
        array|string $allowedFilters = 'all',
        array $defaults = [],
        bool $allowTrashed = true,
    ): void
    {

        // handle trashed/deleted filtering globally
        $model = $query->getModel();
        if ($allowTrashed && method_exists($model, 'trashed')) {
            if ($request->boolean('only_deleted')) {
                $query->onlyTrashed();
            } elseif ($request->boolean('with_deleted')) {
                $query->withTrashed();
            }
        }



        $filters = $request->input('filter', $defaults['filter'] ?? []);
        if (!is_array($filters)) {
            return;
        }

        foreach ($filters as $key => $value) {
            if (is_array($allowedFilters) && !in_array($key, $allowedFilters, true)) {
                continue;
            }

            // If value is an array, decide if it's an indexed array (IN) or
            // an associative array of operators (e.g. ['gt' => 5, 'lte' => 10]).
            if (is_array($value)) {
                foreach ($value as $op => $operand) {
                    $op = strtolower(trim((string) $op));
                    switch ($op) {
                        case 'gt':
                            $query->where($key, '>', $operand);
                            break;
                        case 'gte':
                            $query->where($key, '>=', $operand);
                            break;
                        case 'lt':
                            $query->where($key, '<', $operand);
                            break;
                        case 'lte':
                            $query->where($key, '<=', $operand);
                            break;
                        case 'ne':
                        case 'neq':
                            $query->where($key, '!=', $operand);
                            break;
                        case 'like':
                            $query->where($key, 'LIKE', $operand);
                            break;
                        case 'in':
                            if (is_array($operand)) {
                                $query->whereIn($key, $operand);
                            }
                            break;
                        default:
                            // unknown operator — fallback to equality
                            $query->where($key, $operand);
                    }
                }
                continue;
            }

            // Scalar values: allow operator prefix like ">=10" or "< 5"
            if (is_string($value)) {
                if (preg_match('/^(>=|<=|!=|<>|>|<)\s*(.+)$/', $value, $m)) {
                    $op = $m[1];
                    $val = $m[2];
                    $query->where($key, $op, $val);
                    continue;
                }
            }

            // Fallback: exact match
            $query->where($key, $value);
        }
    }

    /**
     * Apply search across searchable columns.
     * Keeps original LIKE/unaccent behavior.
     */
    protected static function applySearch(Request $request, Builder $query, array|string $searchable = [], array $defaults = []): void
    {
        $q = trim((string) $request->input('q', $defaults['q'] ?? ''));
        if ($q === '' || count((array) $searchable) === 0) {
            return;
        }

        $tokens = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens || count($tokens) === 0) {
            $tokens = [$q];
        }

        // Parse searchable into root columns and relation => columns map.
        $rootColumns = [];
        $relationColumns = [];

        foreach ((array) $searchable as $k => $v) {
            if (is_int($k)) {
                $rootColumns[] = $v;
                continue;
            }

            // associative entry: key is relation name, value is array|string
            if (is_array($v)) {
                $relationColumns[$k] = array_merge($relationColumns[$k] ?? [], $v);
            } elseif (is_string($v)) {
                $relationColumns[$k][] = $v;
            }
        }

        // Build search: tokens are ANDed; within a token, columns/relations are ORed
        $query->where(function (Builder $outer) use ($rootColumns, $relationColumns, $tokens, $query) {
            foreach ($tokens as $token) {
                $like = "%{$token}%";

                $outer->where(function (Builder $inner) use ($rootColumns, $relationColumns, $like, $query) {
                    $first = true;
                    $table = $query->getModel()->getTable();

                    // root columns on the main model
                    foreach ($rootColumns as $col) {
                        $expr = "public.immutable_unaccent(lower(cast({$table}.{$col} as text))) LIKE public.immutable_unaccent(lower(?))";
                        if ($first) {
                            $inner->whereRaw($expr, [$like]);
                            $first = false;
                        } else {
                            $inner->orWhereRaw($expr, [$like]);
                        }
                    }

                    // relations
                    foreach ($relationColumns as $relation => $cols) {
                        $inner->orWhereHas($relation, function (Builder $q) use ($cols, $like, $query, $relation) {
                            $resolved = $resolved = $cols;

                            // if '*' is present, resolve related model fillable columns
                            if (in_array('*', $cols, true)) {
                                $model = $query->getModel();
                                if (!method_exists($model, $relation)) {
                                    throw new \Exception("Cannot resolve relation '{$relation}' for searching on model " . get_class($model));
                                }
                                /** @var \Illuminate\Database\Eloquent\Relations\Relation $modelRelation */
                                $modelRelation = $model->$relation();
                                $related = $modelRelation->getRelated();
                                dd($related, $related->getFillable());
                                // $resolved = $related->
                            }

                            // if we couldn't resolve any columns, do nothing
                            if (empty($resolved)) {
                                return;
                            }

                            $table = $q->getModel()->getTable();
                            foreach ($resolved as $i => $col) {
                                $expr = "public.immutable_unaccent(lower(cast({$table}.{$col} as text))) LIKE public.immutable_unaccent(lower(?))";
                                if ($i === 0) {
                                    $q->whereRaw($expr, [$like]);
                                } else {
                                    $q->orWhereRaw($expr, [$like]);
                                }
                            }
                        });
                    }
                });
            }
        });
    }

    /**
     * Apply sorting
     */
    protected static function applySort(Request $request, Builder $query, array $defaults = []): void
    {
        $sort = $request->input('sort', $defaults['sort'] ?? null);
        if (!$sort) {
            return;
        }

        $parts = explode(',', $sort);
        foreach ($parts as $part) {
            $direction = 'asc';
            $field = $part;
            if (str_starts_with($part, '-')) {
                $direction = 'desc';
                $field = substr($part, 1);
            }
            $query->orderBy($field, $direction);
        }
    }

    /**
     * Apply pagination / limit and return results
     */
    protected static function applyPagination(Request $request, Builder $query, array $defaults = [])
    {
        $paginate = $request->boolean('paginate', $defaults['paginate'] ?? true);
        $perPage = (int) $request->input('per_page', $defaults['per_page'] ?? 15);
        $limit = (int) $request->input('limit', $defaults['limit'] ?? -1);
        if ($request->boolean('all', $defaults['all'] ?? false)) {
            $paginate = false;
            $limit = -1;
        }

        if (!$paginate) {
            if ($limit > 0) {
                $query->limit($limit);
            }
            return $query->get();
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Constrain a relation query according to the current user/request
     * scope.  This is invoked from `applyWith` for every relationship that
     * the client asked to be loaded.
     *
     * The scopes are applied in the following order of precedence:
     *
     * 1. explicit `scope` request parameters (`company` or `branch`)
     * 2. role-based defaults (admins = none, managers = company)
     * 3. fall back to branch (from route) or company for other roles
     *
     * Related models must expose the appropriate query scopes
     * (`forCompany`/`forBranch`) for this helper to invoke them; if the
     * method does not exist we simply leave the relation unmodified.
     */
    protected static function applyScopeToRelation($q, Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            return;
        }

        // we may have been handed a Relation; if so we need to operate on its
        // underlying query builder when applying scopes, but we still want to
        // inspect the related model for `scopeFor*` methods.
        if ($q instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
            $related = $q->getRelated();
            $builder = $q->getQuery();
        } else {
            $related = $q->getModel();
            $builder = $q;
        }

        // replace $q with the builder for scope application below
        $q = $builder;

        // explicit parameters take priority
        $scopes = (array) $request->input('scope', []);

        if (isset($scopes['company'])) {
            $companyId = (int) $scopes['company'];
            if ($user->hasGlobalRole('admin') || $user->company_id === $companyId) {
                if (method_exists($related, 'scopeForCompany')) {
                    $q->forCompany($companyId);
                }
                return;
            }
        }

        if (isset($scopes['branch'])) {
            $branchId = (int) $scopes['branch'];
            if (method_exists($related, 'scopeForBranch')) {
                $q->forBranch($branchId);
            }
            return;
        }

        // role-based defaults
        if ($user->hasGlobalRole('admin')) {
            return;
        }

        if ($user->hasGlobalRole('manager')) {
            if (method_exists($related, 'scopeForCompany')) {
                $q->forCompany($user->company_id);
            }
            return;
        }

        // other roles: try branch, then company
        $branch = $request->route('branch') ?? null;
        if ($branch && method_exists($related, 'scopeForBranch')) {
            $q->forBranch($branch->id);
            return;
        }

        if (method_exists($related, 'scopeForCompany') && $user->company_id) {
            $q->forCompany($user->company_id);
        }
    }
}
