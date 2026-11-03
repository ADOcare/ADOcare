<?php

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = ['position', 'scope'];

    protected $casts = [
        'scope' => RoleScope::class,
    ];

    /**
     * We append a human-readable name to every role so that API clients
     * can display it without needing to map the `position` string
     * themselves.  This is essentially a lightweight mutation/accessor on
     * the model.
     */
    protected $appends = ['name'];

    public function users()
    {
        return $this->hasMany(User::class, 'role_id');
    }

    public function procedures()
    {
        return $this->belongsToMany(Procedure::class, 'procedure_role');
    }

    public function assignUser(User $user)
    {
        $user->role_id = $this->id;
        $user->save();
    }

    public function isBranch(): bool
    {
        return $this->scope === RoleScope::BRANCH;
    }

    public function isCompany(): bool
    {
        return $this->scope === RoleScope::COMPANY;
    }

    public function isGlobal(): bool
    {
        return $this->scope === RoleScope::GLOBAL;
    }

    /**
     * Human-friendly label for the role.  Defaults to a title-cased version
     * of the `position` value, but callers can override if necessary.
     *
     * Example: 'branch_manager' -> 'Branch Manager'
     */
    public function getNameAttribute(): string
    {
        if ($this->position === 'physiotherapist') {
            return 'Fyzioterapeut';
        }

        // translate the position into Slovak; use the `lang` files so
        // translators can override or improve the wording.  fall back to
        // a humanized version if no translation exists.
        $key = 'roles.'.$this->position;
        $translated = __($key);
        if ($translated === $key) {
            // no translation found, so humanize the position string
            $translated = str_replace('_', ' ', $this->position);
            $translated = ucwords($translated);
        }

        return $translated;
    }
}
