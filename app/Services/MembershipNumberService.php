<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Membership-number generation and approval attributes. Ports the static
 * User.prepareApproveMember() / User.makeMembershipNumber() from wpi-hub
 * models/user.js (organizations come from config/wpi.php).
 */
class MembershipNumberService
{
    /**
     * Attributes to apply when approving a member: number, type, expiration.
     *
     * @return array<string, mixed>
     */
    public function prepareApproveMember(?string $organizationId = null): array
    {
        $orgs = config('wpi.organizations');
        if (empty($orgs)) {
            throw new RuntimeException('Cannot find organizations to generate member number');
        }

        $org = null;
        if (! $organizationId) {
            if (count($orgs) > 1) {
                throw new RuntimeException('No organization ID provided');
            }
            $org = $orgs[0];
        } else {
            foreach ($orgs as $o) {
                if ($o['id'] == $organizationId) {
                    $org = $o;
                    break;
                }
            }
        }
        if ($org === null) {
            throw new RuntimeException('Organization not found');
        }

        $attributes = ['membershipNumber' => $this->makeMembershipNumber($org)];

        if (isset($org['approvalState'])) {
            $attributes['membershipType'] = $org['approvalState'];
        }

        $expiration = Carbon::now();
        foreach (($org['initialMembershipLength'] ?? []) as $unit => $value) {
            $expiration->add("{$value} {$unit}");
        }
        $attributes['membershipExpiration'] = $expiration->format('Y-m-d');

        return $attributes;
    }

    public function makeMembershipNumber(array $org): string
    {
        if (! isset($org['numberFormat'])) {
            throw new RuntimeException('No number format definition for organization');
        }

        $number = '';
        $now = Carbon::now();

        foreach ($org['numberFormat'] as $segment) {
            switch ($segment['type']) {
                case 'string':
                    $number .= $segment['value'];
                    break;

                case 'year':
                    $year = (string) $now->year;
                    if (! empty($segment['length'])) {
                        $len = $segment['length'];
                        if ($len < 4) {
                            $year = substr($year, 4 - $len);
                        } elseif ($len > 4) {
                            $year = str_pad($year, $len, '0', STR_PAD_LEFT);
                        }
                    }
                    $number .= $year;
                    break;

                case 'month':
                    $month = (string) $now->month;
                    if (! empty($segment['length'])) {
                        $len = $segment['length'];
                        if ($len < strlen($month)) {
                            $month = substr($month, strlen($month) - $len);
                        } elseif ($len > strlen($month)) {
                            $month = str_pad($month, $len, '0', STR_PAD_LEFT);
                        }
                    }
                    $number .= $month;
                    break;

                case 'max':
                    $number .= $this->nextMax($number, $segment['length'] ?? 1);
                    break;
            }
        }

        return $number;
    }

    /**
     * Finds the current DB maximum for the prefix and increments it, matching
     * the MySQL CAST(SUBSTRING(...)) ordering in the Node code.
     */
    private function nextMax(string $prefix, int $length): string
    {
        $maxNumber = 1;

        $row = User::query()
            ->where('membershipNumber', 'LIKE', $prefix.'%')
            ->orderByRaw('CAST(SUBSTRING(membershipNumber, '.(strlen($prefix) + 1).') AS UNSIGNED) DESC')
            ->first();

        if ($row !== null) {
            $tail = substr($row->membershipNumber, strlen($prefix));
            if (! is_numeric($tail)) {
                throw new RuntimeException('Membership max found is not valid number');
            }
            $maxNumber = (int) $tail + 1;
        }

        return str_pad((string) $maxNumber, $length, '0', STR_PAD_LEFT);
    }
}
