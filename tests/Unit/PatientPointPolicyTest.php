<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\Role;
use App\Models\User;
use App\Policies\PatientPointPolicy;
use PHPUnit\Framework\TestCase;

class PatientPointPolicyTest extends TestCase
{
    public function test_manager_can_access_point_when_branch_belongs_to_their_company(): void
    {
        $manager = $this->manager(10);
        $point = $this->pointInCompany(55, 10);
        $policy = new PatientPointPolicy;

        self::assertTrue($policy->view($manager, $point));
        self::assertTrue($policy->update($manager, $point));
        self::assertTrue($policy->delete($manager, $point));
    }

    public function test_manager_cannot_access_point_from_another_company(): void
    {
        $manager = $this->manager(10);
        $point = $this->pointInCompany(10, 20);
        $policy = new PatientPointPolicy;

        self::assertFalse($policy->view($manager, $point));
        self::assertFalse($policy->update($manager, $point));
        self::assertFalse($policy->delete($manager, $point));
    }

    private function manager(int $companyId): User
    {
        $user = new User;
        $user->company_id = $companyId;
        $user->setRelation('role', new Role(['position' => 'manager', 'scope' => 'company']));

        return $user;
    }

    private function pointInCompany(int $branchId, int $companyId): PatientPoint
    {
        $point = new PatientPoint(['branch_id' => $branchId]);
        $point->setRelation('branch', new Branch(['company_id' => $companyId]));
        $point->setRelation('patient', new Patient(['branch_id' => $branchId]));

        return $point;
    }
}
