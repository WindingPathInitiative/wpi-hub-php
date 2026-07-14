<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Materialized-path hierarchy, ported from wpi-hub models/base.js +
 * models/org-unit.js. Ancestry is encoded in the dot-delimited `parentPath`
 * column (e.g. "1.4.27", ending in the row's own id).
 *
 * OrgUnit uses these methods as-is. Office shares insertWithPath() but
 * overrides getParents()/getChildren() because its legacy semantics differ
 * (its parent set includes self, and its child match is a bare prefix).
 */
trait HasMaterializedPath
{
    /**
     * Ancestor ids: every path segment except the row's own (trailing) id.
     * Mirrors OrgUnit.parents() (split on ".", drop the last segment).
     *
     * @return int[]
     */
    public function parents(): array
    {
        $segments = array_map('intval', explode('.', (string) $this->parentPath));
        array_pop($segments); // drop self

        return $segments;
    }

    /**
     * Ancestors of this node. Mirrors OrgUnit.getParents().
     */
    public function getParents(): Collection
    {
        return static::query()->whereIn('id', $this->parents())->get();
    }

    /**
     * Descendants of this node: parentPath LIKE "<path>.%".
     * Mirrors OrgUnit.getChildren().
     */
    public function getChildren(): Collection
    {
        return static::query()
            ->where('parentPath', 'LIKE', $this->parentPath.'.%')
            ->get();
    }

    /**
     * Ancestors AND descendants, ordered by path. Mirrors OrgUnit.getChain().
     */
    public function getChain(): Collection
    {
        return static::query()
            ->whereIn('id', $this->parents())
            ->orWhere('parentPath', 'LIKE', $this->parentPath.'.%')
            ->orderBy('parentPath', 'asc')
            ->get();
    }

    /**
     * Is this node a child of $node? Mirrors OrgUnit.isChild().
     */
    public function isChild(int|self $node): bool
    {
        $id = is_int($node) ? $node : $node->id;

        return in_array($id, $this->parents(), true);
    }

    /**
     * Is $node below this node? Mirrors OrgUnit.hasChild().
     */
    public function hasChild(int|self $node): bool
    {
        if (is_int($node)) {
            $node = static::query()->findOrFail($node);
        }

        return $node->isChild($this->id);
    }

    /**
     * Insert the row, then rewrite parentPath to append the new id, inside a
     * transaction. Mirrors models/base.js insertWithPath(). Nested calls reuse
     * the surrounding transaction via savepoints.
     */
    public function insertWithPath(): static
    {
        if (! isset($this->attributes['parentPath'])) {
            throw new \RuntimeException('No parent path set');
        }

        return DB::transaction(function () {
            $base = $this->parentPath;
            if (! Str::endsWith($base, '.')) {
                $base .= '.';
            }
            $this->parentPath = $base;
            $this->save();

            $this->parentPath = $base.$this->getKey();
            $this->save();

            return $this;
        });
    }
}
