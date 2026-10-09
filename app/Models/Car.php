<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\CompanyBranchScopes;

class Car extends Model
{
    use HasFactory, CompanyBranchScopes;

    protected $table = 'cars';

    protected $fillable = ['evc', 'vin', 'model', 'owner_name', 'fuel_consumption_l_per_100km', 'company_id', 'user_id'];

    protected $casts = [
        'fuel_consumption_l_per_100km' => 'float',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function documents()
    {
        return $this->hasMany(CarDocument::class);
    }

    public function services()
    {
        return $this->hasMany(CarService::class);
    }
}
