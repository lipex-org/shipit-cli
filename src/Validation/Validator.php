<?php

declare(strict_types=1);

namespace ShipIt\Validation;

use ShipIt\Contracts\ValidationRuleInterface;
use ShipIt\TerminalUI;

class Validator
{
    /** @var ValidationRuleInterface[] */
    private array $rules = [];
    private TerminalUI $ui;

    public function __construct(TerminalUI $ui)
    {
        $this->ui = $ui;
    }

    public function addRule(ValidationRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    /**
     * Run all registered validation rules.
     * 
     * @return array Returns an array of results.
     */
    public function validate(array $config, string $rootDir): array
    {
        $results = [];
        foreach ($this->rules as $rule) {
            $result = $rule->validate($config, $rootDir);
            if ($result) {
                $results[] = array_merge(['rule' => $rule->getName()], $result);
            }
        }
        return $results;
    }

    /**
     * Display validation results in the UI.
     * 
     * @return bool True if there are no errors, false otherwise.
     */
    public function displayResults(array $results, bool $verbose = true): bool
    {
        $hasError = false;
        $tableData = [];

        foreach ($results as $res) {
            $status = strtoupper($res['status']);
            if ($status === 'ERROR') {
                $hasError = true;
            }

            $tableData[] = [
                $res['rule'],
                $status,
                $res['message'],
                $res['suggestion'] ?? '-'
            ];
        }

        if (empty($results)) {
            if ($verbose) {
                $this->ui->success("Configuration validation passed.");
            }
            return true;
        }

        if ($hasError || $verbose) {
            $this->ui->info("\nConfiguration Validation Results:");
            $this->ui->table(
                ['Rule', 'Status', 'Message', 'Suggestion'],
                $tableData
            );
        }

        return !$hasError;
    }
}
