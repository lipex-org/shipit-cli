<?php

declare(strict_types=1);

namespace ShipIt;

class TaskRunner
{
    private array $tasks = [];
    private array $preHooks = [];
    private array $postHooks = [];
    private TerminalUI $ui;

    public function __construct(TerminalUI $ui)
    {
        $this->ui = $ui;
    }

    public function addTask(string $name, callable $task): void
    {
        $this->tasks[$name] = $task;
    }

    public function addPreHook(string $taskName, callable $hook): void
    {
        $this->preHooks[$taskName][] = $hook;
    }

    public function addPostHook(string $taskName, callable $hook): void
    {
        $this->postHooks[$taskName][] = $hook;
    }

    public function getTasks(): array
    {
        return $this->tasks;
    }

    public function getPreHooks(): array
    {
        return $this->preHooks;
    }

    public function getPostHooks(): array
    {
        return $this->postHooks;
    }

    public function mergeRunOrder(array $baseOrder, array $rules): array
    {
        $order = $baseOrder;

        if (!empty($rules['prepend'])) {
            $order = array_merge($rules['prepend'], $order);
        }

        if (!empty($rules['before'])) {
            foreach ($rules['before'] as $target => $inserts) {
                $pos = array_search($target, $order, true);
                if ($pos !== false) {
                    array_splice($order, $pos, 0, $inserts);
                }
            }
        }

        if (!empty($rules['after'])) {
            foreach ($rules['after'] as $target => $inserts) {
                $pos = array_search($target, $order, true);
                if ($pos !== false) {
                    array_splice($order, $pos + 1, 0, $inserts);
                }
            }
        }

        if (!empty($rules['append'])) {
            $order = array_merge($order, $rules['append']);
        }

        return array_values(array_unique($order));
    }

    public function run(array $order, array $ignoreList, array $onlyList, bool $ignoreAll, mixed $context = null): void
    {
        foreach ($order as $taskName) {
            if ($this->shouldRun($taskName, $ignoreList, $onlyList, $ignoreAll)) {
                if (!isset($this->tasks[$taskName])) {
                    $this->ui->error("Task '$taskName' not found.");
                    continue;
                }

                if (isset($this->preHooks[$taskName])) {
                    foreach ($this->preHooks[$taskName] as $hook) {
                        $hook($context);
                    }
                }

                $this->tasks[$taskName]($context);

                if (isset($this->postHooks[$taskName])) {
                    foreach ($this->postHooks[$taskName] as $hook) {
                        $hook($context);
                    }
                }
            } else {
                if ($this->ui->isVerbose()) {
                    $this->ui->info("⏭️  Skipped $taskName");
                }
            }
        }
    }

    private function shouldRun(string $cmd, array $ignoreList, array $onlyList, bool $ignoreAll): bool
    {
        if ($ignoreAll)
            return false;
        if (!empty($onlyList))
            return in_array($cmd, $onlyList, true);
        return !in_array($cmd, $ignoreList, true);
    }
}
