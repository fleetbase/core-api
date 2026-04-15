<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;

class CompanySettingsResolver
{
    protected string $companyUuid;
    protected ?string $parentCompanyUuid;

    protected function __construct(string $companyUuid, ?string $parentCompanyUuid)
    {
        $this->companyUuid = $companyUuid;
        $this->parentCompanyUuid = $parentCompanyUuid;
    }

    public static function forCompany(string $companyUuid): self
    {
        $company = Company::where('uuid', $companyUuid)->first();
        $parentUuid = $company?->parent_company_uuid ?: null;

        return new self($companyUuid, $parentUuid);
    }

    /**
     * Resolution order: company override -> parent org value -> default tree -> caller default.
     */
    public function get(string $key, $default = null)
    {
        $sentinel = new \stdClass();

        // 1. Company-specific value wins.
        $ownValue = Setting::lookup($this->companyKey($this->companyUuid, $key), $sentinel);
        if ($ownValue !== $sentinel) {
            return $ownValue;
        }

        // 2. Parent-org value (inheritance, only for clients with a parent).
        if ($this->parentCompanyUuid) {
            $parentValue = Setting::lookup($this->companyKey($this->parentCompanyUuid, $key), $sentinel);
            if ($parentValue !== $sentinel) {
                return $parentValue;
            }
        }

        // 3. Default from the defaults tree.
        $defaultFromTree = data_get(static::defaults(), $key);
        if ($defaultFromTree !== null) {
            return $defaultFromTree;
        }

        // 4. Caller-provided default.
        return $default;
    }

    public function set(string $key, $value): self
    {
        Setting::configure($this->companyKey($this->companyUuid, $key), $value);
        return $this;
    }

    /**
     * Full merged settings tree: defaults <- parent <- own.
     */
    public function all(): array
    {
        $tree = static::defaults();

        if ($this->parentCompanyUuid) {
            $tree = static::mergeDeep($tree, $this->readCompanyTree($this->parentCompanyUuid));
        }

        $tree = static::mergeDeep($tree, $this->readCompanyTree($this->companyUuid));

        return $tree;
    }

    public static function defaults(): array
    {
        return [
            'billing' => [
                'default_payment_terms_days'   => 30,
                'default_billing_frequency'    => 'per_shipment',
                'invoice_number_prefix'        => 'INV',
                'invoice_number_next'          => 1,
                'default_charge_template_uuid' => null,
                'default_currency'             => 'USD',
            ],
            'tendering' => [
                'default_method'           => 'email',
                'default_expiration_hours' => 4,
                'auto_waterfall'           => true,
                'check_call_stale_hours'   => 6,
            ],
            'documents' => [
                'auto_request_pod_on_delivery' => true,
                'pod_due_days'                 => 3,
                'required_documents'           => ['bol', 'pod'],
            ],
            'pay_files' => [
                'default_format'          => 'csv',
                'default_frequency'       => 'weekly',
                'default_day_of_week'     => 1,
                'default_recipients'      => [],
                'default_payment_method'  => 'ach',
            ],
            'fuel' => [
                'auto_update_eia'        => true,
                'manual_override_price'  => null,
                'update_day'             => 'monday',
            ],
            'audit' => [
                'default_tolerance_percent' => 2.0,
                'default_tolerance_amount'  => 50.00,
                'auto_audit_on_receive'     => true,
            ],
        ];
    }

    /**
     * Read every `company.{uuid}.*` setting row for this company and rebuild
     * into a nested array via dot-notation keys.
     */
    protected function readCompanyTree(string $companyUuid): array
    {
        $prefix = "company.{$companyUuid}.";
        $rows = \DB::table('settings')
            ->where('key', 'like', "{$prefix}%")
            ->get(['key', 'value']);

        $tree = [];
        foreach ($rows as $row) {
            $shortKey = substr($row->key, strlen($prefix));
            $decoded = is_string($row->value) ? json_decode($row->value, true) : $row->value;
            data_set($tree, $shortKey, $decoded);
        }

        return $tree;
    }

    protected static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = static::mergeDeep($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    protected function companyKey(string $companyUuid, string $key): string
    {
        return "company.{$companyUuid}.{$key}";
    }
}
